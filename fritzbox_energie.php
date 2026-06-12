<?php
/**
 * FRITZ!Box Smart-Home Energie auslesen (AHA-HTTP-Interface)
 * Getestet gegen FRITZ!OS 7.x. Laeuft auf Windows mit normalem PHP.
 *
 * Voraussetzung: in der php.ini muss  allow_url_fopen = On  stehen
 * (ist Standard). Alternativ unten auf cURL umstellen.
 *
 * Aufruf:  php fritzbox_energie.php
 */

// ===== Konfiguration =====
$host = 'http://192.168.178.1';   // statt http://fritz.box
$user     = 'YOUR_USERNAME';      // FRITZ!Box-Benutzername (Smart Home / Einstellungen erlaubt)
$password = 'YOUR_PASSWORD_HERE';
$ain      = '16000 0031287';      // deine Aktor-ID (AIN) - exakt so wie angezeigt

// ===== Login: SID holen =====
function fritz_login($host, $user, $password) {
    $xml = simplexml_load_string(@file_get_contents("$host/login_sid.lua?version=2"));
    if (!$xml) { die("FRITZ!Box nicht erreichbar unter $host\n"); }

    $challenge = (string)$xml->Challenge;

    if (strpos($challenge, '2$') === 0) {
        // Neues PBKDF2-Verfahren (FRITZ!OS 7.24+)
        list($_, $iter1, $salt1, $iter2, $salt2) = explode('$', $challenge);
        $hash1    = hash_pbkdf2('sha256', $password, hex2bin($salt1), (int)$iter1, 0, true);
        $hash2    = hash_pbkdf2('sha256', $hash1,    hex2bin($salt2), (int)$iter2, 0, true);
        $response = $salt2 . '$' . bin2hex($hash2);
    } else {
        // Altes MD5-Verfahren
        $text     = $challenge . '-' . $password;
        $response = $challenge . '-' . md5(mb_convert_encoding($text, 'UTF-16LE'));
    }

    $url = "$host/login_sid.lua?version=2&username=" . urlencode($user) . "&response=$response";
    $xml = simplexml_load_string(file_get_contents($url));
    $sid = (string)$xml->SID;

    if ($sid === '0000000000000000') {
        die("Login fehlgeschlagen - Benutzer/Passwort/Rechte pruefen.\n");
    }
    return $sid;
}

// ===== AHA-Kommando abschicken =====
function aha($host, $sid, $cmd, $ain = null) {
    $url = "$host/webservices/homeautoswitch.lua?switchcmd=$cmd&sid=$sid";
    if ($ain !== null) { $url .= '&ain=' . urlencode($ain); }
    return file_get_contents($url);
}

// ===== Los geht's =====
$sid = fritz_login($host, $user, $password);

// --- Aktuelle Momentanwerte ---
$power  = trim(aha($host, $sid, 'getswitchpower',  $ain)); // in mW  ("inval" wenn nicht verfuegbar)
$energy = trim(aha($host, $sid, 'getswitchenergy', $ain)); // in Wh  (Gesamtzaehler)

echo "Aktuelle Leistung: " . (is_numeric($power)  ? ($power  / 1000) . " W"   : $power)  . "\n";
echo "Gesamtenergie:     " . (is_numeric($energy) ? ($energy / 1000) . " kWh" : $energy) . "\n\n";

// --- Verlaufsdaten (das, was du im Diagramm als Balken siehst) ---
$statsXml = simplexml_load_string(aha($host, $sid, 'getbasicdevicestats', $ain));

$result = array();
if (isset($statsXml->energy->stats)) {
    foreach ($statsXml->energy->stats as $s) {
        $grid   = (int)$s['grid'];                 // Abstand der Messpunkte in Sekunden
        $count  = (int)$s['count'];                // Anzahl Werte
        $values = array_map('intval', explode(',', (string)$s)); // Werte pro Periode (Wh)
        $result[] = array('grid' => $grid, 'count' => $count, 'values' => $values);
    }
}

// Als JSON ausgeben -> kannst du direkt an ein Chart-Frontend weiterreichen
echo "Energie-Verlauf (JSON):\n";
echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
