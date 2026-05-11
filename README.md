# Stratum 1 NTP Сервер на Raspberry Pi 4

**Платформа:** Raspberry Pi 4 · Armbian Trixie (Debian 13) · ядро 6.x  
**GNSS модуль:** QUESCAN UBX-M10050-KB (u-blox M10) с PPS · [Купить на AliExpress](https://ali.click/egbg811)

**Точность:** ~150–300 нс (PPS-дисциплинированный источник) · **Stratum:** 1

**Мониторинг:** 🌐 [Веб-интерфейс](#веб-интерфейс-мониторинга) в реальном времени + REST API

---

## 📋 Содержание

- [Быстрый старт](#быстрый-старт) — 5 минут до рабочей системы
- [Схема подключения](#схема-подключения)
- [Использование скриптов](#использование-скриптов)
- [Веб-интерфейс мониторинга](#веб-интерфейс-мониторинга) ⭐ NEW
- [Конфигурация сервера](#конфигурация-сервера)
- [Настройка клиентов](#настройка-клиентов)
- [Проверка и мониторинг](#проверка-и-мониторинг)
- [Диагностика](#диагностика)

---

## 🚀 Быстрый старт

### За 5 минут

```bash
# 1. На рабочем компьютере
scp setup_ntp_server.sh root@<IP_RPi>:/root/

# 2. На Raspberry Pi
ssh root@<IP_RPi>
sudo /root/setup_ntp_server.sh

# 3. Перезагрузиться (обязательно!)
sudo reboot

# 4. Через 2-3 минуты проверить
chronyc sources -v
chronyc tracking
```

**Результат:** Stratum 1 NTP сервер готов! ✅

---

## Схема подключения

```
Raspberry Pi 4          GNSS модуль
─────────────          ────────────
Pin  1 (3.3V)    ───►  VCC
Pin  6 (GND)     ───►  GND
Pin  7 (GPIO4)   ◄───  PPS
Pin  8 (GPIO14)  ───►  RX
Pin 10 (GPIO15)  ◄───  TX
```

| RPi пин # | Название    | Направление | Пин модуля |
|-----------|-------------|-------------|------------|
| 1         | 3.3V        | →           | VCC        |
| 6         | GND         | →           | GND        |
| **7**     | **GPIO4**   | **← PPS**   | **PPS**    |
| 8         | GPIO14 TXD  | → UART TX   | RX         |
| 10        | GPIO15 RXD  | ← UART RX   | TX         |

> ⚠️ **КРИТИЧНО:** Модуль питается от **3.3V**. Подключение к 5V сгорит модуль!  
> ⚠️ **Частая ошибка:** RX и TX часто путают. Если NMEA не идят — поменяй провода местами.

---

## Использование скриптов

### setup_ntp_server.sh — Автоматическая установка

**Что делает:**
- ✅ Резервное копирование конфигов
- ✅ Установка пакетов (gpsd, chrony, pps-tools)
- ✅ Конфигурация UART (отключение BT, освобождение от консоли)
- ✅ Настройка PPS оверлея на GPIO4
- ✅ Конфигурация gpsd для u-blox M10 (38400 baud)
- ✅ Настройка chrony как Stratum 1 сервера
- ✅ Создание systemd сервисов

**Запуск:**
```bash
sudo /root/setup_ntp_server.sh
```

**Параметры (в начале скрипта):**
```bash
PPS_GPIO_PIN=4                      # GPIO пин PPS
GPS_BAUDRATE=38400                 # Скорость UART
ALLOW_SUBNET="192.168.0.0/16"      # Разрешённая подсеть NTP
NTP_FALLBACK="pool 2.debian.pool.ntp.org iburst"
```

**Время:** ~2-3 минуты · **Требует перезагрузку:** ✅ Да

---

### diagnose_ntp.sh — Диагностика и исправление ⭐ NEW

**Назначение:** Проверить и автоматически исправить проблемы.

**11 проверок:**
1. Ядро и система
2. Конфиг загрузки (`config.txt`, `cmdline.txt`)
3. Устройства (`/dev/ttyAMA0`, `/dev/pps0`)
4. **Автоопределение baudrate** — тестирует и выбирает рабочий! 🎯
5. Сервис автоустановки baudrate
6. Статус сервисов (gpsd, chrony)
7. NMEA данные от GPS
8. PPS сигнал пульсирования
9. Права доступа (для веб-мониторинга)
10. Chrony синхронизация (Stratum, источник)
11. Итоговый отчёт

**Запуск:**
```bash
sudo /root/diagnose_ntp.sh
```

**Интерактивность:** Спрашивает `[y/N]` для каждого исправления.

**Время:** ~1-2 минуты · **Требует перезагрузку:** ❌ Нет

---

## 🌐 Веб-интерфейс мониторинга

**NEW!** Полнофункциональный веб-дашборд для мониторинга в реальном времени.

### Установка

```bash
# Копировать файлы мониторинга
sudo mkdir -p /var/www/html/monitor
sudo cp monitor/*.php monitor/*.html monitor/*.json monitor/*.css monitor/*.js monitor/*.txt monitor/*.svg /var/www/html/monitor/

# Права доступа
sudo chown -R www-data:www-data /var/www/html/monitor/
sudo chmod 755 /var/www/html/monitor
sudo chmod 644 /var/www/html/monitor/*.{php,html,json,css,js}

# Разрешения для www-data
sudo cp monitor/www-ntp-monitor /etc/sudoers.d/www-ntp-monitor
sudo chmod 440 /etc/sudoers.d/www-ntp-monitor
sudo visudo -c  # проверить синтаксис

# Групповые разрешения для www-data
sudo usermod -aG dialout www-data   # UART доступ
sudo usermod -aG gpio www-data      # GPIO доступ
```

### Использование

Открыть в браузере: `http://<IP_RPi>/monitor/`

**Функции:**
- 📊 **Dashboard** — метрики в реальном времени (Stratum, смещение, RMS offset, частота)
- 📡 **GPS/GNSS** — режим фикса, координаты, спутники (2D/3D), DOP значения
- 🛰️ **Карта неба (Skyplot)** — азимут и возвышение спутников в полярной проекции
- 📊 **Гистограмма SNR** — уровень сигнала спутников по типам (GPS, GLONASS, Galileo и т.д.)
- 📈 **График смещения** — история синхронизации (последние ~300 точек)
- 🗺️ **Карта** — GPS координаты на OpenStreetMap (если включена)
- 🔧 **Управление** — перезагрузка gpsd/chrony, makestep, switch mode
- 🔍 **Диагностика** — NMEA данные, параметры UART, информация о портах
- 📋 **Статистика** — sourcestats (NP/NR/Span/Frequency), клиенты, системные ресурсы
- 🌓 **Темы** — светлая и тёмная (автоматическое переключение по времени)

### Конфигурация мониторинга

Отредактировать `/var/www/html/monitor/config.json`:

```json
{
  "monitor": {
    "refresh_interval": 15,      // сек между обновлениями API (15-30 рекомендуется)
    "theme": "auto",             // "dark" | "light" | "auto"
    "theme_dark_from": "20:00",  // время переключения в тёмную тему
    "theme_light_from": "07:00", // время переключения в светлую тему
    "title": "NTP Monitor"       // заголовок страницы
  },
  "server": {
    "hostname": "",              // автоматическое определение если пусто
    "ntp_port": 123,             // порт NTP
    "allow_subnet": "192.168.0.0/16"  // для ограничения доступа
  },
  "gnss": {
    "device": "/dev/ttyAMA0",    // UART устройство GPS
    "pps_device": "/dev/pps0",   // PPS сигнал
    "baudrate": 38400,           // ДОЛЖЕН совпадать с модулем! (9600, 38400)
    "pps_gpio": 4                // GPIO пин для PPS
  },
  "map": {
    "enabled": true,             // показывать ли карту
    "zoom": 14,                  // уровень зума
    "tile_server": "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
  },
  "telegram": {
    "enabled": false,            // включить оповещения (опционально)
    "bot_token": "YOUR_TOKEN",   // Telegram Bot API token
    "chat_id": "YOUR_CHAT_ID",   // chat ID для отправки сообщений
    "proxy_enabled": false,      // использовать ли прокси
    "alerts": {
      "gpsd_down": true,         // алерт если gpsd упал
      "chrony_down": true,       // алерт если chrony упал
      "pps_lost": true,          // алерт если нет PPS
      "stratum_change": true,    // алерт при изменении stratum
      "offset_threshold_ms": 10  // алерт если offset > 10мс
    }
  }
}
```

### REST API

**Получить все метрики:**
```bash
curl http://192.168.1.100/monitor/api.php | jq .
```

**Только отслеживание (tracking):**
```bash
curl http://192.168.1.100/monitor/api.php | jq .tracking
```

**Выполнить действие:**
```bash
curl "http://192.168.1.100/monitor/action.php?action=makestep"
curl "http://192.168.1.100/monitor/action.php?action=raw_port"
curl "http://192.168.1.100/monitor/action.php?action=restart_gpsd"
curl "http://192.168.1.100/monitor/action.php?action=restart_chrony"
curl "http://192.168.1.100/monitor/action.php?action=port_info"
```

**Поддерживаемые actions:**
- `makestep` — принудительная синхронизация часов
- `restart_gpsd` — перезагрузка GPS демона
- `restart_chrony` — перезагрузка NTP демона
- `raw_port` — чтение NMEA данных с UART
- `port_info` — параметры портов и процессы
- `offset_history` — история смещения

---

## Конфигурация сервера

### 1. `/boot/firmware/config.txt` — Конфиг RPi

```ini
# UART на GPIO14/15 (вместо консоли)
enable_uart=1

# Отключить Bluetooth (освобождает UART)
dtoverlay=disable-bt

# PPS на GPIO4 (пин 7)
dtoverlay=pps-gpio,gpiopin=4
```

### 2. `/etc/default/gpsd` — GPS демон

```bash
START_DAEMON="true"
USBAUTO="false"
DEVICES="/dev/ttyAMA0 /dev/pps0"
GPSD_OPTIONS="-n"               # read-only mode
```

### 3. `/etc/chrony/chrony.conf` — NTP сервер (ГЛАВНАЯ!)

```bash
# GPS NMEA через SHM (shared memory от gpsd)
refclock SHM 0 delay 0.5 refid GPS noselect

# PPS — основной высокоточный источник (Stratum 0)
refclock PPS /dev/pps0 lock GPS refid PPS precision 1e-7

# Интернет fallback (если нет GPS)
pool 2.debian.pool.ntp.org iburst

# Локальное время при потере GPS (fallback)
local stratum 10

# Разрешить синхронизацию для клиентов этой подсети
allow 192.168.0.0/16

# Быстрое выравнивание при старте (если смещение > 1 сек)
makestep 1.0 3

# Запись логов (опционально, для графика смещения)
log measurements statistics tracking
logdir /var/log/chrony
```

**Если нужно изменить подсеть:**
```bash
sudo nano /etc/chrony/chrony.conf
# Измени: allow 10.0.0.0/8
# Или:    allow 0/0  (всем, небезопасно)
sudo systemctl restart chrony
```

---

## Настройка клиентов

### Linux (Chrony)

```bash
sudo nano /etc/chrony/chrony.conf

# Добавить строки:
server 192.168.1.100 iburst prefer
pool 2.debian.pool.ntp.org iburst

# Перезагрузить
sudo systemctl restart chrony
chronyc sources -v
```

### Linux (ntpd)

```bash
sudo nano /etc/ntp.conf

# Добавить:
server 192.168.1.100 iburst prefer minpoll 4 maxpoll 6

sudo systemctl restart ntp
ntpq -p
```

### Windows

```powershell
# От администратора
w32tm /register
net start w32time
w32tm /config /manualpeerlist:"192.168.1.100,0x8" /syncfromflags:manual /reliable:YES /update
w32tm /resync
w32tm /query /status
```

### MikroTik (RouterOS)

```
/system ntp client set enabled=yes mode=unicast
/system ntp client servers add address=192.168.1.100
/system ntp client print
```

### macOS

```bash
sudo systemsetup -setnetworktimeserver 192.168.1.100
sudo systemsetup -setusingnetworktime on
ntpq -p
```

### Cisco IOS

```
ntp server 192.168.1.100 prefer
!
show ntp status
show ntp associations
```

---

## Проверка и мониторинг

### Обязательные проверки

**1. Устройства созданы:**
```bash
ls /dev/ttyAMA0 /dev/pps0
# crw-rw---- 1 root dialout
```

**2. NMEA данные идут:**
```bash
timeout 5 cat /dev/ttyAMA0
# $GPRMC, $GPGGA, $GPGSA — норма ✓
```

**3. GPS имеет фикс (через 1-2 мин):**
```bash
cgps -s
# MODE: 3D
# SAT: 8/13
```

**4. PPS пульсирует:**
```bash
sudo ppstest /dev/pps0
# source 0 - assert 1234567890.000000001 — норма ✓
```

**5. Chrony синхронизирован (через 2-3 мин):**
```bash
chronyc sources -v
# #* PPS — выбранный источник ✓
# Reach: 377 (все 8 попыток успешны)

chronyc tracking
# Stratum: 1 ✓
# System time: < 1 мкс ✓
# RMS offset: < 500 нс ✓
```

**6. Клиенты видят NTP сервер:**
```bash
# На клиенте
chronyc sources -v
# должен видеть нашу RPi
```

### Полезные команды

```bash
# Мониторинг Chrony
chronyc sources -v              # Источники (NTP, GPS, PPS)
chronyc sources -c              # Компактный формат
chronyc tracking                # Точность и смещение
chronyc activity                # Активные клиенты
chronyc clients                 # Подключённые клиенты

# GPS/NMEA
gpspipe -r -n 10               # 10 NMEA строк
cgps -s                         # Интерактивный GPS статус
cgps -c 10                      # 10 обновлений и выход

# PPS
sudo ppstest /dev/pps0
sudo ppstest -c 10 /dev/pps0   # 10 импульсов

# Логи
sudo journalctl -u gpsd -n 50
sudo journalctl -u chrony -n 50
sudo journalctl -u chrony -f   # Live режим

# Сеть
sudo netstat -an | grep 123     # Слушает ли порт 123
sudo ss -uln | grep 123
sudo tcpdump -i any udp port 123 -n
```

---

## Диагностика

### ❌ `/dev/pps0` не существует

**Причины:** Модуль не загружен, перезагрузка не выполнена, неправильный GPIO пин.

**Решение:**
```bash
# 1. Проверить конфиг
grep "dtoverlay=pps-gpio" /boot/firmware/config.txt
# Если нет, добавить и перезагрузиться

# 2. Запустить диагностику
sudo /root/diagnose_ntp.sh
# Спросит добавить нужные строки и перезагрузиться

# 3. После перезагрузки
ls /dev/pps0
```

### ❌ NMEA мусор или не идят

**Причины:** Неверный baudrate (обычно 9600 вместо 38400), RX/TX перепутаны, отсутствует питание.

**Решение:**
```bash
# Автоопределение baudrate
sudo /root/diagnose_ntp.sh
# Скрипт протестирует все скорости и выберет рабочую

# Или вручную
stty -F /dev/ttyAMA0 9600 raw && timeout 2 cat /dev/ttyAMA0
stty -F /dev/ttyAMA0 38400 raw && timeout 2 cat /dev/ttyAMA0
stty -F /dev/ttyAMA0 4800 raw && timeout 2 cat /dev/ttyAMA0

# Если RX/TX перепутаны:
# Поменяй провода GPIO14 (TX) и GPIO15 (RX) местами
```

### ❌ Chrony показывает Stratum 16 (не синхронизирован)

**Причины:** 
- GPS нет фикса (нет спутников)
- gpsd не пишет в SHM
- PPS не работает
- Неправильная конфигурация chrony

**Решение:**
```bash
# 1. Проверить GPS фикс
cgps -s
# MODE должно быть 3D (не No fix)
# Если No fix: проверить антенну (должна быть на открытом небе)

# 2. Проверить права gpsd для писания в SHM
id gpsd | grep _chrony
# Если нет:
sudo usermod -aG _chrony gpsd
sudo systemctl restart gpsd

# 3. Проверить PPS
sudo ppstest /dev/pps0 | head -5
# Должны быть строки с assert/clear

# 4. Перезагрузить сервисы и подождать 3-5 минут
sudo systemctl restart gpsd chrony
sleep 180 && chronyc tracking

# 5. Запустить диагностику
sudo /root/diagnose_ntp.sh
```

### ❌ Клиенты видят Stratum 16 (не синхронизированы)

**Причины:** Брандмауэр блокирует, RPi недоступна, chrony не слушает порт 123, неправильный IP.

**Решение:**
```bash
# На RPi
# 1. Проверить слушает ли порт
sudo netstat -uln | grep 123
# LISTEN 0.0.0.0:123 — OK

# 2. Разрешить в брандмауэре
sudo ufw allow 123/udp

# 3. Проверить конфиг chrony
grep "^allow" /etc/chrony/chrony.conf
# Должна быть строка с вашей подсетью

# 4. Перезагрузить chrony
sudo systemctl restart chrony

# На клиенте
# 1. Пингануть RPi
ping 192.168.1.100

# 2. Проверить что видит
chronyc sources -v
# Должен видеть RPi в списке

# 3. Может потребоваться 5-10 минут на синхронизацию
chronyc tracking
```

### ❌ Точность не лучше миллисекунды

**Причины:**
- Модуль имеет низкий SNR (слабый сигнал)
- Мало спутников (< 6)
- Плохой GDOP (< 2.0)
- Слишком близко к препятствиям

**Решение:**
```bash
# 1. Проверить сигнал
cgps -s
# Смотреть SNR у каждого спутника (> 30 dBHz)
# Смотреть GDOP (< 2.0, лучше < 1.5)

# 2. Проверить спутники на карте неба
# Открыть веб-интерфейс мониторинга:
# http://IP/monitor/
# Вкладка "Карта неба" должна показывать хороший покров

# 3. Переместить антенну выше (минимум 10° угла возвышения)
# Убедиться что нет препятствий (деревья, дома, провода)

# 4. Если снег или облачность - нормально, потребуется время
```

---

## Чек-лист установки

### Перед запуском setup_ntp_server.sh
- [ ] Armbian Trixie загружена на SD карту и доступна по SSH
- [ ] GNSS модуль правильно подключен (VCC=3.3V, GND, TX/RX на GPIO14/15, PPS на GPIO4)
- [ ] Антенна установлена с полным видом на небо
- [ ] Интернет доступен для загрузки пакетов

### После установки и перезагрузки
- [ ] Обе команды выполняются без ошибок
  ```bash
  ls /dev/ttyAMA0 /dev/pps0
  ```
- [ ] NMEA данные идят
  ```bash
  timeout 3 cat /dev/ttyAMA0 | head -3
  ```
- [ ] PPS импульсирует
  ```bash
  sudo ppstest /dev/pps0 | head -3
  ```

### Через 3-5 минут проверить синхронизацию
- [ ] `chronyc sources -v` показывает `#* PPS` (выбранный источник)
- [ ] `chronyc tracking` показывает:
  - `Stratum: 1`
  - `System time: < 1 мкс` (или наносекунды)
  - `RMS offset: < 500 нс`

### Тестирование сети
- [ ] Клиент пингует RPi: `ping <IP_RPi>` → Reply
- [ ] UDP 123 открыт: `nmap -u -p 123 <IP_RPi>` → open
- [ ] Клиент синхронизируется: `chronyc sources` → видит RPi

---

## 📁 Структура проекта

```
.
├── README.md                    # Главная документация (ты здесь)
├── LICENSE                      # MIT лицензия
├── setup_ntp_server.sh         # Автоматическая установка
├── diagnose_ntp.sh             # Диагностика и исправление ⭐ NEW
└── monitor/                     # Веб-интерфейс мониторинга ⭐ NEW
    ├── README.md               # Документация мониторинга
    ├── api.php                 # REST API (сбор метрик)
    ├── action.php              # Выполнение команд управления
    ├── index.html              # Веб-дашборд (HTML5)
    ├── monitor.js              # JavaScript логика
    ├── style.css               # Стили (светлая/тёмная тема)
    ├── config.json             # Конфигурация системы
    ├── www-ntp-monitor         # Sudoers файл для Apache/nginx
    └── favicon.svg             # Иконка
```

---

## 🔐 Безопасность

### Ограничить доступ к веб-мониторингу

**Apache:**
```apache
<Directory /var/www/html/monitor>
    Require ip 192.168.1.0/24
    # или
    Require host mynetwork.local
</Directory>
```

**nginx:**
```nginx
location /monitor/ {
    allow 192.168.1.0/24;
    allow 127.0.0.1;
    deny all;
}
```

### HTTPS (Let's Encrypt) — Бесплатный сертификат

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d ntp.example.com
# Будет продлеваться автоматически
```

### Разрешить NTP только для определённой подсети

```bash
sudo nano /etc/chrony/chrony.conf
# Изменить:
allow 192.168.1.0/24
# Или более строго:
allow 192.168.1.10
allow 192.168.1.11

sudo systemctl restart chrony
```

---

## 📊 Ожидаемый результат

После правильной установки и прогрева (~5-10 минут):

```bash
$ chronyc sources -v
MS Name/IP Address    Stratum Poll Reach LastRx Last sample
=============================================================
#? GPS                      0    4   377     2  +105ms[+105ms] +/- 251ms
#* PPS                      0    4   377     1  -159ns[+619ns] +/- 167ns
^- 91.189.94.4              2    6   377    33  -1ms[-1ms] +/- 65ms
^- time.google.com          2    6   377    40  +2ms[+2ms] +/- 51ms

$ chronyc tracking
Reference ID    : 50505300 (PPS)
Stratum         : 1
System time     : 0.000000159 seconds fast of NTP time
RMS offset      : 0.000000098 seconds
Frequency       : -0.123 ppm fast
Residual freq   : -0.001 ppm
Skew            : 0.140 ppm
Root delay      : 0.000159 seconds
Root dispersion : 0.000187 seconds
```

---

## 📞 Поддержка и помощь

### Если что-то не работает

```bash
# 1. Запусти диагностику
sudo /root/diagnose_ntp.sh

# 2. Собери логи
sudo journalctl -u gpsd -n 100 > ~/gpsd.log
sudo journalctl -u chrony -n 100 > ~/chrony.log
chronyc sources -v > ~/chrony_sources.txt

# 3. Откройте issue на GitHub
# Приложи логи и опиши что происходит
```

**Ссылки:**
- 🐛 [GitHub Issues](https://github.com/NoIDXMV6/chrony-ublox-M10/issues)
- 📖 [Chrony Documentation](https://chrony.tuxfamily.org/documentation.html)
- 📖 [gpsd Documentation](https://gpsd.gitlab.io/gpsd/)
- 📖 [u-blox M10 Datasheet](https://www.u-blox.com/en/product/zed-m10-module)
- 🎥 [Raspberry Pi GPIO Reference](https://www.raspberrypi.com/documentation/computers/raspberry-pi.html)

---

## 📄 Лицензия

MIT License — используй как угодно в своих проектах.

---

**Версия:** 2.2.0 · **Обновлено:** 2026-05-11  
**Автор:** NoIDXMV6 · **Язык:** Русский

⭐ Если помогла — поставь звезду на [GitHub](https://github.com/NoIDXMV6/chrony-ublox-M10)!
