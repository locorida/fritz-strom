<?php
$mqttHost = getenv('MQTT_HOST') ?: 'mqtt-broker';
$mqttPort = (int)(getenv('MQTT_PORT') ?: 1883);
$dataDir  = '/var/www/data';
$interval = (int)(getenv('COLLECT_INTERVAL') ?: 30);

function log_msg(string $msg): void {
    echo date('[Y-m-d H:i:s] ') . $msg . "\n";
}

// ── Minimal MQTT 3.1.1 Protocol ──

function mqtt_encode_length(int $len): string {
    $out = '';
    do {
        $byte = $len % 128;
        $len = intdiv($len, 128);
        if ($len > 0) $byte |= 0x80;
        $out .= chr($byte);
    } while ($len > 0);
    return $out;
}

function mqtt_decode_length($sock): int {
    $mul = 1;
    $val = 0;
    do {
        $ch = fread($sock, 1);
        if ($ch === false || $ch === '') throw new RuntimeException('EOF beim Lesen der Länge');
        $byte = ord($ch);
        $val += ($byte & 0x7F) * $mul;
        $mul *= 128;
    } while ($byte & 0x80);
    return $val;
}

function mqtt_read($sock, int $n): string {
    $buf = '';
    while (strlen($buf) < $n) {
        $chunk = fread($sock, $n - strlen($buf));
        if ($chunk === false || $chunk === '') throw new RuntimeException('EOF beim Lesen');
        $buf .= $chunk;
    }
    return $buf;
}

function mqtt_send_connect($sock, string $clientId): void {
    $var = "\x00\x04MQTT\x04\x02" . pack('n', 60);
    $payload = pack('n', strlen($clientId)) . $clientId;
    $body = $var . $payload;
    fwrite($sock, "\x10" . mqtt_encode_length(strlen($body)) . $body);

    $type = ord(mqtt_read($sock, 1));
    $len = mqtt_decode_length($sock);
    $data = mqtt_read($sock, $len);
    if (($type >> 4) !== 2 || ord($data[1]) !== 0) {
        throw new RuntimeException('CONNACK fehlgeschlagen: Code ' . ord($data[1]));
    }
}

function mqtt_subscribe($sock, string $topic): void {
    static $pid = 0;
    $pid++;
    $var = pack('n', $pid);
    $payload = pack('n', strlen($topic)) . $topic . "\x00";
    $body = $var . $payload;
    fwrite($sock, "\x82" . mqtt_encode_length(strlen($body)) . $body);

    ord(mqtt_read($sock, 1));
    $len = mqtt_decode_length($sock);
    mqtt_read($sock, $len);
}

function mqtt_ping($sock): void {
    fwrite($sock, "\xc0\x00");
}

function mqtt_read_packet($sock): ?array {
    $header = @fread($sock, 1);
    if ($header === false || $header === '') {
        if (feof($sock)) throw new RuntimeException('Verbindung geschlossen');
        return null;
    }

    $byte = ord($header);
    $type = ($byte >> 4) & 0x0F;
    $len = mqtt_decode_length($sock);
    $data = $len > 0 ? mqtt_read($sock, $len) : '';

    if ($type === 3) {
        $topicLen = unpack('n', substr($data, 0, 2))[1];
        $topic = substr($data, 2, $topicLen);
        $offset = 2 + $topicLen;
        $qos = ($byte >> 1) & 0x03;
        if ($qos > 0) $offset += 2;
        return ['type' => 'publish', 'topic' => $topic, 'payload' => substr($data, $offset)];
    }

    return ['type' => $type];
}

// ── Sensor-Verwaltung ──

$state = [];
$lastSnapshot = 0;
$lastStateWrite = 0;

