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

$sid = fritz_login($host, $user, $password);

switch ($action) {
    case 'devicelist':
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

    case 'config':
        $entries = parse_ain_map($ainMap);
        echo json_encode(['ains' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => "Unbekannte action: '$action'. Erlaubt: devicelist, stats, config"]);
}
