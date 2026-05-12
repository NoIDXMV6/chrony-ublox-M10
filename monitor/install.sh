# Установка NTP Monitor

## Быстрая установка (рекомендуется)

Склонируйте репозиторий и запустите инсталлятор от root:

git clone https://github.com/NoIDXMV6/chrony-ublox-M10.git
cd chrony-ublox-M10
sudo bash install.sh

Инсталлятор запросит:
- порт для ser2net (по умолчанию 2000)
- скорость UART (9600, 38400 или 115200)
- пароль администратора
- тип веб-сервера (Apache2 или nginx)

Он автоматически установит все зависимости, скопирует файлы, настроит права, веб-сервер и watchdog.  
После завершения вы получите URL мониторинга и пароль администратора.

## Ручная установка

### 1. Требования
- Raspberry Pi 4 (или аналог) с UART и GPIO
- GNSS-модуль u-blox M10 с PPS
- Веб-сервер (Apache + PHP или nginx + php-fpm)
- Пакеты: php-cli php-curl php-json gpsd chrony pps-tools sudo stty curl hostname
- Рекомендуется: jq, git

### 2. Копирование файлов

sudo mkdir -p /var/www/html/monitor
sudo cp *.php *.html *.js *.json *.css *.txt *.svg *.sh /var/www/html/monitor/
sudo cp -r leaflet/ /var/www/html/monitor/   # если Leaflet лежит локально
sudo chown -R www-data:www-data /var/www/html/monitor
sudo find /var/www/html/monitor -type d -exec chmod 755 {} \;
sudo find /var/www/html/monitor -type f -exec chmod 644 {} \;
sudo chmod 600 /var/www/html/monitor/config.json

### 3. Настройка прав sudo для www-data

Создайте /etc/sudoers.d/www-ntp-monitor со следующим содержимым:

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

Установите права:

sudo chmod 440 /etc/sudoers.d/www-ntp-monitor
sudo visudo -c   # проверить синтаксис

Добавьте www-data в группу dialout (для доступа к UART):

sudo usermod -aG dialout www-data

### 4. Пароль администратора

Выполните скрипт setup_auth.sh (или создайте хеш вручную):

cd /var/www/html/monitor
sudo bash setup_auth.sh

При запросе введите пароль – он будет сохранён в .monitor_pass.

### 5. Настройка веб-сервера

#### Apache

sudo a2enmod php8.2   # или ваша версия PHP
sudo cat > /etc/apache2/conf-available/ntp-monitor.conf <<'EOF'
<Directory /var/www/html/monitor>
    Options -Indexes +FollowSymLinks
    AllowOverride None
    Require all granted
</Directory>
Alias /monitor /var/www/html/monitor
EOF
sudo a2enconf ntp-monitor.conf
sudo systemctl reload apache2

#### nginx

Создайте конфиг /etc/nginx/sites-available/ntp-monitor:

server {
    listen 80;
    server_name _;
    root /var/www/html/monitor;
    index index.html;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
}

Включите сайт:

sudo ln -s /etc/nginx/sites-available/ntp-monitor /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

### 6. Настройка watchdog (опционально)

Скопируйте скрипт и сервис из папки watchdog:

sudo cp watchdog/ntp-watchdog.sh /usr/local/bin/
sudo chmod +x /usr/local/bin/ntp-watchdog.sh
sudo cp watchdog/ntp-watchdog.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ntp-watchdog.service

Логи: journalctl -u ntp-watchdog -f

### 7. Первый вход

Откройте в браузере http://<IP_Raspberry_Pi>/monitor/  
Для управления сервисами потребуется пароль, заданный на шаге 4.

## Права для gpsd (если требуется)

sudo usermod -aG dialout www-data
sudo systemctl restart php-fpm nginx  # или apache2
