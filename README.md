# Stratum 1 NTP Сервер на Raspberry Pi 4

**Платформа:** Raspberry Pi 4 · Armbian Trixie (Debian 13) · ядро 6.x  
**GNSS модуль:** QUESCAN UBX-M10050-KB (u-blox M10) с PPS  https://ali.click/egbg811

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
net stop w32time && net start w32time
w32tm /query /status
```
#### Если проблемы

Причина ошибки
Ошибка 0x80070426 («Служба не запущена») возникает, потому что служба Windows Time (w32time) не запущена или даже не установлена в системе. Команды net stop w32tm и net start w32tm не работают, потому что правильное имя службы — w32time, а не w32tm.

Пошаговое решение
Шаг 1. Проверить состояние службы
Откройте командную строку от имени администратора и выполните:
```cmd
sc query w32time
```

Возможные результаты:

```
STATE: RUNNING — служба запущена;
STATE: STOPPED — служба остановлена;
[SC] EnumServicesStatus:OpenService FAILED 1060 — служба не существует в системе.
```

Шаг 2. Если служба существует, но остановлена
Запустите службу:

```cmd
net start w32time
```

Если команда не сработала, попробуйте через sc:

```cmd
sc start w32time
```

Шаг 3. Если служба не существует (не установлена)
Установите службу Windows Time:

```cmd
w32tm /register
```

После выполнения этой команды система зарегистрирует службу w32time в реестре.

Шаг 4. Запустить службу после регистрации
```cmd
net start w32time
```

Или альтернативно:

```cmd
sc start w32time
```

Шаг 5. Применить настройки синхронизации
Теперь выполните вашу исходную команду:

```cmd
w32tm /config /manualpeerlist:<IP_Raspberry_Pi> /syncfromflags:manual /reliable:YES /update
```

Шаг 6. Проверить статус синхронизации
Убедитесь, что всё работает:

```cmd
w32tm /query /status
```

В выводе обратите внимание на:
```
Leap Indicator: 0;
Stratum: 1–5 (не 16 — это значит, нет синхронизации);
Reference ID: IP вашего NTP‑сервера (<IP_Raspberry_Pi>);
Last Successful Sync Time: должна быть недавняя дата.
```

Шаг 7. Принудительная синхронизация (опционально)
Чтобы сразу синхронизировать время, выполните:

```cmd
w32tm /resync
```

Полный скрипт решения (в одном блоке)
Выполните эти команды последовательно в командной строке от имени администратора:

```cmd
:: Проверить состояние службы
sc query w32time

:: Если служба не зарегистрирована — зарегистрировать
w32tm /register

:: Запустить службу
net start w32time

:: Настроить синхронизацию с вашим NTP‑сервером
w32tm /config /manualpeerlist:<IP_Raspberry_Pi> /syncfromflags:manual /reliable:YES /update

:: Принудительно синхронизировать
w32tm /resync

:: Проверить статус
w32tm /query /status
```

Дополнительные проверки и советы
Права администратора. Все команды требуют запуска от имени администратора. Кликните правой кнопкой мыши по ярлыку командной строки и выберите «Запуск от имени администратора».

Брандмауэр. Убедитесь, что брандмауэр разрешает UDP‑порт 123 (NTP).

Доступность сервера. Проверьте, что сервер <IP_Raspberry_Pi> доступен:

```cmd
ping <IP_Raspberry_Pi>
```

Альтернативный формат списка пиров. Если команда с одним IP не сработает, попробуйте указать список через пробел (в кавычках):

```cmd
w32tm /config /manualpeerlist:"<IP_Raspberry_Pi> pool.ntp.org" /syncfromflags:manual /update
```

Сброс конфигурации (если нужно начать с чистого листа):

```cmd
w32tm /unregister
:: Подождите несколько секунд
w32tm /register
```

Расшифровка параметров вашей команды

```
/manualpeerlist:<IP_Raspberry_Pi> — указать конкретный NTP‑сервер для синхронизации;

/syncfromflags:manual — синхронизироваться только с указанными вручную серверами;

/reliable:YES — пометить этот сервер как надёжный источник времени (важно для контроллеров домена);

/update — применить изменения немедленно.
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
  u-blox M10  ────  │  gpsd               │
  UART 38400        │    └── SHM 0 ──────►│
  PPS GPIO4   ────  │  pps_gpio           │──► chrony (Stratum 1)
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
