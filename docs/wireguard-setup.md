# WireGuard VPN über FRITZ!Box — Fernzugriff auf Home Server

## Voraussetzungen
- FRITZ!Box 7530 AX mit aktueller Firmware (7.50+)
- MyFRITZ!-Konto aktiv (`3i8glkvnqta9pnqx.myfritz.net`)
- WireGuard-App auf Handy/Laptop

## 1. FRITZ!Box: VPN-Verbindung anlegen

1. **FRITZ!Box öffnen:** `http://fritz.box` → Anmelden
2. **Internet → Freigaben → VPN (WireGuard)** aufrufen
3. **"VPN-Verbindung hinzufügen"** klicken
4. **"Einzelgerät verbinden"** auswählen → Weiter
5. **Name vergeben** (z.B. "Max Handy" oder "Max Laptop")
6. **"Einstellungen übernehmen"** klicken
7. → FRITZ!Box zeigt einen **QR-Code** und bietet eine **Konfigurationsdatei** zum Download an

## 2. Handy einrichten (Android / iOS)

1. **WireGuard App** installieren (kostenlos im App Store / Play Store)
2. App öffnen → **"+" → "Von QR-Code erstellen"**
3. QR-Code aus Schritt 1 scannen
4. Name vergeben (z.B. "Zuhause")
5. **Verbindung aktivieren** → Fertig

## 3. Laptop einrichten (Windows / Mac / Linux)

1. **WireGuard** installieren: https://www.wireguard.com/install/
2. Die **.conf-Datei** aus Schritt 1 herunterladen
3. WireGuard öffnen → **"Tunnel hinzufügen"** → .conf-Datei auswählen
4. **"Aktivieren"** klicken → Fertig

## 4. Testen

VPN aktivieren und folgende Adressen im Browser aufrufen:

| Service          | Adresse                        |
|------------------|--------------------------------|
| Dashboard        | `http://192.168.178.123:9090`  |
| Home Assistant   | `http://192.168.178.123:8123`  |
| TSUN Proxy       | `http://192.168.178.123:8127`  |
| FRITZ!Box        | `http://192.168.178.1`         |

## 5. Automatische VPN-Verbindung (Handy)

Damit das VPN sich automatisch aktiviert wenn du nicht zuhause bist:

### Android (FRITZ!App WLAN)
1. **FRITZ!App WLAN** installieren
2. Einstellungen → "VPN automatisch aktivieren" einschalten
3. Heim-WLAN auswählen → VPN wird nur unterwegs aktiviert

### iOS (WireGuard App)
1. WireGuard App → Tunnel bearbeiten
2. **"On-Demand aktivieren"** einschalten
3. Regel: **"Nur über Mobilfunk und WLAN"** + Ausnahme für euer Heim-WLAN (SSID eintragen)

## 6. Mehrere Geräte

Für jedes Gerät (Handy, Laptop, Tablet) eine **eigene VPN-Verbindung** in der FRITZ!Box anlegen — Schritt 1 wiederholen. Jedes Gerät bekommt eine eigene IP im VPN-Netz.

## Hinweise

- **Geschwindigkeit:** Upload-Speed der FRITZ!Box ist der Flaschenhals (bei DSL ca. 10-40 Mbit/s). Für Dashboard/HA-Steuerung mehr als genug.
- **Sicherheit:** Keine Ports nach außen offen. Alles läuft verschlüsselt durch den WireGuard-Tunnel.
- **DynDNS:** MyFRITZ! aktualisiert die IP automatisch — du musst dich um nichts kümmern.
