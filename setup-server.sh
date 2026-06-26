#!/bin/bash
set -e

# Fritz-Strom Server Setup — Ubuntu Server 24.04 auf ThinkCentre M710Q
# Einmalig nach frischer Ubuntu-Installation ausführen:
#   curl -fsSL https://raw.githubusercontent.com/.../setup-server.sh | bash
#   oder: chmod +x setup-server.sh && sudo ./setup-server.sh

if [ "$EUID" -ne 0 ]; then
  echo "Bitte mit sudo ausführen: sudo ./setup-server.sh"
  exit 1
fi

FRITZ_STROM_USER="${SUDO_USER:-$USER}"
FRITZ_STROM_DIR="/opt/fritz-strom"
SERVER_IP="192.168.178.123"
GATEWAY="192.168.178.1"
DNS="45.90.28.54,45.90.30.54"
INTERFACE=""

echo "=== Fritz-Strom Server Setup ==="
echo ""

# -------------------------------------------------------
# 1. System aktualisieren
# -------------------------------------------------------
echo "[1/6] System aktualisieren..."
apt-get update && apt-get upgrade -y

# -------------------------------------------------------
# 2. Basis-Pakete installieren
# -------------------------------------------------------
echo "[2/6] Basis-Pakete installieren..."
apt-get install -y \
  ca-certificates \
  curl \
  gnupg \
  git \
  htop \
  net-tools

# -------------------------------------------------------
# 3. Docker installieren
# -------------------------------------------------------
echo "[3/6] Docker installieren..."
if ! command -v docker &> /dev/null; then
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  echo \
    "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu \
    $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
    tee /etc/apt/sources.list.d/docker.list > /dev/null
  apt-get update
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  usermod -aG docker "$FRITZ_STROM_USER"
  echo "Docker installiert. User '$FRITZ_STROM_USER' zur docker-Gruppe hinzugefügt."
else
  echo "Docker bereits installiert, überspringe."
fi

# -------------------------------------------------------
# 4. Statische IP konfigurieren (Netplan)
# -------------------------------------------------------
echo "[4/6] Statische IP konfigurieren..."

# Aktives Netzwerk-Interface finden
INTERFACE=$(ip -o -4 route show to default | awk '{print $5}' | head -1)

if [ -z "$INTERFACE" ]; then
  echo "WARNUNG: Kein aktives Netzwerk-Interface gefunden. Statische IP muss manuell konfiguriert werden."
else
  cat > /etc/netplan/99-static.yaml <<EOF
network:
  version: 2
  ethernets:
    ${INTERFACE}:
      dhcp4: false
      addresses:
        - ${SERVER_IP}/24
      routes:
        - to: default
          via: ${GATEWAY}
      nameservers:
        addresses: [${DNS}]
EOF
  chmod 600 /etc/netplan/99-static.yaml
  echo "Netplan konfiguriert: ${SERVER_IP} auf ${INTERFACE}"
  echo "HINWEIS: 'sudo netplan apply' wird am Ende ausgeführt."
fi

# -------------------------------------------------------
# 5. Fritz-Strom Projekt klonen / kopieren
# -------------------------------------------------------
echo "[5/6] Fritz-Strom einrichten..."
if [ ! -d "$FRITZ_STROM_DIR" ]; then
  mkdir -p "$FRITZ_STROM_DIR"
  chown "$FRITZ_STROM_USER":"$FRITZ_STROM_USER" "$FRITZ_STROM_DIR"
  echo "Verzeichnis $FRITZ_STROM_DIR erstellt."
  echo ""
  echo "Projekt dorthin kopieren mit:"
  echo "  scp -r ./* ${FRITZ_STROM_USER}@${SERVER_IP}:${FRITZ_STROM_DIR}/"
  echo "Oder per Git klonen:"
  echo "  git clone <repo-url> ${FRITZ_STROM_DIR}"
else
  echo "$FRITZ_STROM_DIR existiert bereits."
fi

# Home Assistant Config-Verzeichnis anlegen
mkdir -p "$FRITZ_STROM_DIR/homeassistant/config"
chown -R "$FRITZ_STROM_USER":"$FRITZ_STROM_USER" "$FRITZ_STROM_DIR/homeassistant"

# -------------------------------------------------------
# 6. Firewall konfigurieren
# -------------------------------------------------------
echo "[6/6] Firewall konfigurieren..."
if command -v ufw &> /dev/null; then
  ufw allow 22/tcp     comment "SSH"
  ufw allow 8123/tcp   comment "Home Assistant"
  ufw allow 9090/tcp   comment "Fritz-Strom Dashboard"
  ufw allow 1883/tcp   comment "MQTT"
  ufw allow 5005/tcp   comment "TSUN Proxy"
  ufw allow 8127/tcp   comment "TSUN Proxy Web"
  ufw --force enable
  echo "Firewall konfiguriert."
else
  echo "ufw nicht installiert, Firewall übersprungen."
fi

# -------------------------------------------------------
# Zusammenfassung
# -------------------------------------------------------
echo ""
echo "==========================================="
echo "  Setup abgeschlossen!"
echo "==========================================="
echo ""
echo "  Server IP:        ${SERVER_IP}"
echo "  Docker:           $(docker --version 2>/dev/null || echo 'Neustart nötig')"
echo "  Fritz-Strom:      ${FRITZ_STROM_DIR}"
echo ""
echo "  Nächste Schritte:"
echo "  1. Neustart:      sudo reboot"
echo "  2. Projekt kopieren/klonen nach ${FRITZ_STROM_DIR}"
echo "  3. Stack starten: cd ${FRITZ_STROM_DIR} && docker compose up -d"
echo ""
echo "  Danach erreichbar:"
echo "  - Dashboard:       http://${SERVER_IP}:9090"
echo "  - Home Assistant:  http://${SERVER_IP}:8123"
echo "  - TSUN Proxy:      http://${SERVER_IP}:8127"
echo ""
echo "  WICHTIG: Nach dem Reboot die IP in der"
echo "  FRITZ!Box unter ${SERVER_IP} reservieren!"
echo "==========================================="

# Netplan anwenden (kann SSH-Verbindung unterbrechen!)
if [ -n "$INTERFACE" ] && [ -f /etc/netplan/99-static.yaml ]; then
  echo ""
  read -p "Statische IP jetzt aktivieren? SSH-Verbindung kann abbrechen! [j/N] " -n 1 -r
  echo
  if [[ $REPLY =~ ^[jJyY]$ ]]; then
    netplan apply
    echo "Netplan angewendet. Neue IP: ${SERVER_IP}"
  else
    echo "Netplan nicht angewendet. Manuell mit 'sudo netplan apply' aktivieren."
  fi
fi
