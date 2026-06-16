<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=1800');

$plz = $_GET['plz'] ?? '';
if (!preg_match('/^\d{4,5}$/', $plz)) {
    http_response_code(400);
    die(json_encode(['error' => 'Ungültige PLZ (4-5 Ziffern erwartet)']));
}

$cacheDir = '/tmp/weather_cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

function geocodePlz(string $plz, string $cacheDir): ?array {
    $cacheFile = "$cacheDir/geo_$plz.json";
    if (file_exists($cacheFile)) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    $ctx = stream_context_create(['http' => [
        'timeout' => 5,
        'header' => "User-Agent: FritzStromDashboard/1.0\r\n",
    ]]);

    $response = @file_get_contents("https://api.zippopotam.us/de/$plz", false, $ctx);
    if ($response !== false) {
        $data = json_decode($response, true);
        if (!empty($data['places'][0])) {
            $place = $data['places'][0];
            $result = [
                'name' => $place['place name'],
                'lat'  => (float)$place['latitude'],
                'lon'  => (float)$place['longitude'],
            ];
            file_put_contents($cacheFile, json_encode($result));
            return $result;
        }
    }

    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'postalcode' => $plz, 'country' => 'de', 'format' => 'json', 'limit' => 1,
    ]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response !== false) {
        $data = json_decode($response, true);
        if (!empty($data[0])) {
            $parts = explode(',', $data[0]['display_name']);
            $result = [
                'name' => trim($parts[0]),
                'lat'  => (float)$data[0]['lat'],
                'lon'  => (float)$data[0]['lon'],
            ];
            file_put_contents($cacheFile, json_encode($result));
            return $result;
        }
    }

    return null;
}

function fetchWeather(float $lat, float $lon, string $cacheDir): ?array {
    $cacheKey = round($lat, 2) . '_' . round($lon, 2);
    $cacheFile = "$cacheDir/wx_$cacheKey.json";

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 1800) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    $params = http_build_query([
        'latitude'      => $lat,
        'longitude'     => $lon,
        'current'       => 'temperature_2m,relative_humidity_2m,weather_code,wind_speed_10m,cloud_cover,is_day',
        'hourly'        => 'temperature_2m,shortwave_radiation,cloud_cover,weather_code,sunshine_duration',
        'daily'         => 'sunrise,sunset,sunshine_duration,temperature_2m_max,temperature_2m_min,weather_code',
        'timezone'      => 'Europe/Berlin',
        'forecast_days' => 1,
    ]);

    $ctx = stream_context_create(['http' => [
        'timeout' => 8,
        'header' => "User-Agent: FritzStromDashboard/1.0\r\n",
    ]]);
    $response = @file_get_contents("https://api.open-meteo.com/v1/forecast?$params", false, $ctx);

    if ($response === false) return null;

    $data = json_decode($response, true);
    if (empty($data) || isset($data['error'])) return null;

    file_put_contents($cacheFile, $response);
    return $data;
}

function weatherInfo(int $code, bool $isDay = true): array {
    $map = [
        0  => ['Klar',                        "\u{2600}\u{FE0F}", "\u{1F319}"],
        1  => ['Überwiegend klar',             "\u{1F324}\u{FE0F}", "\u{1F319}"],
        2  => ['Teilweise bewölkt',            "\u{26C5}",         "\u{2601}\u{FE0F}"],
        3  => ['Bedeckt',                      "\u{2601}\u{FE0F}", "\u{2601}\u{FE0F}"],
        45 => ['Nebel',                        "\u{1F32B}\u{FE0F}", "\u{1F32B}\u{FE0F}"],
        48 => ['Reifnebel',                    "\u{1F32B}\u{FE0F}", "\u{1F32B}\u{FE0F}"],
        51 => ['Leichter Nieselregen',         "\u{1F326}\u{FE0F}", "\u{1F327}\u{FE0F}"],
        53 => ['Nieselregen',                  "\u{1F326}\u{FE0F}", "\u{1F327}\u{FE0F}"],
        55 => ['Starker Nieselregen',          "\u{1F327}\u{FE0F}", "\u{1F327}\u{FE0F}"],
        61 => ['Leichter Regen',               "\u{1F326}\u{FE0F}", "\u{1F327}\u{FE0F}"],
        63 => ['Regen',                        "\u{1F327}\u{FE0F}", "\u{1F327}\u{FE0F}"],
        65 => ['Starker Regen',                "\u{1F327}\u{FE0F}", "\u{1F327}\u{FE0F}"],
        66 => ['Gefrierender Regen',           "\u{1F328}\u{FE0F}", "\u{1F328}\u{FE0F}"],
        67 => ['Starker gefrierender Regen',   "\u{1F328}\u{FE0F}", "\u{1F328}\u{FE0F}"],
        71 => ['Leichter Schneefall',          "\u{1F328}\u{FE0F}", "\u{1F328}\u{FE0F}"],
        73 => ['Schneefall',                   "\u{2744}\u{FE0F}", "\u{2744}\u{FE0F}"],
        75 => ['Starker Schneefall',           "\u{2744}\u{FE0F}", "\u{2744}\u{FE0F}"],
        80 => ['Leichte Regenschauer',         "\u{1F326}\u{FE0F}", "\u{1F327}\u{FE0F}"],
        81 => ['Regenschauer',                 "\u{1F327}\u{FE0F}", "\u{1F327}\u{FE0F}"],
        82 => ['Starke Regenschauer',          "\u{1F327}\u{FE0F}", "\u{1F327}\u{FE0F}"],
        85 => ['Leichte Schneeschauer',        "\u{1F328}\u{FE0F}", "\u{1F328}\u{FE0F}"],
        86 => ['Starke Schneeschauer',         "\u{2744}\u{FE0F}", "\u{2744}\u{FE0F}"],
        95 => ['Gewitter',                     "\u{26C8}\u{FE0F}", "\u{26C8}\u{FE0F}"],
        96 => ['Gewitter mit Hagel',           "\u{26C8}\u{FE0F}", "\u{26C8}\u{FE0F}"],
        99 => ['Gewitter mit starkem Hagel',   "\u{26C8}\u{FE0F}", "\u{26C8}\u{FE0F}"],
    ];

    $info = $map[$code] ?? ['Unbekannt', '?', '?'];
    return ['text' => $info[0], 'icon' => $isDay ? $info[1] : $info[2]];
}

