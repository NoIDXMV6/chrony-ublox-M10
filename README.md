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
> ⚠️ **Частая ошибка:** RX и TX часто путают. Если NMEA не идут — поменяй провода местами.

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
sudo cp monitor/*.php monitor/*.html monitor/*.json monitor/*.css /var/www/html/monitor/

# Права доступа
sudo chown -R www-data:www-data /var/www/html/monitor/
sudo chmod 755 /var/www/html/monitor

# Разрешения для www-data
sudo cp monitor/www-ntp-monitor /etc/sudoers.d/www-ntp-monitor
sudo chmod 440 /etc/sudoers.d/www-ntp-monitor
```

### Использование

Открыть в браузере: `http://<IP_RPi>/monitor/`

**Функции:**
- 📊 **Dashboard** — метрики в реальном времени (Stratum, смещение, RMS offset)
- 📡 **GPS/GNSS** — фикс, координаты, спутники (2D/3D)
- 🛰️ **Карта неба (Skyplot)** — азимут и возвышение спутников
- 📈 **График смещения** — история синхронизации
- 🗺️ **Карта** — GPS координаты на OpenStreetMap
- 🔧 **Управление** — перезагрузка gpsd/chrony, makestep
- 🔍 **Диагностика** — NMEA данные, параметры UART
- 📋 **Статистика** — sourcestats, клиенты, системные ресурсы

### Конфигурация мониторинга

Отредактировать `/var/www/html/monitor/config.json`:

```json
{
  "monitor": {
    "refresh_interval": 15,      // сек между обновлениями API
    "theme": "auto",             // "dark" | "light" | "auto"
    "theme_dark_from": "20:00",  // время переключения темы
    "theme_light_from": "07:00"
  },
  "gnss": {
    "device": "/dev/ttyAMA0",
    "pps_device": "/dev/pps0",
    "baudrate": 38400,           // должен совпадать с GPS модулем
    "pps_gpio": 4
  },
  "telegram": {
    "enabled": false,            // включить оповещения (опционально)
    "bot_token": "YOUR_TOKEN",
    "chat_id": "YOUR_CHAT_ID",
    "alerts": {
      "gpsd_down": true,
      "chrony_down": true,
      "pps_lost": true,
      "offset_threshold_ms": 10
    }
  }
}
```

### REST API

```bash
# Получить все метрики
curl http://192.168.1.100/monitor/api.php | jq .

# Только tracking
curl http://192.168.1.100/monitor/api.php | jq .tracking

# Выполнить действие
curl "http://192.168.1.100/monitor/action.php?action=makestep"
curl "http://192.168.1.100/monitor/action.php?action=raw_port"
curl "http://192.168.1.100/monitor/action.php?action=restart_gpsd"
```

---

## Конфигурация сервера

### 1. `/boot/firmware/config.txt` — Конфиг RPi

```ini
enable_uart=1                    # UART на GPIO14/15
dtoverlay=disable-bt             # BT не занимает UART
dtoverlay=pps-gpio,gpiopin=4    # PPS на GPIO4 (пин 7)
```

### 2. `/etc/default/gpsd` — GPS демон

```bash
START_DAEMON="true"
USBAUTO="false"
DEVICES="/dev/ttyAMA0 /dev/pps0"
GPSD_OPTIONS="-n"               # readonly
```

### 3. `/etc/chrony/chrony.conf` — NTP сервер (ГЛАВНАЯ!)

```bash
# GPS NMEA через SHM
refclock SHM 0 delay 0.5 refid GPS noselect

# PPS — основной высокоточный источник
refclock PPS /dev/pps0 lock GPS refid PPS precision 1e-7

# Интернет fallback
pool 2.debian.pool.ntp.org iburst

# Локальное время при потере GPS
local stratum 10

# Разрешить клиентам
allow 192.168.0.0/16

# Быстрое выравнивание при старте
makestep 1.0 3
```

**Если нужно изменить подсеть:**
```bash
sudo nano /etc/chrony/chrony.conf
# Измени: allow 10.0.0.0/8
sudo systemctl restart chrony
```

---

## Настройка клиентов

### Linux (Chrony)

```bash
sudo nano /etc/chrony/chrony.conf

# Добавь:
server 192.168.1.100 iburst prefer
pool 2.debian.pool.ntp.org iburst

# Перезагрузи
sudo systemctl restart chrony
chronyc sources -v
```

### Windows

```cmd
REM От администратора
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

---

## Проверка и мониторинг

### Обязательные проверки

**1. Устройства:**
```bash
ls /dev/ttyAMA0 /dev/pps0
```

**2. NMEA данные:**
```bash
timeout 5 cat /dev/ttyAMA0
# $GPRMC, $GPGGA, $GPGSA — норма ✓
```

**3. GPS фикс:**
```bash
cgps -s
# MODE: 3D, SAT: 8/13 — норма ✓
```

**4. PPS пульсирует:**
```bash
sudo ppstest /dev/pps0
# assert 1234567890.000... — норма ✓
```

**5. Chrony синхронизирован (через 2-3 мин):**
```bash
chronyc sources -v
# #* PPS — выбранный источник ✓

chronyc tracking
# Stratum: 1 ✓
# System time: < 1 мкс ✓
```

**6. Клиенты синхронизированы:**
```bash
# На клиенте
chronyc sources -v
# должен видеть нашу RPi как source ✓
```

### Полезные команды

```bash
# Мониторинг в реальном времени
chronyc sources -v              # Источники
chronyc tracking                # Точность
chronyc activity                # Активные клиенты
chronyc clients                 # Список подключённых

# GPS/NMEA
gpspipe -r -n 10               # 10 NMEA строк
cgps -s                         # Интерактивный GPS статус

# PPS
sudo ppstest /dev/pps0
sudo ppstest -c 10 /dev/pps0   # 10 импульсов

# Логи
sudo journalctl -u gpsd -n 50
sudo journalctl -u chrony -n 50
sudo journalctl -u chrony -f   # Живой просмотр

# Сеть
sudo netstat -an | grep 123
sudo tcpdump -i any udp port 123
```

---

## Диагностика

### ❌ `/dev/pps0` не существует

**Причины:** Модуль не загружен, перезагрузка не выполнена.

**Решение:**
```bash
sudo /root/diagnose_ntp.sh
# Скрипт предложит добавить dtoverlay=pps-gpio
sudo reboot
```

### ❌ NMEA мусор или не идут

**Причины:** Неверный baudrate, RX/TX перепутаны.

**Решение:**
```bash
sudo /root/diagnose_ntp.sh
# Скрипт автоопределит правильный baudrate и исправит
```

### ❌ Chrony показывает Stratum 16

**Причины:** GPS нет фикса, gpsd не пишет в SHM.

**Решение:**
```bash
# 1. Проверить GPS
cgps -s
# MODE должно быть 3D

# 2. Проверить права gpsd
id gpsd | grep _chrony
# Если нет:
sudo usermod -aG _chrony gpsd
sudo systemctl restart gpsd

# 3. Подождать 3-5 минут
```

### ❌ Клиенты видят Stratum 16

**Причины:** Брандмауэр, RPi недоступна, chrony не слушает.

**Решение:**
```bash
# На RPi
sudo ufw allow 123/udp
sudo netstat -an | grep 123
# LISTEN 0.0.0.0:123

# На клиенте
ping <IP_RPi>
chronyc sources -v
```

---

## Чек-лист установки

**Перед запуском setup_ntp_server.sh:**
- [ ] Armbian загружена и доступна по SSH
- [ ] GNSS модуль подключен (VCC, GND, TX, RX, PPS)
- [ ] Антенна установлена с видом на небо

**После установки:**
- [ ] Система перезагружена
- [ ] `/dev/ttyAMA0` существует
- [ ] `/dev/pps0` существует
- [ ] NMEA данные идут
- [ ] GPS имеет 3D фикс

**Через 5-10 минут:**
- [ ] `chronyc sources -v` показывает `#* PPS`
- [ ] `chronyc tracking` показывает `Stratum: 1`
- [ ] RMS offset < 1 мкс

**Сеть:**
- [ ] Клиенты пингуют RPi
- [ ] UDP 123 открыт
- [ ] Клиенты синхронизируются с RPi

---

## 📊 Ожидаемый результат

```bash
$ chronyc sources -v
MS Name/IP address    Stratum Poll Reach LastRx Last sample
================================================================
#? GPS                      0    4   377     2  +105ms[+105ms] +/- 251ms
#* PPS                      0    4   377     1  -159ns[+619ns] +/- 167ns
^- pool.ntp.org             2    6   377    33  -1ms[-1ms] +/- 65ms

$ chronyc tracking
Reference ID    : 50505300 (PPS)
Stratum         : 1
System time     : 0.000000159 seconds fast of NTP time
RMS offset      : 0.000000098 seconds
```

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
    ├── action.php              # Выполнение команд
    ├── index.html              # Веб-дашборд
    ├── style.css               # Стили (светлая/тёмная тема)
    ├── monitor.js              # JavaScript логика
    ├── config.json             # Конфигурация
    └── www-ntp-monitor         # Sudoers файл для Apache/nginx
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
    deny all;
}
```

### HTTPS (Let's Encrypt)

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d ntp.example.com
# Автоматически обновляется
```

---

## 📞 Поддержка

**Если что-то не работает:**

```bash
# 1. Запусти диагностику
sudo /root/diagnose_ntp.sh

# 2. Сохрани логи
sudo journalctl -u gpsd -n 100 > ~/gpsd.log
sudo journalctl -u chrony -n 100 > ~/chrony.log
chronyc sources -v > ~/chrony_sources.txt

# 3. Откройте issue на GitHub с логами
```

**Ссылки:**
- 🐛 [GitHub Issues](https://github.com/NoIDXMV6/chrony-ublox-M10/issues)
- 📖 [Документация Chrony](https://chrony.tuxfamily.org/)
- 📖 [Документация gpsd](https://gpsd.gitlab.io/gpsd/)
- 📖 [u-blox M10 datasheet](https://www.u-blox.com/en/product/zed-m10-module)

---

## 📄 Лицензия

MIT License — используй как угодно в своих проектах.

---

**Версия:** 2.1.0 · **Обновлено:** 2026-05-10  
**Автор:** NoIDXMV6 · **Язык:** Русский

⭐ Если помогла — поставь звезду на [GitHub](https://github.com/NoIDXMV6/chrony-ublox-M10)!
