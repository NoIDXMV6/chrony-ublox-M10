#!/bin/bash
# ============================================================
# NTP Monitor — универсальный инсталлятор
# ============================================================
set -e

# --- цвета ---
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'

# --- проверка прав ---
if [[ $EUID -ne 0 ]]; then
    echo -e "${RED}Запустите от root (sudo $0)${NC}"; exit 1
fi

# --- определение путей ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MONITOR_DIR="/var/www/html/monitor"      # можно изменить
WATCHDOG_DIR="$SCRIPT_DIR/watchdog"

# --- приветствие ---
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}   NTP Monitor Installer v2.2${NC}"
echo -e "${GREEN}========================================${NC}"
echo

# --- 1. Сбор параметров ---
read -p "Введите порт для ser2net (по умолчанию 2000): " SER2_PORT
SER2_PORT=${SER2_PORT:-2000}

read -p "Введите скорость UART по умолчанию (9600/38400/115200): " BAUD
BAUD=${BAUD:-9600}

read -p "Введите пароль для панели управления: " ADMIN_PASS
if [[ -z "$ADMIN_PASS" ]]; then
    ADMIN_PASS=$(openssl rand -base64 12 2>/dev/null || head -c12 /dev/urandom | base64)
    echo -e "${YELLOW}Сгенерирован случайный пароль: ${GREEN}$ADMIN_PASS${NC}"
fi

echo "Выберите веб-сервер:"
echo "  1) Apache2"
echo "  2) Nginx"
read -p "Ваш выбор (1/2): " WEB_CHOICE
if [[ "$WEB_CHOICE" == "2" ]]; then
    WEB_SERVER="nginx"
else
    WEB_SERVER="apache2"
fi

# --- 2. Проверка и установка зависимостей ---
echo -e "${YELLOW}[1/6] Проверка зависимостей...${NC}"
PKGS="php-cli php-curl php-json sudo coreutils util-linux gpsd chrony pps-tools"
WEB_PKG=""
if [[ "$WEB_SERVER" == "apache2" ]]; then
    WEB_PKG="apache2 libapache2-mod-php"
else
    WEB_PKG="nginx php-fpm"
fi

apt-get update -qq
apt-get install -y -qq $PKGS $WEB_PKG stty curl hostname &>/dev/null || {
    echo -e "${RED}Ошибка установки пакетов${NC}"; exit 1
}
echo -e "${GREEN}Зависимости установлены.${NC}"

# --- 3. Копирование файлов и настройка ---
echo -e "${YELLOW}[2/6] Копирование файлов...${NC}"
mkdir -p "$MONITOR_DIR"
cp "$SCRIPT_DIR/index.html" "$MONITOR_DIR/"
cp "$SCRIPT_DIR/monitor.js" "$MONITOR_DIR/"
cp "$SCRIPT_DIR/api.php" "$MONITOR_DIR/"
cp "$SCRIPT_DIR/action.php" "$MONITOR_DIR/"
cp "$SCRIPT_DIR/config.json" "$MONITOR_DIR/"
cp "$SCRIPT_DIR/style.css" "$MONITOR_DIR/"
cp "$SCRIPT_DIR/ru_locale.txt" "$MONITOR_DIR/"
cp "$SCRIPT_DIR/en_locale.txt" "$MONITOR_DIR/"
cp "$SCRIPT_DIR/favicon.svg" "$MONITOR_DIR/" 2>/dev/null || true

# Leaflet (если локальный)
if [[ -d "$SCRIPT_DIR/leaflet" ]]; then
    mkdir -p "$MONITOR_DIR/leaflet"
    cp "$SCRIPT_DIR/leaflet/"* "$MONITOR_DIR/leaflet/"
fi

# Установка прав
echo -e "${YELLOW}[3/6] Настройка прав...${NC}"
chown -R www-data:www-data "$MONITOR_DIR"
find "$MONITOR_DIR" -type d -exec chmod 755 {} \;
find "$MONITOR_DIR" -type f -exec chmod 644 {} \;
chmod 600 "$MONITOR_DIR/config.json"

