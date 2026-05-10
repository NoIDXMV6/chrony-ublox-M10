# Stratum 1 NTP Сервер на Raspberry Pi 4

**Платформа:** Raspberry Pi 4 · Armbian Trixie (Debian 13) · ядро 6.x  
**GNSS модуль:** QUESCAN UBX-M10050-KB (u-blox M10) с PPS · [Купить на AliExpress](https://ali.click/egbg811)

**Точность:** ~150–300 нс (PPS-дисциплинированный источник) · **Stratum:** 1

---

## 📋 Содержание

- [Схема подключения](#схема-подключения)
- [Требования](#требования)
- [Быстрый старт](#быстрый-старт)
- [Использование скриптов](#использование-скриптов)
- [Конфигурация сервера](#конфигурация-сервера)
- [Настройка клиентов](#настройка-клиентов)
- [Мониторинг и проверка](#мониторинг-и-проверка)
- [Диагностика и troubleshooting](#диагностика-и-troubleshooting)
- [Полезные команды](#полезные-команды)

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

> ⚠️ **КРИТИЧНО:** Модуль питается от **3.3V**. Подключение к 5V (пины 2, 4) сгорит модуль!

> ⚠️ **Частая ошибка:** RX и TX часто путают. Если NMEA не идут — поменяй провода местами.

---

## Требования

### Оборудование
- **Raspberry Pi 4** с Armbian Trixie 64-bit
- **GNSS модуль** с PPS выходом (u-blox M10, M9N или аналог)
- **Антенна GPS** с видом на небо (минимум 10° угла возвышения)
- **Провода** дюпон 3.3V, GND, RX/TX/PPS (5 шт)
- **Кабель USB-UART** для начальной конфигурации (опционально)

### Программное обеспечение
```bash
# Основное
Linux kernel 5.10+ (с поддержкой PPS-GPIO)
Armbian Trixie 24.x
Apache 2.4 или nginx
PHP 7.4+

# Пакеты (устанавливаются автоматически скриптом)
- gpsd и gpsd-clients
- chrony
- pps-tools
```

---

## Быстрый старт

### 1️⃣ Подготовка Raspberry Pi

Если Armbian не установлена, загрузи образ:
```bash
# На рабочем компьютере
wget https://www.armbian.com/download/[выбери модель RPi 4]
sudo dd if=Armbian-official-*.img of=/dev/sdX bs=4M status=progress
sync
```

Вставь SD карту в RPi 4, подключи питание и подожди 1-2 мин.

### 2️⃣ Подключение по SSH

```bash
# Узнай IP (в роутере или через ARP)
ssh root@<IP_RPi>
# пароль: 1234
```

### 3️⃣ Копирование и запуск скрипта установки

**Вариант A: Через SCP (рекомендуется)**
```bash
# На рабочем компьютере
scp setup_ntp_server.sh root@<IP_RPi>:/root/

# На RPi
ssh root@<IP_RPi>
chmod +x /root/setup_ntp_server.sh
sudo /root/setup_ntp_server.sh
```

**Вариант B: Через curl**
```bash
ssh root@<IP_RPi>
curl -fsSL https://your-server/setup_ntp_server.sh | sudo bash
```

### 4️⃣ Перезагрузка (ОБЯЗАТЕЛЬНО!)

Скрипт попросит перезагрузиться. **ПЕРЕЗАГРУЗКА НЕОБХОДИМА:**
- Отключение Bluetooth от UART
- Подключение PPS оверлея GPIO4
- Выход UART из режима консоли

```bash
sudo reboot
# Подожди 2-3 минуты
```

### 5️⃣ Проверка после перезагрузки

```bash
# Устройства присутствуют
ls /dev/ttyAMA0 /dev/pps0

# NMEA данные идут (Ctrl+C для выхода)
stty -F /dev/ttyAMA0 38400 raw && timeout 5 cat /dev/ttyAMA0

# GPS фикс и спутники
cgps -s

# Статус Chrony (через 2-3 мин)
chronyc sources -v
chronyc tracking
```

---

## Использование скриптов

### setup_ntp_server.sh — Полная установка

**Что делает (9 шагов):**

1. ✅ **Резервная копия** конфигов в `/root/ntp_setup_backup_YYYYMMDD_HHMMSS/`
2. ✅ **Установка пакетов:** `gpsd`, `chrony`, `pps-tools`
3. ✅ **Конфигурация `/boot/firmware/config.txt`:**
   - `enable_uart=1` — включить UART на GPIO14/15
   - `dtoverlay=disable-bt` — отключить BT от UART
   - `dtoverlay=pps-gpio,gpiopin=4` — PPS на GPIO4
4. ✅ **Освобождение UART от консоли:**
   - Удалить `console=serial0` из `cmdline.txt`
   - Отключить `serial-getty@ttyAMA0`
5. ✅ **Отключение Bluetooth:**
   - Disable `hciuart` и `bluetooth`
6. ✅ **Создание udev правил** для `/dev/pps0`
7. ✅ **Конфигурация gpsd:**
   - `/etc/default/gpsd` с UART 38400 baud
   - Добавление в группу `_chrony`
8. ✅ **Конфигурация chrony:**
   - GPS (NMEA через SHM) + PPS (высокоточный источник)
   - Stratum 1 конфигурация
   - Fallback на интернет серверы
9. ✅ **Включение в автозапуск** через systemctl

**Запуск:**
```bash
sudo /root/setup_ntp_server.sh
```

**Параметры (измени в начале скрипта):**
```bash
PPS_GPIO_PIN=4                      # GPIO пин PPS
GPS_BAUDRATE=38400                 # Скорость UART
ALLOW_SUBNET="192.168.0.0/16"      # Разрешённая подсеть
NTP_FALLBACK="pool 2.debian.pool.ntp.org iburst"  # Fallback серверы
```

**Время выполнения:** ~2-3 минуты  
**Требует перезагрузку:** ✅ Да

---

### diagnose_ntp.sh — Диагностика и исправление

**Назначение:** Проверить конфигурацию и автоматически исправить проблемы.

**11 проверок:**

1. ✅ **Ядро и система** — версия, архитектура, Armbian
2. ✅ **Конфиг загрузки** — `config.txt`, `cmdline.txt`, UART, PPS
3. ✅ **Устройства** — `/dev/ttyAMA0`, `/dev/pps0`
4. ✅ **Автоопределение baudrate** ⭐ — тестирует 9600, 38400, 4800... и выбирает рабочий
5. ✅ **Сервис baudrate** — создаёт `gps-baudrate.service` для гарантии
6. ✅ **Сервисы** — статус gpsd и chrony
7. ✅ **NMEA данные** — проверка что gpsd получает данные
8. ✅ **PPS сигнал** — тест пульсирования на `/dev/pps0`
9. ✅ **Права доступа** — www-data, sudoers для веб-мониторинга
10. ✅ **Chrony синхронизация** — Stratum, выбор источника, makestep
11. ✅ **Итоги** — кол-во найденных проблем и исправлено

**Запуск:**
```bash
sudo /root/diagnose_ntp.sh
```

**Интерактивность:** Для каждой проблемы спрашивает `[y/N]`:
```
  ✗ enable_uart=1 отсутствует в /boot/firmware/config.txt
  Добавить? [y/N]
```

**Пример вывода:**
```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  4. Определение baudrate GNSS модуля
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    Baudrate 9600: 0 NMEA строк
    Baudrate 38400: 12 NMEA строк     ✓ Найден!
  
  ✓ GNSS модуль отвечает на baudrate: 38400
  ✓ gpsd настроен на правильный baudrate
```

**Время выполнения:** ~1-2 минуты  
**Требует перезагрузку:** ❌ Нет (в зависимости от исправлений)

**Совет:** Запусти после первой перезагрузки, если что-то не работает.

---

## Конфигурация сервера

### 1. `/boot/firmware/config.txt` — Конфиг Raspberry Pi

Скрипт добавляет в конец:
```ini
# === NTP SERVER SETUP ===
enable_uart=1
dtoverlay=disable-bt
dtoverlay=pps-gpio,gpiopin=4
# === END NTP SERVER SETUP ===
```

**Значения:**
- `enable_uart=1` — включить UART (PL011) на GPIO14/15
- `dtoverlay=disable-bt` — отключить BT от основного UART (чтобы не конфликтовал с GPS)
- `dtoverlay=pps-gpio,gpiopin=4` — загрузить модуль PPS на GPIO4 (пин 7)

**Если нужно изменить GPIO пин:**
```bash
sudo nano /boot/firmware/config.txt
# Измени: dtoverlay=pps-gpio,gpiopin=17
sudo reboot
```

### 2. `/boot/firmware/cmdline.txt` — Параметры ядра

Скрипт **автоматически удаляет** `console=serial0,...` для освобождения UART.

**Проверка:**
```bash
cat /boot/firmware/cmdline.txt
# Не должно быть console=serial0 или console=ttyAMA0
```

### 3. `/etc/default/gpsd` — Конфигурация GPS демона

```bash
START_DAEMON="true"
USBAUTO="false"
DEVICES="/dev/ttyAMA0 /dev/pps0"
GPSD_OPTIONS="-n"
```

**Параметры:**
- `DEVICES="/dev/ttyAMA0 /dev/pps0"` — слушать UART GPS и PPS
- `GPSD_OPTIONS="-n"` — readonly (не менять конфиг модуля)

**Если нужно явно указать baudrate:**
```bash
sudo nano /etc/default/gpsd
# Измени: GPSD_OPTIONS="-n -s 38400"
sudo systemctl restart gpsd
```

### 4. `/etc/chrony/chrony.conf` — Конфигурация NTP (ГЛАВНАЯ!)

Скрипт создаёт полную конфигурацию для **Stratum 1**:

```bash
# GPS NMEA через gpsd SHM (shared memory)
refclock SHM 0 delay 0.5 refid GPS noselect

# PPS — основной высокоточный источник
refclock PPS /dev/pps0 lock GPS refid PPS precision 1e-7

# Интернет fallback (когда нет GPS)
pool 2.debian.pool.ntp.org iburst

# Локальное время при потере всех источников
local stratum 10

# Разрешить клиентам (подсеть)
allow 192.168.0.0/16

# Быстрое выравнивание при старте
makestep 1.0 3
```

**Важные параметры:**

| Параметр | Значение | Описание |
|----------|----------|---------|
| `refclock SHM 0` | GPS | NMEA время от gpsd |
| `refclock PPS /dev/pps0` | PPS | Пульсирующий сигнал (наносекунды) |
| `lock GPS` | на PPS | PPS привязана к GPS секунде |
| `precision 1e-7` | на PPS | Заявленная точность 100 нс |
| `noselect` | на GPS | GPS помечена дополнительным источником (lock для PPS) |
| `allow` | подсеть | Какие клиенты могут запрашивать время |
| `makestep 1.0 3` | параметр | Выправлять скачки до 1 сек, макс 3 раза при старте |

**Если нужно изменить разрешённую подсеть:**
```bash
sudo nano /etc/chrony/chrony.conf

# Замени:
allow 192.168.0.0/16

# На:
allow 10.0.0.0/8
allow 172.16.0.0/12
# или для всех (небезопасно!):
# allow 0.0.0.0/0

sudo systemctl restart chrony
```

**Если нужно добавить fallback серверы:**
```bash
sudo nano /etc/chrony/chrony.conf

# Замени:
pool 2.debian.pool.ntp.org iburst

# На:
pool 0.debian.pool.ntp.org iburst
pool 1.debian.pool.ntp.org iburst
pool 2.debian.pool.ntp.org iburst
server ntp.ubuntu.com iburst

sudo systemctl restart chrony
```

---

## Настройка клиентов

### Linux (Chrony)

```bash
sudo nano /etc/chrony/chrony.conf
```

Добавь перед остальными серверами:
```bash
# Наш Stratum 1 сервер (с приоритетом)
server 192.168.1.100 iburst prefer

# Резервные серверы
server 192.168.1.101 iburst
pool 2.debian.pool.ntp.org iburst
```

**Параметры:**
- `iburst` — быстрая синхронизация при старте (8 запросов за 2 сек)
- `prefer` — отдать приоритет этому серверу

**Применить:**
```bash
sudo systemctl restart chrony
chronyc sources -v    # Проверить статус
```

**Ожидаемый результат:**
```
MS Name/IP address         Stratum Poll Reach LastRx Last sample
================================================================
#* 192.168.1.100              1    6   377     1  +234us[+456us] +/- 150us
^- pool.ntp.org              2    6   377    23  -3ms[-3ms] +/- 45ms
```

### MikroTik (RouterOS)

**Через WebFig:**
1. IP → NTP Client
2. Enabled ✓
3. Mode: `unicast`
4. Servers (вкладка): Add → `192.168.1.100`
5. Apply

**Через Terminal:**
```bash
/system ntp client
set enabled=yes mode=unicast

/system ntp client servers
add address=192.168.1.100 comment="Stratum 1 GPS"

# Проверка
/system ntp client print
# synced-server: 192.168.1.100
# synced-stratum: 2
```

### Windows

**Командная строка (от администратора):**

```cmd
REM Проверить состояние
sc query w32time

REM Если не зарегистрирована
w32tm /register

REM Запустить
net start w32time

REM Настроить сервер
w32tm /config /manualpeerlist:"192.168.1.100,0x8" /syncfromflags:manual /reliable:YES /update

REM Синхронизировать
w32tm /resync

REM Проверить статус
w32tm /query /status
```

**Ожидаемый результат:**
```
Stratum: 2
Reference ID: 192.168.1.100
Last Successful Sync Time: 2026-05-10 12:34:56
Leap Indicator: 0 (Normal)
```

### macOS

```bash
# Проверить текущий сервер
cat /etc/ntp.conf | grep server

# Добавить наш сервер
sudo nano /etc/ntp.conf

# Добавь в начало:
server 192.168.1.100 iburst prefer
server 192.168.1.101 iburst

# Перезагрузить ntpd
sudo launchctl stop com.apple.xntpd
sudo launchctl start com.apple.xntpd

# Проверить (через 1-2 мин)
ntpq -p
```

---

## Мониторинг и проверка

### Проверка после первичной настройки

**1. Устройства присутствуют:**
```bash
ls -la /dev/ttyAMA0 /dev/pps0
# crw-rw---- 1 root dialout 204,   64 (должны оба существовать)
```

**2. Модули загружены:**
```bash
lsmod | grep pps_gpio
# pps_gpio               16384  0
```

**3. NMEA данные идут:**
```bash
timeout 5 cat /dev/ttyAMA0
# $GPRMC,120530.123,A,5559.8889,N,03729.7777,E,...
# $GPGGA,120530.123,5559.8889,N,03729.7777,E,...
```

**4. GPS имеет фикс (нужна антенна с видом на небо):**
```bash
cgps -s
# MODE: 3D ✓
# SAT: 08/13 (минимум 4 спутника для 3D)
```

**5. PPS пульсирует (требуется GPS фикс!):**
```bash
sudo ppstest /dev/pps0
# source 0 - assert 1715345945.000000157   ← должно быть .000... или .999...
# source 0 - assert 1715345946.000000042
```

**Анализ:**
- `.000000000` до `.000000500` = зелёный сигнал ✓
- `.999999500` до `.999999999` = зелёный сигнал ✓
- Если > ±500 мс = нет GPS фикса или проблема подключения

**6. Chrony синхронизирован (через 2-3 мин):**
```bash
chronyc sources -v
# #* PPS                0    4   377     0  -159ns[+619ns] +/- 167ns
#    ↑ звёздочка = выбранный источник

chronyc tracking
# Reference ID: 50505300 (PPS)
# Stratum: 1
# System time: 0.000000159 seconds fast (← должно быть < 1 мкс)
```

**7. Клиенты синхронизированы:**
```bash
# На клиенте (Linux)
chronyc sources -v

# На MikroTik
/system ntp client print

# На Windows
w32tm /query /status
```

---

## Диагностика и troubleshooting

### ❌ Проблема: `/dev/pps0` не существует

**Причины:**
- ❌ Модуль `pps_gpio` не загружен (перезагрузка не выполнена)
- ❌ Неверно указан GPIO пин в `config.txt`
- ❌ Пин GPIO4 занят чем-то другим

**Решение:**
```bash
# 1. Проверить config.txt
grep "pps-gpio" /boot/firmware/config.txt
# Должна быть: dtoverlay=pps-gpio,gpiopin=4

# 2. Проверить дмесг
sudo dmesg | grep -i pps
# Должно быть: pps_gpio: PPS GPIO initialized on GPIO4

# 3. Если есть ошибка — исправить
sudo nano /boot/firmware/config.txt

# 4. Перезагрузиться
sudo reboot
```

### ❌ Проблема: `/dev/ttyAMA0` нет или занят

**Причины:**
- ❌ Bluetooth занял UART
- ❌ Консоль слушает `console=serial0`
- ❌ Сервис `serial-getty` ещё работает

**Решение:**
```bash
# 1. Проверить что занято
ps aux | grep tty
lsof /dev/ttyAMA0

# 2. Отключить Bluetooth
sudo systemctl disable hciuart bluetooth
sudo systemctl stop hciuart bluetooth

# 3. Проверить config.txt и cmdline.txt
sudo /root/diagnose_ntp.sh
# Скрипт предложит исправить автоматически

# 4. Перезагрузиться
sudo reboot
```

### ❌ Проблема: NMEA мусор или не идут данные

**Причины:**
- ❌ Неверный baudrate (модуль на другой скорости)
- ❌ RX/TX провода перепутаны
- ❌ Модуль не подключен (нет питания 3.3V)
- ❌ gpsd заблокирован

**Решение:**
```bash
# 1. Автоопределение baudrate
sudo /root/diagnose_ntp.sh
# Скрипт автоматически найдёт правильный baudrate

# 2. Вручную проверить
sudo systemctl stop gpsd

for baud in 9600 38400 4800 19200 57600 115200; do
  echo "=== Baudrate $baud ==="
  sudo stty -F /dev/ttyAMA0 $baud raw
  timeout 2 sudo cat /dev/ttyAMA0 | head -3
done

# 3. Если RX/TX перепутаны — поменять провода местами
```

### ❌ Проблема: GPS имеет фикс, но PPS не пульсирует

**Признаки:**
```bash
sudo ppstest /dev/pps0
# нет вывода или очень редко
```

**Причины:**
- ❌ PPS провод отсоединен
- ❌ GPIO4 занят чем-то другим
- ❌ Модуль не настроен на выдачу PPS

**Решение:**
```bash
# 1. Проверить провод GPIO4 визуально
# 2. Проверить дмесг
sudo dmesg | tail -30 | grep pps

# 3. Проверить что GPIO4 свободен
grep -r "gpio4\|GPIO4" /sys/kernel/debug/pinctrl/ 2>/dev/null

# 4. Если всё ещё не работает — требуется настройка модуля u-blox
# https://www.u-blox.com/en/product/u-center-linux
```

### ❌ Проблема: Chrony не синхронизируется (Stratum 10 или 16)

**Признаки:**
```bash
chronyc tracking
# Stratum: 10 или 16 (вместо 1 или 2)
```

**Причины:**
- ❌ GPS нет фикса (спутники не видны)
- ❌ gpsd не пишет в SHM (ошибка прав доступа)
- ❌ PPS не пульсирует
- ❌ chrony не может читать `/dev/pps0`

**Решение:**
```bash
# 1. Проверить GPS фикс
cgps -s
# Должно быть MODE: 3D и SAT: N/M (N > 4)

# 2. Проверить что gpsd пишет в SHM
ls -la /dev/shm/ | grep gps

# 3. Проверить что gpsd в группе _chrony
id gpsd | grep _chrony

# Если нет:
sudo usermod -aG _chrony gpsd
sudo systemctl restart gpsd

# 4. Перезагрузиться и подождать 3-5 минут
sudo reboot
```

### ❌ Проблема: Клиенты не синхронизируются (Stratum 16)

**Причины:**
- ❌ Брандмауэр блокирует UDP порт 123
- ❌ RPi недоступна в сети
- ❌ Chrony не слушает сеть
- ❌ Клиент в другой подсети

**Решение:**
```bash
# На RPi:

# 1. Проверить что chrony слушает
sudo netstat -an | grep 123
# LISTEN 0.0.0.0:123

# 2. Проверить брандмауэр
sudo ufw status
sudo ufw allow 123/udp

# 3. Проверить IP и доступность
hostname -I
ping <IP_RPi>  # с клиента

# На клиенте:

# 4. Проверить что может достичь RPi
ping <IP_RPi>

# 5. Проверить config
cat /etc/chrony/chrony.conf | grep server

# 6. Перезагрузить chrony
sudo systemctl restart chrony
sleep 2
chronyc sources -v
```

---

## Полезные команды

**На Raspberry Pi (сервер):**

```bash
# Основной статус
chronyc sources -v              # Источники времени
chronyc tracking                # Точность синхронизации
chronyc activity                # Активные клиенты
chronyc clients                 # Список подключённых клиентов

# GPS/NMEA
gpspipe -r -n 10               # Читать NMEA (10 строк)
gpspipe -r                      # Постоянно читать (Ctrl+C для выхода)
cgps -s                         # Интерактивный GPS статус

# PPS
sudo ppstest /dev/pps0         # Тест PPS сигнала
sudo ppstest -c 10 /dev/pps0   # 10 импульсов и выход

# Логи
sudo journalctl -u gpsd -n 50      # Последние 50 строк gpsd
sudo journalctl -u chrony -n 50    # Последние 50 строк chrony
sudo journalctl -u chrony -f       # Живой просмотр (Ctrl+C)

# Сеть
sudo netstat -an | grep 123         # Прослушивание порта 123
sudo tcpdump -i any udp port 123    # Перехватить NTP пакеты

# Модули
lsmod | grep pps                    # Загруженные модули PPS
```

**На клиенте (Linux):**

```bash
chronyc sources -v
chronyc tracking
chronyc activity

ping <IP_RPi>
sudo tcpdump -i any udp port 123
```

**На MikroTik:**

```
/system ntp client print
/system ntp client servers print
```

---

## Чек-лист первичной настройки

**Перед запуском setup_ntp_server.sh:**
- [ ] Raspberry Pi 4 загружена и доступна по SSH
- [ ] GNSS модуль физически подключен (VCC, GND, TX, RX, PPS)
- [ ] Антенна установлена с видом на небо

**После запуска setup_ntp_server.sh:**
- [ ] Скрипт завершился без ошибок
- [ ] Резервные копии сохранены
- [ ] Система перезагружена

**После первой перезагрузки (3-5 мин):**
- [ ] `/dev/ttyAMA0` существует
- [ ] `/dev/pps0` существует
- [ ] NMEA данные идут
- [ ] GPS имеет 3D фикс
- [ ] PPS пульсирует

**Через 5-10 мин:**
- [ ] `chronyc sources -v` показывает `#* PPS`
- [ ] `chronyc tracking` показывает `Stratum: 1`
- [ ] RMS offset < 1 мкс

**Сеть:**
- [ ] Клиенты пингуют RPi
- [ ] UDP 123 открыт
- [ ] Клиенты синхронизируются

---

## Мониторинг

### Apache + PHP веб-интерфейс

Если установлен Apache + PHP, можно добавить веб-мониторинг:

```bash
sudo nano /etc/sudoers.d/chrony-web
```

Содержимое:
```
www-data ALL=(ALL) NOPASSWD: /usr/bin/chronyc
www-data ALL=(ALL) NOPASSWD: /bin/systemctl
```

```bash
sudo chmod 440 /etc/sudoers.d/chrony-web
```

Скопировать файлы мониторинга (если есть):
```bash
sudo cp monitor/* /var/www/html/monitor/
sudo chown -R www-data:www-data /var/www/html/monitor/
```

Открыть в браузере: `http://<IP_RPi>/monitor/`

---

## Откат конфигурации

Если что-то сломалось:

```bash
# Найти резервную копию
ls -la /root/ntp_setup_backup_*/

# Вернуть config.txt
sudo cp /root/ntp_setup_backup_20260510_*/config.txt.bak \
        /boot/firmware/config.txt

# Вернуть gpsd конфиг
sudo cp /root/ntp_setup_backup_20260510_*/gpsd.bak \
        /etc/default/gpsd

# Вернуть chrony конфиг
sudo cp /root/ntp_setup_backup_20260510_*/chrony.conf.bak \
        /etc/chrony/chrony.conf

# Перезагрузиться
sudo reboot
```

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
System time     : 0.000000477 seconds fast of NTP time
Last offset     : -0.000000015 seconds
RMS offset      : 0.000000098 seconds
```

---

## 📱 Поддержка

Если есть проблемы:

1. **Запусти диагностический скрипт:**
   ```bash
   sudo /root/diagnose_ntp.sh
   ```

2. **Сохрани логи:**
   ```bash
   sudo journalctl -u gpsd -n 100 > ~/gpsd.log
   sudo journalctl -u chrony -n 100 > ~/chrony.log
   ```

3. **Откройте issue на GitHub** с логами и описанием проблемы

---

## 📄 Лицензия

Проект распространяется под лицензией **MIT**. Смотри [LICENSE](LICENSE).

---

**Версия:** 2.0.0 · **Обновлено:** 2026-05-10  
**Автор:** NoIDXMV6 · **Язык:** Русский

