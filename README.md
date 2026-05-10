# Stratum 1 NTP Сервер на Raspberry Pi 4

**Платформа:** Raspberry Pi 4 · Armbian Trixie (Debian 13) · ядро 6.x  
**GNSS модуль:** QUESCAN UBX-M10050-KB (u-blox M10) с PPS  
**Точность:** ~150–300 нс (PPS-дисциплинированный источник)

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

> ⚠️ Модуль питается от **3.3V**. Не подключай к 5V (пины 2, 4) — сгорит.

> ⚠️ RX и TX часто путают. Если NMEA не идут — поменяй провода местами.

---

## Требования

- Raspberry Pi 4
- Armbian Trixie 64-bit (ядро 6.x)
- GNSS модуль с PPS выходом (u-blox M10 или аналог)
- Антенна с видом на небо
- Доступ к интернету для установки пакетов

---

## Быстрый старт

### 1. Скопировать скрипт на Raspberry Pi

```bash
scp setup_ntp_server.sh root@<IP_RPi>:/root/
```

или через curl если есть доступ к скрипту:

```bash
curl -O https://your-server/setup_ntp_server.sh
```

### 2. Запустить установку

```bash
chmod +x setup_ntp_server.sh
sudo ./setup_ntp_server.sh
```

Скрипт выполнит:
- резервное копирование конфигов
- установку пакетов (gpsd, chrony, pps-tools)
- настройку UART (отключение BT, освобождение от консоли)
- настройку PPS оверлея на GPIO4
- настройку gpsd для u-blox M10 (38400 baud)
- настройку chrony как Stratum 1 сервера
- предложит перезагрузку

### 3. Проверка после перезагрузки

```bash
# Устройства появились
ls /dev/ttyAMA0 /dev/pps0

# PPS пульсирует (нужен GPS фикс — антенна с видом на небо)
sudo ppstest /dev/pps0
# source 0 - assert 1234567890.000000001 — норма

# NMEA строки идут по UART
stty -F /dev/ttyAMA0 38400 raw && cat /dev/ttyAMA0
# $GNRMC,$GNGGA и т.д. — норма

# GPS фикс и спутники
cgps -s

# Статус chrony (через 2-3 мин после загрузки)
chronyc sources -v
# #* PPS — звёздочка означает выбранный источник

# Точность синхронизации
chronyc tracking
# Stratum: 1
# System time: единицы сотен наносекунд
```

---

## Ожидаемый результат

```
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
```

---

## Настройка клиентов

### Linux (chrony)

```bash
# /etc/chrony/chrony.conf
server <IP_Raspberry_Pi> iburst prefer
```

```bash
sudo systemctl restart chrony
chronyc sources -v
```

### MikroTik (RouterOS)

```
/system ntp client
set enabled=yes mode=unicast

/system ntp client servers
add address=<IP_Raspberry_Pi>
```

Проверка:
```
/system ntp client print
# synced-server: <IP_Raspberry_Pi>
# synced-stratum: 2
```

### Windows

```
w32tm /config /manualpeerlist:<IP_Raspberry_Pi> /syncfromflags:manual /reliable:YES /update
net stop w32tm && net start w32tm
w32tm /query /status
```

---

## Диагностика

| Симптом | Причина | Решение |
|---------|---------|---------|
| `/dev/ttyAMA0` нет | BT не отключён | Проверь `dmesg \| grep hci_uart` |
| `/dev/pps0` нет | оверлей не загрузился | `dmesg \| grep pps`, проверь config.txt |
| `cat /dev/ttyAMA0` — мусор | неверный baudrate | `stty -F /dev/ttyAMA0 38400 raw` |
| RX/TX перепутаны | классическая ошибка | поменяй провода местами |
| chrony GPS `?` | gpsd не пишет в SHM | `sudo usermod -aG _chrony gpsd` |
| chrony PPS `?` | нет GPS фикса | нужна антенна с видом на небо |
| PPS `+` вместо `*` | нет lock на GPS | GPS должен иметь фикс (`cgps -s`) |

---

## Параметры скрипта

Перед запуском можно изменить в начале скрипта:

| Параметр | По умолчанию | Описание |
|----------|-------------|----------|
| `PPS_GPIO_PIN` | `4` | GPIO пин PPS (физический пин 7) |
| `GPS_BAUDRATE` | `38400` | Скорость UART модуля |
| `ALLOW_SUBNET` | `192.168.0.0/16` | Подсеть NTP клиентов |
| `NTP_FALLBACK` | `pool 2.debian.pool.ntp.org` | Fallback серверы |

---

## Архитектура

```
                    ┌─────────────────────┐
  GNSS антенна      │   Raspberry Pi 4    │
       │            │                     │
  u-blox M10  ──── │  gpsd               │
  UART 38400        │    └── SHM 0 ──────►│
  PPS GPIO4   ──── │  pps_gpio           │──► chrony (Stratum 1)
                    │    └── /dev/pps0 ──►│         │
                    └─────────────────────┘         │
                                                     ▼

                                              LAN клиенты
                                          (или через MikroTik)
```

## Мониторинг

### Apache + PHP

Нужно добавить www-data в sudoers:
```bash
sudo nano /etc/sudoers.d/chrony-web
```
Содержимое:
```
www-data ALL=(ALL) NOPASSWD: /usr/bin/chronyc
```
```bash
sudo chmod 440 /etc/sudoers.d/chrony-web
```
<img width="1796" height="1334" alt="Screenshot_1" src="https://github.com/user-attachments/assets/e2c0555d-45c3-45c6-aeb4-61fd42f0cc8a" />
