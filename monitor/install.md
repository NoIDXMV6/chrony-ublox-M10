# Установка NTP Monitor

## Файлы

- `index.html` — фронтенд (статика, обновляется каждые 15 с через AJAX)
- `api.php`    — бэкенд (возвращает JSON со статистикой chrony + gpsd)

## Установка

### 1. Установить nginx + php-fpm

```bash
sudo apt install nginx php-fpm -y
```

### 2. Скопировать файлы

```bash
sudo mkdir -p /var/www/ntp-monitor
sudo cp index.html api.php /var/www/ntp-monitor/
sudo chown -R www-data:www-data /var/www/ntp-monitor
```

### 3. Настроить sudo для www-data

```bash
sudo nano /etc/sudoers.d/chrony-web
```

Содержимое:
```
www-data ALL=(ALL) NOPASSWD: /usr/bin/chronyc
```

### 4. Настроить nginx

```bash
sudo nano /etc/nginx/sites-available/ntp-monitor
```

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/ntp-monitor;
    index index.html;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/ntp-monitor /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 5. Найти правильный сокет php-fpm

```bash
ls /run/php/
# Если php8.2-fpm.sock — замени в конфиге nginx
```

### 6. Открыть в браузере

```
http://<IP_Raspberry_Pi>/
```

## Права для gpsd

Если gpspipe работает только от root — добавь www-data в группу:

```bash
sudo usermod -aG dialout www-data
sudo systemctl restart php-fpm nginx
```