function handleMessage(string $topic, string $payload): void {
    global $state;

    $data = json_decode($payload, true);
    if (!$data || !is_array($data)) return;

    $section = basename($topic);

    if ($section === 'grid') {
        setState('output_power', $data['Output_Power'] ?? null, 'W', 'Output Power');
        setState('grid_voltage', $data['Voltage'] ?? null, 'V', 'Grid Voltage');
        setState('grid_current', $data['Current'] ?? null, 'A', 'Grid Current');
        setState('grid_frequency', $data['Frequency'] ?? null, 'Hz', 'Grid Frequency');
        log_msg("Grid: " . ($data['Output_Power'] ?? '?') . " W");
    } elseif ($section === 'total') {
        setState('daily_generation', $data['Daily_Generation'] ?? null, 'kWh', 'Daily Generation');
        setState('total_generation', $data['Total_Generation'] ?? null, 'kWh', 'Total Generation');
    } elseif ($section === 'input') {
        for ($i = 1; $i <= 4; $i++) {
            $pv = $data["pv$i"] ?? null;
            if (!$pv || !is_array($pv)) continue;
            if (($pv['Power'] ?? 0) == 0 && ($pv['Voltage'] ?? 0) == 0) continue;
            setState("pv{$i}_power", $pv['Power'] ?? null, 'W', "PV$i Power");
            setState("pv{$i}_voltage", $pv['Voltage'] ?? null, 'V', "PV$i Voltage");
            setState("pv{$i}_current", $pv['Current'] ?? null, 'A', "PV$i Current");
            setState("pv{$i}_daily", $pv['Daily_Generation'] ?? null, 'kWh', "PV$i Daily");
            setState("pv{$i}_total", $pv['Total_Generation'] ?? null, 'kWh', "PV$i Total");
        }
    } elseif ($section === 'controller') {
        setState('signal_strength', $data['Signal_Strength'] ?? null, '%', 'Signal Strength');
    }

    computeDailyTotal();
}

function computeDailyTotal(): void {
    global $state;
    $daily = 0;
    $total = 0;
    for ($i = 1; $i <= 4; $i++) {
        $daily += $state["pv{$i}_daily"]['value'] ?? 0;
        $total += $state["pv{$i}_total"]['value'] ?? 0;
    }
    if ($daily > 0) setState('daily_generation', $daily, 'kWh', 'Daily Generation');
    if ($total > 0) setState('total_generation', $total, 'kWh', 'Total Generation');
}

function setState(string $key, $value, string $unit, string $name): void {
    global $state;
    if ($value === null) return;
    $state[$key] = [
        'value' => is_numeric($value) ? (float)$value : $value,
        'unit'  => $unit,
        'name'  => $name,
    ];
}

function writeState(): void {
    global $state, $dataDir;
    if (empty($state)) return;

    $file = "$dataDir/tsun-state.json";
    $tmp = "$file.tmp";
    $data = json_encode(['ts' => time(), 'sensors' => $state], JSON_UNESCAPED_UNICODE);
    file_put_contents($tmp, $data);
    rename($tmp, $file);
}

function writeSnapshot(): void {
    global $state, $dataDir;
    if (empty($state)) return;

    $dir = "$dataDir/tsun";
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    $row = ['ts' => time()];
    foreach ($state as $key => $s) {
        if (is_numeric($s['value'])) $row[$key] = $s['value'];
    }

    $file = "$dir/" . date('Y-m-d') . '.jsonl';
    file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    log_msg("Snapshot: " . (count($row) - 1) . " Sensoren → $file");
}

// ── Hauptschleife ──

log_msg("TSUN-Collector gestartet (MQTT: $mqttHost:$mqttPort, Intervall: {$interval}s)");

while (true) {
    try {
        log_msg("Verbinde zu MQTT-Broker $mqttHost:$mqttPort …");
        $sock = @stream_socket_client("tcp://$mqttHost:$mqttPort", $errno, $errstr, 10);
        if (!$sock) {
            log_msg("Verbindung fehlgeschlagen: $errstr — Retry in 5s");
            sleep(5);
            continue;
        }
        stream_set_timeout($sock, 65);

        mqtt_send_connect($sock, 'fritz-strom-tsun-' . getmypid());
        mqtt_subscribe($sock, 'tsun/#');
        log_msg("MQTT verbunden — warte auf TSUN-Daten (tsun/#) …");

        $lastSnapshot = time();
        $lastStateWrite = time();

        while (true) {
            $packet = mqtt_read_packet($sock);

            if ($packet === null) {
                mqtt_ping($sock);
            } elseif (($packet['type'] ?? '') === 'publish') {
                handleMessage($packet['topic'], $packet['payload']);
            }

            $now = time();

            if ($now - $lastStateWrite >= 5) {
                writeState();
                $lastStateWrite = $now;
            }

            if ($now - $lastSnapshot >= $interval) {
                writeSnapshot();
                $lastSnapshot = $now;
            }
        }
    } catch (Throwable $e) {
        log_msg("Fehler: {$e->getMessage()} — Reconnect in 5s");
        if (isset($sock) && is_resource($sock)) @fclose($sock);
        sleep(5);
    }
}
