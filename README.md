# FRITZ!Box Energiemonitor

Lokale Web-App zur Visualisierung der Energiedaten eines **FRITZ!Smart Energy 250** über das AHA-HTTP-Interface der FRITZ!Box.

![Screenshot](https://img.shields.io/badge/stack-PHP_8.3_|_Chart.js_4_|_Docker-blue)

## Features

- **Bezug & Einspeisung** als getrennte Datenreihen im Balkendiagramm
- **Live-Ansicht** der Netzleistung (letzte 60 Minuten)
- Zeiträume: Live / Woche / Monat / Jahr
- Einheit umschaltbar: Wh / kWh
- Darstellung: gruppiert oder gestapelt
- Live-Kennzahlen: aktuelle Netzleistung, Tages-Bezug, Tages-Einspeisung, Netto-Bilanz
- Automatische Aktualisierung jede Minute
- Dark Theme

## Voraussetzungen

- Docker & Docker Compose
- FRITZ!Box im selben Netzwerk (getestet mit FRITZ!Box 7530 AX, FRITZ!OS 8.25)
- FRITZ!Smart Energy 250 am Stromzähler
- FRITZ!Box-Benutzer mit Berechtigung „Smart Home"

## Schnellstart

1. Repository klonen:
   ```bash
   git clone https://github.com/locorida/fritz-strom.git
   cd fritz-strom
   ```

2. `.env` anlegen (Vorlage kopieren und anpassen):
   ```bash
   cp .env.example .env
   ```

3. In der `.env` Zugangsdaten und AINs eintragen:
   ```env
   FRITZ_HOST=http://192.168.178.1
   FRITZ_USER=mein_benutzer
   FRITZ_PASSWORD=mein_passwort
   FRITZ_AINS=Verbrauch:16000 0031287-1,Einspeisung:16000 0031287-2
   ```

4. Starten:
   ```bash
   docker compose up -d
   ```

5. Öffnen: **http://localhost:8080**

## AINs ermitteln

Falls du deine AINs nicht kennst, starte die App und rufe die Geräteliste ab:

```
http://localhost:8080/api/fritz.php?action=devicelist
```

Der FRITZ!Smart Energy 250 erzeugt typischerweise drei Einträge:
| AIN-Suffix | Bedeutung |
|---|---|
| (ohne) | Basisgerät |
| `-1` | Bezug (Verbrauch vom Netz, A+) |
| `-2` | Einspeisung (ins Netz, A−) |

## Hinweise

- Aus Docker-Containern wird `fritz.box` nicht aufgelöst — daher immer die IP verwenden.
- Die Momentanleistung (W) ist auf beiden Kanälen identisch — der Zähler misst den absoluten Netzfluss. Nur die Energiezähler (Wh) unterscheiden nach Richtung.
- `getswitchpower` / `getswitchenergy` funktionieren bei Energiezählern nicht (HTTP 500). Die App nutzt stattdessen `getdevicelistinfos` und `getbasicdevicestats`.

## Projektstruktur

```
├── docker-compose.yml
├── .env.example
├── .gitignore
└── src/
    ├── index.html          # Frontend (Chart.js)
    └── api/
        └── fritz.php       # Backend (AHA-HTTP-Interface)
```
