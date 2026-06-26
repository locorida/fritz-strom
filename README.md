# FRITZ!Box Energiemonitor

Lokale Web-App zur Visualisierung der Energiedaten eines **FRITZ!Smart Energy 250** mit **TSUN Solar-Monitoring** und **Home Assistant** Integration.

![Screenshot](https://img.shields.io/badge/stack-PHP_8.4_|_Chart.js_4_|_Docker_|_MQTT-blue)

## Features

- **Bezug & Einspeisung** als getrennte Datenreihen (rot/grün) mit automatischer Richtungserkennung
- **Solar-Monitoring** — TSUN Mikro-Wechselrichter (GEN3) via MQTT, Live-Leistung und Tagesertrag
- **Energiebilanz** — Donut-Chart mit Autarkie-Quote, Eigenverbrauch, Einspeisung, Netzbezug (tagesweise navigierbar)
- **Tagesverlauf** aus lokaler Aufzeichnung — beliebige Zeiträume filterbar (z.B. 10:00–12:00)
- Zeiträume: Live (60 Min) / Heute / Woche / Monat / Jahr / Total
- Zeitraum-Filter mit Detail-Panel (Durchschnitt, Maximum, Kosten, Netto-Bilanz)
- Einheit umschaltbar: Wh / kWh
- Darstellung: gruppiert oder gestapelt
- Live-Kennzahlen: Netzleistung, Richtungsanzeige, Solar-Leistung, Tages-Bezug/-Einspeisung, Netto-Bilanz, Kosten, Eigenverbrauch, Autarkie
- Speicher-Simulation: Amortisationsrechnung für Batteriespeicher
- **Wetter-Widget** mit PLZ-Eingabe: Aktuelle Temperatur, Sonnenschein, Solarstrahlungs-Prognose (stündlich), beste Verbrauchszeit
- Automatische Aktualisierung alle 30 Sekunden
- Eigenständige Collector-Services — loggen unabhängig vom Browser
- Filter bleibt über Seitenrefresh erhalten (sessionStorage)
- Dark Theme

## Voraussetzungen

- Docker & Docker Compose
- FRITZ!Box im selben Netzwerk (getestet mit FRITZ!Box 7530 AX, FRITZ!OS 8.25)
- FRITZ!Smart Energy 250 am Stromzähler
- FRITZ!Box-Benutzer mit Berechtigung „Smart Home"
- Optional: TSUN Mikro-Wechselrichter (GEN3, z.B. TSOL-MS600) mit WLAN-Logger

## Schnellstart

### Server-Setup (Ubuntu Server 24.04)

```bash
# Setup-Script installiert Docker, statische IP, Firewall
chmod +x setup-server.sh && sudo ./setup-server.sh
```

### Projekt starten

1. Repository klonen:
   ```bash
   git clone https://github.com/locorida/fritz-strom.git /opt/fritz-strom
   cd /opt/fritz-strom
   ```

2. `.env` anlegen:
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

4. TSUN-Proxy konfigurieren (optional):
   ```bash
   cp tsun-config/config.example.toml tsun-config/config.toml
   # Seriennummer des Wechselrichters eintragen
   ```

5. Starten:
   ```bash
   docker compose up -d
   ```

6. Erreichbar:
   - **Dashboard:** http://server-ip:9090
   - **Home Assistant:** http://server-ip:8123
   - **TSUN Proxy:** http://server-ip:8127

## Architektur

### Services

| Service | Image | Aufgabe |
|---------|-------|---------|
| `web` | `php:8.4-apache` | Dashboard (Frontend + API) auf Port 9090 |
| `collector` | `php:8.4-cli` | Fragt alle 30s die FRITZ!Box ab und loggt in `./data/` |
| `tsun-collector` | `php:8.4-cli` | Sammelt TSUN-Solardaten via MQTT |
| `tsun-proxy` | `ghcr.io/s-allius/tsun-gen3-proxy` | Fängt TSUN-Logger ab, leitet an Cloud weiter, publiziert via MQTT |
| `mqtt-broker` | `eclipse-mosquitto:2` | MQTT-Broker für Solar-Daten |
| `homeassistant` | `ghcr.io/home-assistant/home-assistant:stable` | Smart-Home-Zentrale |

### TSUN Solar-Integration

Der TSUN-Logger (am Wechselrichter) verbindet sich normalerweise zur TSUN-Cloud. Per **DNS-Rewrite** (z.B. NextDNS) wird `logger.talent-monitoring.com` auf die lokale Server-IP umgeleitet. Der TSUN-Proxy:

1. Empfängt die Logger-Daten lokal
2. Leitet sie optional an die TSUN-Cloud weiter (SmartLife-App funktioniert weiterhin)
3. Publiziert Messdaten per MQTT (`tsun/solar/grid`, `tsun/solar/total`, `tsun/solar/input`)

Voraussetzung: DNS-Rebind-Ausnahme in der FRITZ!Box für `logger.talent-monitoring.com`.

### Richtungserkennung

Die FRITZ!Box liefert nur **eine** Momentanleistung (W). Bezug vs. Einspeisung wird über die **Energiezähler-Deltas** (Wh) der beiden Kanäle (`-1` = Bezug, `-2` = Einspeisung) ermittelt.

### Datenaufzeichnung

- FRITZ!Box-Collector: pro Tag eine JSONL-Datei nach `./data/YYYY-MM-DD.jsonl`
- TSUN-Collector: Live-State in `./data/tsun-state.json`, Snapshots in `./data/tsun/YYYY-MM-DD.jsonl`
- **Wenn Docker nicht läuft, werden keine Daten geloggt**

## API-Endpunkte

| Endpunkt | Beschreibung |
|----------|-------------|
| `/api/fritz.php?action=config` | AIN-Konfiguration, Preise, Speicher-Parameter |
| `/api/fritz.php?action=live` | Aktuelle Zählerstände aller Powermeter |
| `/api/fritz.php?action=stats&ain=...` | Historische Statistiken (60 Min / Tage / Monate) |
| `/api/fritz.php?action=history&date=YYYY-MM-DD` | Lokale Aufzeichnung mit Richtungserkennung |
| `/api/tsun.php?action=live` | Aktuelle TSUN-Solardaten |
| `/api/tsun.php?action=history&date=YYYY-MM-DD` | TSUN-Tagesaufzeichnung |
| `/api/weather.php?plz=12345` | Wetter + Solarprognose für deutsche PLZ |

## Projektstruktur

```
├── docker-compose.yml
├── .env.example
├── .gitignore
├── setup-server.sh           # Server-Setup (Docker, IP, Firewall)
├── collector/
│   ├── collect.php           # FRITZ!Box-Collector (30s-Intervall)
│   └── collect_tsun.php      # TSUN MQTT-Collector
├── data/                     # Lokale Messdaten (nicht im Repo)
│   ├── YYYY-MM-DD.jsonl      # FRITZ!Box-Daten
│   └── tsun/YYYY-MM-DD.jsonl # TSUN-Solardaten
├── mosquitto/
│   └── mosquitto.conf        # Mosquitto MQTT-Broker Config
├── tsun-config/
│   └── config.example.toml   # TSUN-Proxy Beispielkonfiguration
├── docs/
│   └── wireguard-setup.md    # VPN-Anleitung
└── src/
    ├── index.html            # Frontend (Chart.js)
    └── api/
        ├── fritz.php          # FRITZ!Box API
        ├── tsun.php           # TSUN Solar API
        └── weather.php        # Wetter-API
```