function solarRecommendation(array $hourly): array {
    $times     = $hourly['time'] ?? [];
    $radiation = $hourly['shortwave_radiation'] ?? [];
    if (empty($radiation)) return ['best_hours' => [], 'peak_radiation' => 0];

    $peak = max($radiation);
    if ($peak <= 0) return ['best_hours' => [], 'peak_radiation' => 0, 'message' => 'Kein Sonnenschein erwartet'];

    $threshold = $peak * 0.5;
    $bestHours = [];
    $peakHour  = null;

    foreach ($radiation as $i => $val) {
        $hour = (int)substr($times[$i] ?? '', 11, 2);
        if ($val >= $threshold && $hour >= 6 && $hour <= 20) $bestHours[] = $hour;
        if ($val == $peak && $peakHour === null) $peakHour = $hour;
    }

    if (empty($bestHours)) return ['best_hours' => [], 'peak_radiation' => round($peak), 'message' => 'Wenig Sonne erwartet'];

    $from = min($bestHours);
    $to   = max($bestHours);
    return [
        'best_hours'     => $bestHours,
        'peak_radiation' => round($peak),
        'peak_hour'      => $peakHour,
        'window'         => sprintf('%02d:00 – %02d:00', $from, $to + 1),
        'message'        => sprintf('Beste Solarzeit: %02d – %02d Uhr (bis %d W/m²)', $from, $to + 1, round($peak)),
    ];
}

$geo = geocodePlz($plz, $cacheDir);
if (!$geo) {
    http_response_code(404);
    die(json_encode(['error' => "PLZ $plz nicht gefunden"]));
}

$weather = fetchWeather($geo['lat'], $geo['lon'], $cacheDir);
if (!$weather) {
    http_response_code(502);
    die(json_encode(['error' => 'Wetterdaten nicht verfügbar']));
}

$current = $weather['current'] ?? [];
$hourly  = $weather['hourly'] ?? [];
$daily   = $weather['daily'] ?? [];
$isDay   = ($current['is_day'] ?? 1) === 1;
$wxInfo  = weatherInfo($current['weather_code'] ?? 0, $isDay);

$hourlyForecast = [];
$times = $hourly['time'] ?? [];
for ($i = 0; $i < count($times); $i++) {
    $hour = (int)substr($times[$i], 11, 2);
    if ($hour < 5 || $hour > 21) continue;
    $hourlyForecast[] = [
        'hour'         => $hour,
        'time'         => sprintf('%02d:00', $hour),
        'temperature'  => $hourly['temperature_2m'][$i] ?? null,
        'radiation'    => $hourly['shortwave_radiation'][$i] ?? 0,
        'cloud_cover'  => $hourly['cloud_cover'][$i] ?? 0,
        'sunshine_min' => round(($hourly['sunshine_duration'][$i] ?? 0) / 60, 1),
    ];
}

$solar = solarRecommendation($hourly);

echo json_encode([
    'location' => $geo,
    'current'  => [
        'temperature'  => $current['temperature_2m'] ?? null,
        'humidity'     => $current['relative_humidity_2m'] ?? null,
        'wind_speed'   => $current['wind_speed_10m'] ?? null,
        'cloud_cover'  => $current['cloud_cover'] ?? null,
        'weather_code' => $current['weather_code'] ?? 0,
        'weather_text' => $wxInfo['text'],
        'weather_icon' => $wxInfo['icon'],
        'is_day'       => $isDay,
    ],
    'hourly' => $hourlyForecast,
    'daily'  => [
        'sunrise'        => isset($daily['sunrise'][0]) ? substr($daily['sunrise'][0], 11, 5) : null,
        'sunset'         => isset($daily['sunset'][0]) ? substr($daily['sunset'][0], 11, 5) : null,
        'sunshine_hours' => isset($daily['sunshine_duration'][0]) ? round($daily['sunshine_duration'][0] / 3600, 1) : null,
        'temp_max'       => $daily['temperature_2m_max'][0] ?? null,
        'temp_min'       => $daily['temperature_2m_min'][0] ?? null,
    ],
    'solar' => $solar,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
