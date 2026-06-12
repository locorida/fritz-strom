<?php
$host     = getenv('FRITZ_HOST') ?: 'http://192.168.178.1';
$user     = getenv('FRITZ_USER');
$password = getenv('FRITZ_PASSWORD');
$interval = (int)(getenv('COLLECT_INTERVAL') ?: 30);
$dataDir  = '/var/www/data';

function log_msg(string $msg): void {
    echo date('[Y-m-d H:i:s] ') . $msg . "\n";
}

function fritz_login(string $host, string $user, string $password): ?string {
    $body = @file_get_contents("$host/login_sid.lua?version=2");
    if ($body === false) return null;

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

    return $sid !== '0000000000000000' ? $sid : null;
}

log_msg("Collector gestartet (Intervall: {$interval}s)");

$sid = null;
$sidExpiry = 0;

while (true) {
    $now = time();

    if (!$sid || $now >= $sidExpiry) {
        $sid = fritz_login($host, $user, $password);
        if (!$sid) {
            log_msg("Login fehlgeschlagen — Retry in {$interval}s");
            sleep($interval);
            continue;
        }
        $sidExpiry = $now + 300;
        log_msg("Login OK");
    }

    $url = "$host/webservices/homeautoswitch.lua?switchcmd=getdevicelistinfos&sid=$sid";
    $xml = @file_get_contents($url);

    if ($xml === false) {
        log_msg("Fritz!Box nicht erreichbar");
        $sid = null;
        sleep($interval);
        continue;
    }

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

    if (count($meters) > 0) {
        $logFile = "$dataDir/" . date('Y-m-d', $now) . '.jsonl';
        $logLine = json_encode(['ts' => $now, 'meters' => $meters], JSON_UNESCAPED_UNICODE);
        file_put_contents($logFile, $logLine . "\n", FILE_APPEND | LOCK_EX);
    }

    sleep($interval);
}