# Пароль администратора
echo -e "${YELLOW}[4/6] Создание пароля администратора...${NC}"
HASH=$(php -r "echo password_hash('$ADMIN_PASS', PASSWORD_BCRYPT);")
echo "$HASH" > "$MONITOR_DIR/.monitor_pass"
chown www-data:www-data "$MONITOR_DIR/.monitor_pass"
chmod 400 "$MONITOR_DIR/.monitor_pass"

# Обновление config.json (подставим параметры)
TMP=$(mktemp)
jq --arg port "$SER2_PORT" --argjson baud "$BAUD" \
   '.ser2net.port = ($port | tonumber) | .gnss.baudrate = $baud' \
   "$MONITOR_DIR/config.json" > "$TMP" && mv "$TMP" "$MONITOR_DIR/config.json"
chmod 600 "$MONITOR_DIR/config.json"

# sudoers
echo -e "${YELLOW}[5/6] Настройка sudoers...${NC}"
cp "$SCRIPT_DIR/www-ntp-monitor" /etc/sudoers.d/www-ntp-monitor 2>/dev/null || {
    cat > /etc/sudoers.d/www-ntp-monitor <<EOF
www-data ALL=(ALL) NOPASSWD: /usr/bin/chronyc
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start gpsd
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop gpsd
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart gpsd
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start gpsd.socket
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop gpsd.socket
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start chrony
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop chrony
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart chrony
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start ser2net
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop ser2net
www-data ALL=(ALL) NOPASSWD: /bin/systemctl reload ntp-watchdog.service
www-data ALL=(ALL) NOPASSWD: /bin/stty
www-data ALL=(ALL) NOPASSWD: /usr/bin/lsof
www-data ALL=(ALL) NOPASSWD: /usr/local/bin/ntp-watchdog.sh --force-reset
EOF
}
chmod 440 /etc/sudoers.d/www-ntp-monitor

# Watchdog
echo -e "${YELLOW}[6/6] Установка watchdog...${NC}"
if [[ -f "$WATCHDOG_DIR/ntp-watchdog.sh" ]]; then
    cp "$WATCHDOG_DIR/ntp-watchdog.sh" /usr/local/bin/
    chmod +x /usr/local/bin/ntp-watchdog.sh
fi
if [[ -f "$WATCHDOG_DIR/ntp-watchdog.service" ]]; then
    cp "$WATCHDOG_DIR/ntp-watchdog.service" /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable --now ntp-watchdog.service
fi

# Веб-сервер
if [[ "$WEB_SERVER" == "apache2" ]]; then
    cat > /etc/apache2/conf-available/ntp-monitor.conf <<EOF
<Directory $MONITOR_DIR>
    Options -Indexes +FollowSymLinks
    AllowOverride None
    Require all granted
</Directory>
Alias /monitor $MONITOR_DIR
EOF
    a2enconf ntp-monitor.conf
    systemctl reload apache2
else
    cat > /etc/nginx/sites-available/ntp-monitor <<EOF
server {
    listen 80;
    server_name _;
    root $MONITOR_DIR;
    index index.html;
    location / {
        try_files \$uri \$uri/ =404;
    }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
}
EOF
    ln -sf /etc/nginx/sites-available/ntp-monitor /etc/nginx/sites-enabled/
    nginx -t && systemctl reload nginx
fi

# --- финальная проверка ---
IP=$(hostname -I | awk '{print $1}')
echo
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}   Установка завершена!${NC}"
echo -e "${GREEN}========================================${NC}"
echo -e "Веб-интерфейс:    ${BLUE}http://${IP}/monitor/${NC}"
echo -e "Пароль администратора: ${GREEN}${ADMIN_PASS}${NC}"
echo -e "${YELLOW}Сохраните пароль в надёжном месте!${NC}"
