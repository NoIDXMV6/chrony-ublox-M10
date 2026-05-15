<img width="150" height="150" alt="favicon" src="https://github.com/user-attachments/assets/a97caaf9-0d15-44fe-9773-afe97644d830" />

# Stratum 1 NTP Сервер — Raspberry Pi 4 + u-blox M10

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Platform](https://img.shields.io/badge/Platform-Raspberry%20Pi%204-c51a4a)](https://www.raspberrypi.com/)
[![OS](https://img.shields.io/badge/OS-Armbian%20Trixie%20(Debian%2013)-blue)](https://www.armbian.com/)
[![Stratum](https://img.shields.io/badge/NTP-Stratum%201-brightgreen)]()
[![Accuracy](https://img.shields.io/badge/Accuracy-150–300%20ns-brightgreen)]()

PPS-дисциплинированный NTP-сервер первого стратума на базе Raspberry Pi 4 и GNSS-модуля u-blox M10 с поддержкой GPS, GLONASS, Galileo и BeiDou. Точность синхронизации 150–300 нс. Включает веб-дашборд мониторинга с REST API и Telegram-оповещениями.

**Платформа:** Raspberry Pi 4 · Armbian Trixie (Debian 13) · ядро 6.x  
**GNSS модуль:** QUESCAN UBX-M10050-KB (u-blox M10) с PPS · [Купить на AliExpress](https://ali.click/egbg811)

**Точность:** ~150–300 мс (PPS-дисциплинированный источник) · **Stratum:** 1

**Мониторинг:** 🌐 [Веб-интерфейс](monitor/README.md) в реальном времени + REST API

**ВАЖНО** Если вам нужна большая точность - не покупайте дешевый позиционный модуль! Из доступных есть [Quectel LG290P Chipset Quad-band GNSS Module RTK Centimeter-Level Positioning Board L1+L2+L5+E6 Positioning](https://www.aliexpress.com/item/1005010589099378.html). С ним результаты будут гораздо точнее.

---

## Содержание

- [Возможности](#возможности)
- [Компоненты](#компоненты)
- [Схема подключения](#схема-подключения)
- [Быстрый старт](#быстрый-старт)
- [Мониторинг](#мониторинг)
- [Настройка клиентов](#настройка-клиентов)
- [Проверка работы](#проверка-работы)
- [Диагностика](#диагностика)
- [Структура проекта](#структура-проекта)
- [Безопасность](#безопасность)
- [Ожидаемый результат](#ожидаемый-результат)

---

## Возможности

- **Stratum 1** — прямая привязка к PPS-сигналу GNSS-модуля
- **Точность 150–300 нс** — достигается PPS-дисциплинированием chrony
- **Автоматическая установка** — один скрипт настраивает всю систему
- **Автоопределение baudrate** — диагностический скрипт тестирует все скорости UART
- **Веб-мониторинг** — дашборд с картой спутников, графиком смещения, REST API
- **Fallback** — при потере GPS автоматически переключается на интернет-серверы (Stratum 10)
- **Telegram-оповещения** — алерты при потере PPS, падении сервисов, изменении strata
- **Мультисистемный GNSS** — поддержка GPS, GLONASS, Galileo, BeiDou (зависит от антенны)

---

## Компоненты

| Компонент | Характеристики |
|---|---|
| **Плата** | Raspberry Pi 4B (любой объём RAM) |
| **ОС** | Armbian Trixie (Debian 13), ядро 6.x |
| **GNSS-модуль** | QUESCAN UBX-M10050-KB (u-blox M10) с PPS · [AliExpress](https://ali.click/egbg811) |
| **Интерфейс** | UART 38400 baud + PPS (GPIO4) |
| **NTP-демон** | Chrony 4.x |
| **GPS-демон** | gpsd |

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

| RPi пин | Сигнал | Направление | Пин модуля |
|---|---|---|---|
| 1 | 3.3V | → | VCC |
| 6 | GND | → | GND |
| **7** | **GPIO4** | **← PPS** | **PPS** |
| 8 | GPIO14 TXD | → | RX |
| 10 | GPIO15 RXD | ← | TX |

> ⚠️ **КРИТИЧНО:** Модуль питается строго от **3.3V**. Подключение к 5V необратимо выведет модуль из строя.
>
> ⚠️ **Частая ошибка:** RX и TX часто путают. Если NMEA-данные не поступают — поменяй провода GPIO14 и GPIO15 местами.

---

## Быстрый старт

Полная пошаговая инструкция — в [INSTALL.md](INSTALL.md).

Краткий путь (если уже знаком с проектом):

```bash
# На рабочем компьютере — скопировать скрипт установки
scp setup_ntp_server.sh root@<IP_RPi>:/root/

# На Raspberry Pi — запустить установку
ssh root@<IP_RPi>
sudo /root/setup_ntp_server.sh

# Обязательно перезагрузиться
sudo reboot

# Через 2-3 минуты после загрузки — проверить
chronyc sources -v
chronyc tracking
```

**Ожидаемый результат:** `chronyc sources` покажет `#* PPS`, `chronyc tracking` — `Stratum: 1`.

---

## Мониторинг

Проект включает веб-дашборд реального времени: метрики chrony, карта неба (Skyplot), график смещения, управление сервисами и REST API. Поддерживаются светлая/тёмная темы и Telegram-оповещения.

Установка, конфигурация и описание API — в [monitor/README.md](monitor/README.md).

---

## Настройка клиентов

### Linux (Chrony)

```bash
sudo nano /etc/chrony/chrony.conf
# Добавить:
server 192.168.1.100 iburst prefer

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

```batch
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
show ntp status
show ntp associations
```

---

## Проверка работы

### Обязательные проверки после установки

```bash
# 1. Устройства созданы
ls -l /dev/ttyAMA0 /dev/pps0

# 2. NMEA-данные поступают
timeout 5 cat /dev/ttyAMA0
# Норма: строки $GPRMC, $GPGGA, $GPGSV и др.

# 3. GPS имеет фикс (через 1-5 мин после выхода на небо)
cgps -s
# MODE: 3D, SAT: 6+ — хорошо

# 4. PPS пульсирует (только при активном фиксе)
sudo ppstest /dev/pps0
# assert 1234567890.000000001 — норма

# 5. Chrony синхронизирован (через 2-3 мин)
chronyc sources -v
# #* PPS — источник выбран

chronyc tracking
# Stratum: 1, System time: < 1 мкс, RMS offset: < 500 нс
```

### Полезные команды

```bash
# Chrony
chronyc sources -v       # Источники времени
chronyc tracking         # Точность и смещение
chronyc activity         # Количество клиентов
chronyc clients          # Список подключённых клиентов
chronyc sourcestats      # Статистика по источникам

# GPS
gpspipe -r -n 20         # 20 строк NMEA
cgps -s                  # Интерактивный GPS-статус

# PPS
sudo ppstest /dev/pps0

# Логи
sudo journalctl -u gpsd   -n 100 -f
sudo journalctl -u chrony -n 100 -f

# Сеть
sudo ss -uln | grep 123
sudo tcpdump -i any udp port 123 -n
```

---

## Диагностика

Для автоматической диагностики и исправления большинства проблем используй:

```bash
sudo /root/diagnose_ntp.sh
```

Скрипт выполняет 11 проверок, предлагает автоисправление интерактивно и не требует перезагрузки.

### `/dev/pps0` не существует

**Причина:** модуль pps-gpio не загружен (перезагрузка не выполнена или неверный GPIO).

```bash
# Проверить конфиг
grep "pps-gpio" /boot/firmware/config.txt
# Если строки нет — добавить и перезагрузиться
sudo /root/diagnose_ntp.sh
```

### NMEA-данные не поступают или содержат мусор

**Причина:** неверный baudrate (обычно 9600 вместо 38400) или перепутаны RX/TX.

```bash
# Тест разных скоростей вручную
for baud in 4800 9600 38400 115200; do
    echo "--- $baud ---"
    stty -F /dev/ttyAMA0 $baud raw
    timeout 2 cat /dev/ttyAMA0 | head -3
done

# Или автоопределение через диагностику
sudo /root/diagnose_ntp.sh
```

Если скорость верная, но данных нет — поменяй провода GPIO14 и GPIO15 местами.

### Chrony: Stratum 16 (нет синхронизации)

**Причина:** нет GPS-фикса, gpsd не пишет в SHM, PPS не работает.

```bash
# Проверить фикс
cgps -s   # MODE должен быть 3D

# Проверить права gpsd на SHM
id gpsd | grep _chrony
# Если нет — добавить:
sudo usermod -aG _chrony gpsd
sudo systemctl restart gpsd

# Проверить PPS
sudo ppstest /dev/pps0 | head -5

# Подождать 3-5 минут после перезапуска
sudo systemctl restart gpsd chrony
sleep 180 && chronyc tracking
```

### Клиенты не синхронизируются (Stratum 16)

**Причина:** брандмауэр, неверная подсеть в `allow`, порт 123/UDP закрыт.

```bash
# На сервере (RPi)
sudo ss -uln | grep 123         # Порт слушается?
grep "^allow" /etc/chrony/chrony.conf
sudo ufw allow 123/udp          # Если используется ufw
sudo systemctl restart chrony

# На клиенте
ping 192.168.1.100
chronyc sources -v              # Видна ли RPi?
```

### Точность хуже 1 мс

**Причина 1:** слабый сигнал спутников, мало спутников, плохой GDOP, препятствия.

```bash
cgps -s   # SNR спутников должен быть > 30 dBHz, GDOP < 2.0
```

Переместить антенну выше, обеспечить вид на небо от 10° над горизонтом.  

**Причина 2:** текущая конфигурация использует refclock SHM 0 для NMEA (через gpsd) и refclock PPS /dev/pps0 lock GPS. Проблема может быть в том, что NMEA-данные могут иметь задержку или смещение, и PPS привязывается к ним, что приводит к ошибке.  
Через определенное время вы увидите в панели Источники времени что-то подобное:  

Источники времени	Str	Poll	Reach	Last	Смещение
?	GPS	            —   4	    377     22	    +269ms[ -343ms] +/- 318ms  

Внесите в /etc/chrony/chrony.conf  
```
refclock SHM 0 offset -0.269 delay 0.5 refid GPS noselect
```

```bash
sudo systemctl restart chrony
```

**Причина 3:** Несмотря на добавленный offset, смещение NMEA продолжает прыгать (от +156 мс до -423 мс). Это означает, что модуль u-blox M10 в навигационной прошивке выдаёт время с переменной задержкой, и привязать PPS к такому источнику через lock GPS невозможно — вы будете получать миллисекундные скачки. Нужно перейти на другую схему, которая не зависит от нестабильности NMEA.  

```bash
gpspipe -w -n 10 | grep -o '"shm":"[^"]*"'
"shm":"NTP1"
"shm":"NTP2"
```
Это означает, что gpsd создает два сегмента разделяемой памяти: NTP1 и NTP2. В терминах chrony, SHM 0 соответствует NTP1, SHM 1 соответствует NTP2. Таким образом, PPS, скорее всего, находится в NTP2 (индекс 1). Теперь нужно окончательно настроить chrony.conf с использованием SHM 0 для NMEA и SHM 1 для PPS.  
Замените в /etc/chrony/chrony.conf оба параметра refclock на:
```
refclock SHM 0 offset 0.5 delay 0.2 refid NMEA noselect
refclock SHM 1 offset 0.0 delay 0.0 refid PPS prefer
```
Теперь chrony будет брать:
NMEA для грубой привязки из SHM 0 (с компенсацией задержки 0.5 с)
PPS как основной источник из SHM 1 (без смещения, с наивысшим приоритетом)
```bash
sudo systemctl restart chrony
```

Дайте системе 2-3 минуты на стабилизацию, затем проверьте:

```bash
chronyc sources -v
chronyc tracking
```

**🔧 Немедленные действия для восстановления точности**

1️⃣ Измерьте актуальное смещение NMEA
Выполните несколько раз (с интервалом в 10–20 секунд):

```bash
chronyc sources | grep NMEA
```
Вы увидите значение в столбце Last sample, например +218ms. Запомните это число (оно может колебаться, но порядок понятен).

2️⃣ Внесите правку в /etc/chrony/chrony.conf
Замените строку для NMEA на следующую (подставьте ваше измеренное смещение, но с обратным знаком):

```bash
refclock SHM 0 offset -0.218 delay 0.2 refid NMEA noselect
```
Если смещение, например, +156ms, то offset -0.156. Если -423ms, то offset +0.423. Не забудьте про первоначальное смещение +0.5!

3️⃣ Перезапустите chrony и дайте ему 2–3 минуты на стабилизацию
```bash
sudo systemctl restart chrony
sleep 180
```
4️⃣ Проверьте результат
```bash
chronyc tracking
```
Ожидаем System time и RMS offset в микросекундах (значения менее 0.000010).

**📊 Ожидаемый результат**  

В chronyc sources вы должны увидеть:

```text
#* PPS       ...    Last sample: +0.000000000 sec
#- NMEA      ...    Last sample: +0.485123456 sec
```
В chronyc tracking:
```text
Reference ID    : 50505300 (PPS)
Stratum         : 1
System time     : 0.000000045 seconds slow of NTP time
RMS offset      : 0.000000098 seconds
```

Если RMS offset останется в миллисекундах (например, 0.1 с), то это будет означать, что ваш модуль M10 в навигационной прошивке не способен выдавать стабильный PPS — он либо имеет аппаратный джиттер, либо время от времени сдвигает фазу импульса. В этом случае единственное решение — замена на TIM-модуль (NEO-M8T или NEO-F10T). 

---

## Структура проекта

```
.
├── README.md                  # Документация (ты здесь)
├── INSTALL.md                 # Пошаговая инструкция установки
├── LICENSE                    # MIT
├── setup_ntp_server.sh       # Автоматическая установка
├── diagnose_ntp.sh           # Диагностика и автоисправление
└── monitor/                   # Веб-интерфейс мониторинга
    ├── README.md             # Документация мониторинга
    ├── index.html            # Веб-дашборд
    ├── monitor.js            # JavaScript-логика
    ├── style.css             # Стили (светлая/тёмная тема)
    ├── api.php               # REST API — сбор метрик
    ├── action.php            # REST API — управляющие действия
    ├── config.json           # Конфигурация
    ├── www-ntp-monitor       # Sudoers-файл для Apache/nginx
    └── favicon.svg           # Иконка
```

---

## Безопасность

### Ограничить NTP-клиентов

```bash
sudo nano /etc/chrony/chrony.conf
# Изменить строку allow — например, для конкретной подсети:
# allow 192.168.1.0/24
# или для конкретных хостов:
# allow 192.168.1.10
# allow 192.168.1.11
sudo systemctl restart chrony
```

---

## Ожидаемый результат

После правильной установки и прогрева (~5–10 минут на открытом небе):

```
root@ntp:~# chronyc tracking
Reference ID    : 50505300 (PPS)
Stratum         : 1
Ref time (UTC)  : Thu May 14 09:12:00 2026
System time     : 0.004687872 seconds fast of NTP time
Last offset     : +0.001886390 seconds
RMS offset      : 0.004174584 seconds
Frequency       : 9.072 ppm fast
Residual freq   : +19.805 ppm
Skew            : 0.038 ppm
Root delay      : 0.000000000 seconds
Root dispersion : 0.061631236 seconds
Update interval : 16.0 seconds
Leap status     : Normal

root@ntp:~# chronyc sources
MS Name/IP address         Stratum Poll Reach LastRx Last sample
===============================================================================
#? NMEA                          0   4   377    15   +128ms[ +130ms] +/-  156ms
#* PPS                           0   4   377    15  +4437us[+6103us] +/-   40ms
^? scan-76.skipa.cyberok.ru      0  10     0     -     +0ns[   +0ns] +/-    0ns
^? mskm9-ntp01c.ntppool.yan>     0  10     0     -     +0ns[   +0ns] +/-    0ns
^? mail.rashnikov.name           0  10     0     -     +0ns[   +0ns] +/-    0ns
^? 51.250.110.169                0  10     0     -     +0ns[   +0ns] +/-    0ns
^? 23-93-251-54.dedicated.s>     0  10     0     -     +0ns[   +0ns] +/-    0ns
^? 172-104-28-175.ip.linode>     0  10     0     -     +0ns[   +0ns] +/-    0ns
^? 92.63.176.247                 0  10     0     -     +0ns[   +0ns] +/-    0ns
^? mail.redway.ru                0  10     0     -     +0ns[   +0ns] +/-    0ns

```

`#* PPS` — PPS выбран как основной источник (именно это означает Stratum 1).

---

## Поддержка

При возникновении проблем:

```bash
# Запустить автодиагностику
sudo /root/diagnose_ntp.sh

# Собрать логи для отчёта
sudo journalctl -u gpsd   -n 100 > ~/gpsd.log
sudo journalctl -u chrony -n 100 > ~/chrony.log
chronyc sources -v > ~/chrony_sources.txt
```

Открыть [GitHub Issue](https://github.com/NoIDXMV6/chrony-ublox-M10/issues) с приложенными логами.

**Ресурсы:**

- 📖 [Chrony Documentation](https://chrony-project.org/documentation.html)
- 📖 [gpsd Documentation](https://gpsd.gitlab.io/gpsd/)
- 📖 [u-blox M10 Product Page]([https://www.u-blox.com/en/product/m10-platform](https://www.u-blox.com/en/product/ubx-m10-series))
- 📋 [Raspberry Pi GPIO Reference](https://www.raspberrypi.com/documentation/computers/raspberry-pi.html)

---

## Лицензия

[MIT License](LICENSE) — используй как угодно в своих проектах.

---

**Версия:** 2.2.0 · **Обновлено:** 2026-05-11 · **Автор:** [NoIDXMV6](https://github.com/NoIDXMV6)

⭐ Если проект оказался полезен — поставь звезду на [GitHub](https://github.com/NoIDXMV6/chrony-ublox-M10)!
