<?php
header('Content-Type: application/json; charset=utf-8');

function env(string $key, string $default = ''): string {
    return getenv($key) ?: $default;
}

$host     = env('FRITZ_HOST', 'http://192.168.178.1');
$user     = env('FRITZ_USER');
$password = env('FRITZ_PASSWORD');
$ainMap   = env('FRITZ_AINS');

function fritz_login(string $host, string $user, string $password): string {
    $body = @file_get_contents("$host/login_sid.lua?version=2");
    if ($body === false) {
        http_response_code(502);
        die(json_encode(['error' => "FRITZ!Box nicht erreichbar unter $host"]));
    }
    $xml = simplexml_load_string($body);
    $challenge = (string)$xml->Challenge;

    if (str_starts_with($challenge, '2$')) {
        [, $iter1, $salt1, $iter2, $salt2] = explode('$', $challenge);
        $hash1    = hash_pbkdf2('sha256', $password, hex2bin($salt1), (int)$iter1, 0, true);
        $hash2    = hash_pbkdf2('sha256', $hash1,    hex2bin($salt2), (int)$iter2, 0, true);
        $response = $salt2 . '$' . bin2hex($hash2);
    } else {
        $text     = $challenge . '-' . $password;
        $response = $challenge . '-' . md5(mb_convert_encoding($text, 'UTF-16LE'));
    }

    $url = "$host/login_sid.lua?version=2&username=" . urlencode($user) . "&response=$response";
    $xml = simplexml_load_string(file_get_contents($url));
    $sid = (string)$xml->SID;

    if ($sid === '0000000000000000') {
        http_response_code(401);
        die(json_encode(['error' => 'Login fehlgeschlagen – Benutzer/Passwort/Rechte prüfen']));
    }
    return $sid;
}

function aha(string $host, string $sid, string $cmd, ?string $ain = null): string {
    $url = "$host/webservices/homeautoswitch.lua?switchcmd=$cmd&sid=$sid";
    if ($ain !== null) {
        $url .= '&ain=' . urlencode($ain);
    }
    $result = @file_get_contents($url);
    if ($result === false) {
        http_response_code(502);
        die(json_encode(['error' => "AHA-Kommando '$cmd' fehlgeschlagen"]));
    }
    return $result;
}

function parse_ain_map(string $raw): array {
    $map = [];
    foreach (explode(',', $raw) as $entry) {
        $entry = trim($entry);
        if ($entry === '') continue;
        $parts = explode(':', $entry, 2);
        if (count($parts) === 2) {
            $map[] = ['label' => trim($parts[0]), 'ain' => trim($parts[1])];
        }
    }
    return $map;
}

$action = $_GET['action'] ?? '';

function get_sid(string $host, string $user, string $password): string {
    $cacheFile = '/tmp/fritz_sid.json';
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && (time() - $cached['ts']) < 300) {
            return $cached['sid'];
        }
    }
    $sid = fritz_login($host, $user, $password);
    file_put_contents($cacheFile, json_encode(['sid' => $sid, 'ts' => time()]));
    return $sid;
}

