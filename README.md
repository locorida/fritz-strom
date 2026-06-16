# FRITZ!Box Energiemonitor

Lokale Web-App zur Visualisierung der Energiedaten eines **FRITZ!Smart Energy 250** über das AHA-HTTP-Interface der FRITZ!Box.

![Screenshot](https://img.shields.io/badge/stack-PHP_8.4_|_Chart.js_4_|_Docker-blue)

## Features

- **Bezug & Einspeisung** als getrennte Datenreihen (rot/grün) mit automatischer Richtungserkennung
- **Tagesverlauf** aus lokaler Aufzeichnung — beliebige Zeiträume filterbar (z.B. 10:00–12:00)
- Zeiträume: Live (60 Min) / Heute / Woche / Monat / Jahr / Total
- Zeitraum-Filter mit Detail-Panel (Durchschnitt, Maximum, Kosten, Netto-Bilanz)
- Einheit umschaltbar: Wh / kWh
- Darstellung: gruppiert oder gestapelt
- Live-Kennzahlen: Netzleistung, Richtungsanzeige, Tages-Bezug/-Einspeisung, Netto-Bilanz, Kosten
- Speicher-Simulation: Amortisationsrechnung für Batteriespeicher
- **Wetter-Widget** mit PLZ-Eingabe: Aktuelle Temperatur, Sonnenschein, Solarstrahlungs-Prognose (stündlich), beste Verbrauchszeit
- Automatische Aktualisierung alle 30 Sekunden
- Eigenständiger Collector-Service — loggt unabhängig vom Browser
- Filter bleibt über Seitenrefresh erhalten (sessionStorage)
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

Beide Container (`web` + `collector`) starten automatisch mit Docker (`restart: unless-stopped`).

## Architektur

### Zwei Services

| Service | Image | Aufgabe |
|---------|-------|---------|
| `web` | `php:8.4-apache` | Dashboard (Frontend + API) auf Port 8080 |
| `collector` | `php:8.4-cli` | Fragt alle 30s die Fritz!Box ab und loggt in `./data/` |

### Richtungserkennung

Die Fritz!Box liefert nur **eine** Momentanleistung (W). Bezug vs. Einspeisung wird über die **Energiezähler-Deltas** (Wh) der beiden Kanäle (`-1` = Bezug, `-2` = Einspeisung) ermittelt. Da die Zähler nur ganzzahlige Wh auflösen, nutzt die API ein **5-Minuten-Gleitfenster** — bei niedrigen Leistungen (< 120 W) braucht es einige Minuten bis die Richtung erkannt wird.

### Datenaufzeichnung

- Der Collector schreibt pro Tag eine JSONL-Datei nach `./data/YYYY-MM-DD.jsonl`
- Jede Zeile: `{"ts": unix_ts, "meters": [{"ain": "...", "power": mW, "energy": Wh}, ...]}`
- Die History-API (`?action=history&date=YYYY-MM-DD`) berechnet Richtung und Deltas on-the-fly
- **Wenn Docker nicht läuft, werden keine Daten geloggt** — die Fritz!Box bietet keinen rückwirkenden Export

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

## Wetter & Solarprognose

Das Dashboard enthält ein Wetter-Widget, das bei der Planung hilft, wann Geräte eingeschaltet werden sollten:

- **PLZ eingeben** — wird im Browser gespeichert (localStorage)
- **Aktuelle Wetterdaten** via [Open-Meteo](https://open-meteo.com/) (kostenlos, kein API-Key nötig)
- **Solarstrahlungs-Balkendiagramm** zeigt stündlich die erwartete Globalstrahlung (W/m²)
- **Beste Verbrauchszeit** wird automatisch berechnet (Stunden mit > 50% der Spitzenstrahlung)
- Daten werden serverseitig 30 Minuten gecacht

Geocoding erfolgt über [Zippopotam.us](https://zippopotam.us/) (Fallback: Nominatim/OpenStreetMap).

## API-Endpunkte

| Endpunkt | Beschreibung |
|----------|-------------|
| `?action=config` | AIN-Konfiguration, Preise, Speicher-Parameter |
| `?action=live` | Aktuelle Zählerstände aller Powermeter |
| `?action=stats&ain=...` | Historische Statistiken von der Fritz!Box (60 Min / Tage / Monate) |
| `?action=history&date=YYYY-MM-DD` | Lokale Aufzeichnung mit Richtungserkennung |
| `?action=devicelist` | Alle Smart-Home-Geräte (zur AIN-Ermittlung) |
| `/api/weather.php?plz=12345` | Wetter + Solarprognose für deutsche PLZ |

## Hinweise

- Aus Docker-Containern wird `fritz.box` nicht aufgelöst — daher immer die IP verwenden.
- Die Momentanleistung (W) ist auf beiden Kanälen identisch — der Zähler misst den absoluten Netzfluss. Nur die Energiezähler (Wh) unterscheiden nach Richtung.
- `getswitchpower` / `getswitchenergy` funktionieren bei Energiezählern nicht (HTTP 500). Die App nutzt stattdessen `getdevicelistinfos` und `getbasicdevicestats`.

## Projektstruktur

```
├── docker-compose.yml
├── .env.example
├── .gitignore
├── collector/
│   └── collect.php         # Standalone-Collector (30s-Intervall)
├── data/                   # Lokale Messdaten (nicht im Repo)
│   └── YYYY-MM-DD.jsonl
└── src/
    ├── index.html          # Frontend (Chart.js)
    └── api/
        ├── fritz.php       # Backend (AHA-HTTP-Interface + History-API)
        └── weather.php     # Wetter-API (Open-Meteo + PLZ-Geocoding)
```
