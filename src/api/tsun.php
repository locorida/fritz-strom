<?php
header('Content-Type: application/json; charset=utf-8');

$dataDir = '/var/www/data';
$stateFile = "$dataDir/tsun-state.json";

function findValue(array $sensors, array $keys): ?float {
    foreach ($keys as $key) {
        if (isset($sensors[$key]['value']) && is_numeric($sensors[$key]['value'])) {
            return (float)$sensors[$key]['value'];
        }
    }
    foreach ($sensors as $k => $s) {
        foreach ($keys as $key) {
            if (str_contains($k, $key) && is_numeric($s['value'])) {
                return (float)$s['value'];
            }
        }
    }
    return null;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'live':
        if (!file_exists($stateFile)) {
            echo json_encode(['ts' => null, 'sensors' => new \stdClass(), 'power_w' => null, 'daily_kwh' => null]);
            break;
        }

        $raw = file_get_contents($stateFile);
        $data = json_decode($raw, true);
        if (!$data || empty($data['sensors'])) {
            echo json_encode(['ts' => null, 'sensors' => new \stdClass(), 'power_w' => null, 'daily_kwh' => null]);
            break;
        }

        $sensors = $data['sensors'];
        $age = time() - ($data['ts'] ?? 0);

        echo json_encode([
            'ts'        => $data['ts'],
            'age_s'     => $age,
            'sensors'   => $sensors,
            'power_w'   => findValue($sensors, ['output_power', 'ac_power', 'power']),
            'pv1_w'     => findValue($sensors, ['pv1_power', 'pv_power_1']),
            'pv2_w'     => findValue($sensors, ['pv2_power', 'pv_power_2']),
            'daily_kwh' => findValue($sensors, ['daily_generation', 'daily_energy']),
            'total_kwh' => findValue($sensors, ['total_generation', 'total_energy']),
            'temp_c'    => findValue($sensors, ['inverter_temp', 'temperature']),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'history':
        $date = $_GET['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            die(json_encode(['error' => 'Ungültiges Datum']));
        }

        $file = "$dataDir/tsun/$date.jsonl";
        $readings = [];
        if (file_exists($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $entry = json_decode($line, true);
                if ($entry) $readings[] = $entry;
            }
        }

        echo json_encode([
            'date' => $date,
            'readings' => $readings,
        ], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => "Unbekannte action: '$action'. Erlaubt: live, history"]);
}