switch ($action) {
    case 'devicelist':
        $sid = get_sid($host, $user, $password);
        $xml = aha($host, $sid, 'getdevicelistinfos');
        $dom = simplexml_load_string($xml);
        $devices = [];
        foreach ($dom->device as $dev) {
            $d = [
                'ain'          => trim((string)$dev['identifier']),
                'name'         => (string)$dev->name,
                'productname'  => (string)$dev['productname'],
                'manufacturer' => (string)$dev['manufacturer'],
                'fwversion'    => (string)$dev['fwversion'],
                'functionbitmask' => (int)$dev['functionbitmask'],
            ];
            if (isset($dev->powermeter)) {
                $d['powermeter'] = [
                    'voltage' => (int)$dev->powermeter->voltage,
                    'power'   => (int)$dev->powermeter->power,
                    'energy'  => (int)$dev->powermeter->energy,
                ];
            }
            $devices[] = $d;
        }
        echo json_encode(['devices' => $devices, 'raw_xml' => $xml], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    case 'stats':
        $ain = $_GET['ain'] ?? '';
        if ($ain === '') {
            http_response_code(400);
            die(json_encode(['error' => 'Parameter ain fehlt']));
        }
        $sid = get_sid($host, $user, $password);
        $xml = aha($host, $sid, 'getbasicdevicestats', $ain);
        $dom = simplexml_load_string($xml);

        $energy = [];
        if (isset($dom->energy->stats)) {
            foreach ($dom->energy->stats as $s) {
                $grid     = (int)$s['grid'];
                $count    = (int)$s['count'];
                $datatime = (int)$s['datatime'];
                $raw      = trim((string)$s);
                $values   = $raw !== '' ? array_map('intval', explode(',', $raw)) : [];
                $energy[] = ['grid' => $grid, 'count' => $count, 'datatime' => $datatime, 'values' => $values];
            }
        }

        $power = [];
        if (isset($dom->power->stats)) {
            foreach ($dom->power->stats as $s) {
                $grid     = (int)$s['grid'];
                $count    = (int)$s['count'];
                $datatime = (int)$s['datatime'];
                $raw      = trim((string)$s);
                $values   = $raw !== '' ? array_map(fn($v) => (float)$v / 100, explode(',', $raw)) : [];
                $power[] = ['grid' => $grid, 'count' => $count, 'datatime' => $datatime, 'values' => $values];
            }
        }

        echo json_encode([
            'ain'    => $ain,
            'energy' => $energy,
            'power'  => $power,
            'raw_xml' => $xml,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    case 'live':
        $sid = get_sid($host, $user, $password);
        $xml = aha($host, $sid, 'getdevicelistinfos');
        $dom = simplexml_load_string($xml);
        $meters = [];
        foreach ($dom->device as $dev) {
            if (!isset($dev->powermeter)) continue;
            $meters[] = [
                'ain'    => trim((string)$dev['identifier']),
                'name'   => (string)$dev->name,
                'power'  => (int)$dev->powermeter->power,
                'energy' => (int)$dev->powermeter->energy,
            ];
        }
        $ts = time();
        $result = ['meters' => $meters, 'ts' => $ts];

        $dataDir = '/var/www/data';
        $logFile = "$dataDir/" . date('Y-m-d', $ts) . '.jsonl';
        $logLine = json_encode(['ts' => $ts, 'meters' => $meters], JSON_UNESCAPED_UNICODE);
        @file_put_contents($logFile, $logLine . "\n", FILE_APPEND | LOCK_EX);

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    case 'history':
        $date = $_GET['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            die(json_encode(['error' => 'Ungültiges Datum']));
        }
        $dataDir = '/var/www/data';
        $logFile = "$dataDir/$date.jsonl";
        $readings = [];
        if (file_exists($logFile)) {
            foreach (file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $entry = json_decode($line, true);
                if ($entry) $readings[] = $entry;
            }
        }

        $ainEntries = parse_ain_map($ainMap);
        $bezugAin = null; $einspAin = null;
        foreach ($ainEntries as $e) {
            if (stripos($e['label'], 'einspe') !== false) $einspAin = $e['ain'];
            else $bezugAin = $e['ain'];
        }

        $processed = [];
        $windowSeconds = 300;
        foreach ($readings as $i => $r) {
            $bezug = null; $einsp = null;
            foreach ($r['meters'] as $m) {
                if ($m['ain'] === $bezugAin || str_ends_with($m['ain'], '-1')) $bezug = $m;
                if ($m['ain'] === $einspAin || str_ends_with($m['ain'], '-2')) $einsp = $m;
            }
            $power = $r['meters'][0]['power'] ?? 0;

            $entry = [
                'ts' => $r['ts'],
                'power' => $power / 1000,
                'bezug_energy' => $bezug['energy'] ?? 0,
                'einsp_energy' => $einsp['energy'] ?? 0,
            ];

            $windowStart = null;
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($r['ts'] - $readings[$j]['ts'] >= $windowSeconds) {
                    $windowStart = $processed[$j] ?? null;
                    break;
                }
            }

            if ($windowStart) {
                $bDelta = max(0, $entry['bezug_energy'] - $windowStart['bezug_energy']);
                $eDelta = max(0, $entry['einsp_energy'] - $windowStart['einsp_energy']);
                $entry['bezug_delta'] = $bDelta;
                $entry['einsp_delta'] = $eDelta;

                if ($eDelta > $bDelta) {
                    $entry['direction'] = 'export';
                } else if ($bDelta > $eDelta) {
                    $entry['direction'] = 'import';
                } else {
                    $entry['direction'] = 'balanced';
                }
            }

            $processed[] = $entry;
        }

        $availableDays = [];
        foreach (glob("$dataDir/*.jsonl") as $f) {
            $availableDays[] = basename($f, '.jsonl');
        }
        sort($availableDays);

        echo json_encode([
            'date' => $date,
            'readings' => $processed,
            'available_days' => $availableDays,
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'config':
        $entries = parse_ain_map($ainMap);
        echo json_encode([
            'ains' => $entries,
            'prices' => [
                'bezug'       => (float)env('PRICE_BEZUG', '31.72'),
                'einspeisung' => (float)env('PRICE_EINSPEISUNG', '8.00'),
            ],
            'battery' => [
                'capacity' => (float)env('BATTERY_CAPACITY_KWH', '1.92'),
                'price'    => (float)env('BATTERY_PRICE_EUR', '399'),
                'name'     => env('BATTERY_NAME', 'Speicher'),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => "Unbekannte action: '$action'. Erlaubt: devicelist, stats, config"]);
}
