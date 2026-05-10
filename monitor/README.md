# NTP Monitor — Веб-интерфейс мониторинга Stratum 1 сервера

Полнофункциональная система мониторинга в реальном времени для Stratum 1 NTP сервера на Raspberry Pi с u-blox M10 GNSS модулем.

<img width="1612" height="2023" alt="Screenshot_3" src="https://github.com/user-attachments/assets/4e5728c6-0905-469f-9038-04c45cba0538" />


---

## 📋 Содержание

1. [Архитектура системы](#архитектура-системы)
2. [Компоненты](#компоненты)
3. [Требования](#требования)
4. [Установка и настройка](#установка-и-настройка)
5. [Использование](#использование)
6. [API Reference](#api-reference)
7. [Панель управления](#панель-управления)
8. [Настройка безопасности](#настройка-безопасности)
9. [Решение проблем](#решение-проблем)

---

## Архитектура системы

```
┌──────────────────────────────────────────────────────────┐
│                    Raspberry Pi 4                        │
│                                                          │
│  ┌─────────┐  ┌───────────┐  ┌──────────────────┐        │
│  │ gpsd    │  │  chrony   │  │  Apache + PHP    │        │
│  │ ────────│  │ ──────────│  │ ─────────────────│        │
│  │• GNSS   │  │• NTP      │  │• REST API        │        │
│  │• PPS    │  │  daemon   │  │• Live Web UI     │        │
│  └────┬────┘  └────┬──────┘  │• Action control  │        │
│       │            │         └────────┬─────────┘        │
│  /dev/ttyAMA0  /dev/pps0        :80/api.php              │
│  /run/shm      chronyc          :80/action.php           │
│                                 :80/index.html           │
│                                                          │
└──────────────────────────────────────────────────────────┘
       ▲                                      │
       │                                      │
   GNSS модуль                         LAN клиенты (браузеры)
   (UART + PPS)                        NTP клиенты
```

### Поток данных

1. **GNSS → gpsd** (UART 38400 baud)
   - u-blox M10 отправляет NMEA строки по `/dev/ttyAMA0`
   - gpsd парсит положение, высоту, спутники
   - Сохраняет в SHM (shared memory)

2. **PPS → chrony** (GPIO4)
   - Пульсирующий сигнал 1 Hz на `/dev/pps0`
   - Дисциплинирует системные часы до наносекунд
   - chrony использует как primary reference

3. **chrony → API** (Unix socket / TCP port 323)
   - `chronyc` выполняет команды мониторинга
   - Возвращает `tracking`, `sources`, `sourcestats`, `activity`, `clients`, `serverstats`

4. **API → Browser** (REST JSON)
   - `api.php` собирает все метрики в одно JSON
   - `index.html` отображает интерактивный дашборд
   - `action.php` выполняет команды управления

---

## Компоненты

### 1. Backend: `api.php` — Сбор метрик

**Язык:** PHP 7.4+  
**Зависимости:** `chronyc`, `gpsd`, `systemctl`, `/proc`, `/sys`

#### Основные функции

| Функция | Команда | Возвращает |
|---------|---------|-----------|
| `getTracking()` | `chronyc tracking` | Смещение, частота, stratum, статус синхронизации |
| `getSources()` | `chronyc sources -v` | Список источников времени (GPS, PPS, NTP серверы) |
| `getSourcestats()` | `chronyc sourcestats` | Статистика источников (NP, NR, span, frequency) |
| `getActivity()` | `chronyc activity` | Кол-во онлайн/оффлайн источников |
| `getClients()` | `chronyc clients` | Подключённые NTP клиенты (требует `sudo`) |
| `getServerstats()` | `chronyc serverstats` | Статистика сервера (пакеты, NTS-KE) |
| `getGpsd()` | `gpspipe -w -n 20` | GPS фикс, координаты, спутники (TPV + SKY классы JSON) |
| `getSystem()` | `/proc`, `/sys`, `systemctl` | Температура, uptime, нагрузка, память, статус сервисов |

#### Форматирование данных

- **formatSeconds()** — конвертирует секунды в наносекунды/микросекунды/миллисекунды с выбором оптимальной единицы
- **Цветовая кодировка статуса:**
  - `good` — зелёный (stratum ≤ 1, offset < 100 нс)
  - `warning` — жёлтый (offset 100-500 нс, < 2 источников)
  - `error` — красный (offset > 500 нс, нет синхронизации)

---

### 2. Backend: `action.php` — Выполнение команд

**Язык:** PHP 7.4+  
**Зависимости:** `sudo`, `systemctl`, `chronyc`, `stty`, `cat`, `strings`

#### Поддерживаемые действия

| Action | Команда | Назначение |
|--------|---------|-----------|
| `makestep` | `sudo chronyc makestep` | Принудительная синхронизация (если смещение > 1 сек) |
| `restart_gpsd` | `sudo systemctl restart gpsd` | Перезагрузка демона GPS |
| `restart_chrony` | `sudo systemctl restart chrony` | Перезагрузка NTP демона |
| `raw_port` | `stty -F /dev/ttyAMA0 && cat` | Чтение сырых NMEA данных с UART (20 строк за 3 сек) |
| `port_info` | `stty -F /dev/ttyAMA0` + `lsof` | Информация о параметрах порта и использующих процессах |

#### Функция runCommand()

Вспомогательная функция для безопасного выполнения команд:
- Захватывает `stdout` и `stderr`
- Возвращает код выхода и вывод
- Используется `exec()` с перехватом в третий аргумент

#### Пример ответа

```json
{
  "success": true,
  "output": "200 OK\nClock was stepped by 0.000000042 seconds"
}
```

---

### 3. Frontend: `index.html` — Интерактивный дашборд

**Язык:** HTML5 + CSS3 + JavaScript (vanilla, без фреймворков)  
**Размер:** ~22 KB (минифицировано ~13 KB)

#### Стили и тема

**Цветовая схема:** Темная, с неоновыми акцентами (cyberpunk-style)

```css
--bg:        #0a0e14    /* чёрный фон */
--bg2:       #0f141c    /* карточки */
--bg3:       #151c27    /* заголовки блоков */
--border:    #1e2a3a    /* разделители */
--accent:    #00d4ff    /* голубой неон */
--accent2:   #0099cc    /* голубой приглушённый */
--green:     #39d98a    /* статус OK */
--yellow:    #f5c842    /* статус WARNING */
--red:       #ff4d6a    /* статус ERROR */
--mono:      'JetBrains Mono', monospace
--sans:      'Space Grotesk', sans-serif
```

#### Основные компоненты UI

##### 1. Clock Bar (часы в реальном времени)
- Формат: `HH:MM:SS.mmm`
- Дата с названием дня недели и часовым поясом
- Обновляется каждые 10 мс

##### 2. Header
- Логотип `NTP//MON` с акцентом
- Хостнейм сервера
- Сервис-пилюли (chrony/gpsd/PPS статус)
- Время последнего обновления + кнопка Refresh

##### 3. Metric Cards (верхний ряд)
8 карточек с основными метриками:
- Stratum, Смещение, RMS offset, Частота
- Спутники (used/total), Онлайн источники
- UART baudrate, Leap статус

Цветовое кодирование:
- `good` — зелёная полоса сверху
- `warn` — жёлтая полоса
- `error` — красная полоса

##### 4. Grid 3×3 (основные блоки)

**Левая колонка: Источники времени**
- Таблица с состояниями источников
- Состояния: `*`=выбран, `+`=комбинируется, `-`=не используется, `x`=ошибка, `?`=недоступен
- Reach bar (8 битов последних попыток пинга)

**Средняя колонка: GPS / GNSS**
- Режим фикса (No data / No fix / 2D / 3D)
- Координаты (lat, lon, alt)
- DOP значения (HDOP, VDOP, PDOP)
- PPS статус (/dev/pps0)
- Спутники (used / total)

**Правая колонка: Карта неба (Skyview)**
- Полярная проекция (азимут/возвышение)
- SVG рендеринг спутников:
  - **Круг** = GPS, QZSS
  - **Квадрат** = GLONASS
  - **Треугольник △** = Galileo
  - **Треугольник ▽** = BeiDou
  - **Ромб ◇** = SBAS/WAAS
- Цвет по SNR:
  - Зелёный: ≥40 dBHz
  - Жёлтый: 30-39
  - Красный: <30
- Контур без заливки = видим, но не используется
- Легенда с расшифровкой

##### 5. Вторая строка (Sync + Clients + System)

**Синхронизация (Tracking)**
- Stratum, Reference ID, Ref time
- Смещение, Last offset, RMS offset
- Частота, Skew, Root delay/dispersion
- Leap status

**Клиенты (NTP Clients)**
- Таблица подключённых клиентов
- Хостнейм, кол-во NTP запросов, потеряно пакетов

**Система (System)**
- Uptime, температура CPU, нагрузка
- Использование памяти (прогресс-бар)
- Статус chrony, gpsd, PPS, UART

##### 6. Диаграмма (Chart)

**График смещения времени (System Offset)**
- Последние 300 точек измерений
- Ось Y: наносекунды (автомасштабирование)
- Ось X: время (HH:MM:SS)
- Сетка 4x4 с легендой
- Нулевая линия пунктиром
- Закрашенная область под графиком
- Точка и значение в конце графика

##### 7. Sourcestats

**Статистика источников**
- Таблица: Name, NP (отправлено), NR (получено), Span, Frequency, Freq Skew, Offset, StdDev

##### 8. Repair Panel (Панель управления и диагностики)

**Левая часть: Управление сервисами**
- `⏱ Принудительная синхронизация` — `makestep`
- `↺ Перезапуск gpsd` — restart GPS
- `↺ Перезапуск chrony` — restart NTP

**Правая часть: Диагностика UART**
- `📡 Сырые данные NMEA` — 20 строк с UART
- `ℹ Параметры порта` — stty + lsof вывод

Оба раздела имеют область вывода где появляются результаты команд.

#### JavaScript логика

**Основной цикл:**
```javascript
// Получение данных каждые ~15 сек
fetch('/monitor/api.php')
  .then(r => r.json())
  .then(data => render(data))
```

**SVG Skyplot рендеринг:**
```javascript
// Преобразование азимута и возвышения в x,y координаты
azel2xy(az, el) {
  r = R * (1 - el / 90);  // радиус от горизонта к зениту
  angle = (az - 90) * π / 180;
  x = CX + r * cos(angle);
  y = CY + r * sin(angle);
}

// Выбор формы спутника по GNSS ID
shape(gnss_id) {
  if (gnss_id === 6) return 'square';    // GLONASS
  if (gnss_id === 3) return 'tri_down';  // BeiDou
  if (gnss_id === 2) return 'tri_up';    // Galileo
  if (gnss_id === 1) return 'diamond';   // SBAS
  return 'circle';                        // GPS/QZSS
}

// Цвет по SNR
snrColor(snr) {
  if (snr >= 40) return '#39d98a';  // зелёный
  if (snr >= 30) return '#f5c842';  // жёлтый
  return '#ff4d6a';                  // красный
}
```

**Reach Bar (восьмибитный код):**
```javascript
reachBar(octalStr) {
  dec = parseInt(octalStr, 8);
  for (i = 7; i >= 0; i--) {
    bits.push((dec >> i) & 1);  // [1,0,1,1,1,0,1,1]
  }
  // отобразить как 8 маленьких квадратов (заполненных или пустых)
}
```

**Chart (график offset):**
- Полупрозрачная область под графиком с градиентом
- Плавная линия графика с закруглениями в углах
- Автомасштабирование по значениям с 15% запасом
- Последняя точка с координатами

---

## Требования

### Аппаратное обеспечение

- Raspberry Pi 4 (или любой Linux ARM64 с UART + GPIO)
- u-blox M10 GNSS модуль с PPS выходом (или аналог)
- Веб-сервер (Apache с PHP или nginx + php-fpm)
- Минимум 1 GB памяти, 512 MB для сервера достаточно

### Программное обеспечение

```bash
# Основное
- Linux kernel 5.10+ (с поддержкой PPS-GPIO)
- PHP 7.4+ (с модулем CLI)
- Apache 2.4+ или nginx
- chrony 4.0+
- gpsd 3.20+
- pps-tools

# Для разработки (опционально)
- git
- curl
- jq
```

### Разрешения и доступ

#### sudo для www-data

`api.php` и `action.php` требуют прав администратора:

```bash
sudo nano /etc/sudoers.d/www-ntp-monitor
```

Содержимое:
```
# NTP Monitor — разрешения для веб-сервера
www-data ALL=(ALL) NOPASSWD: /usr/bin/chronyc
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart gpsd
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart chrony
www-data ALL=(ALL) NOPASSWD: /bin/systemctl is-active
www-data ALL=(ALL) NOPASSWD: /bin/stty -F /dev/ttyAMA0*
www-data ALL=(ALL) NOPASSWD: /usr/bin/timeout *
www-data ALL=(ALL) NOPASSWD: /usr/bin/lsof /dev/ttyAMA0
```

Сохранить (Ctrl+O, Enter, Ctrl+X) и установить права:
```bash
sudo chmod 440 /etc/sudoers.d/www-ntp-monitor
sudo visudo -c  # проверить синтаксис
```

#### Групповые разрешения

```bash
# www-data должна иметь доступ к устройствам
sudo usermod -aG dialout www-data   # для UART
sudo usermod -aG gpio www-data      # для PPS GPIO
```

---

## Установка и настройка

### 1. Копирование файлов

```bash
# Через SCP
scp api.php action.php index.html root@<IP_RPi>:/var/www/html/monitor/

# Или локально
sudo mkdir -p /var/www/html/monitor
sudo cp api.php action.php index.html /var/www/html/monitor/

# Установить права
sudo chown -R www-data:www-data /var/www/html/monitor/
sudo chmod 755 /var/www/html/monitor/
sudo chmod 644 /var/www/html/monitor/*.{php,html}
```

### 2. Настройка веб-сервера

#### Apache (рекомендуется)

Убедиться, что PHP включён:
```bash
sudo a2enmod php8.2
# или php7.4, в зависимости от версии

sudo systemctl restart apache2
```

Проверить доступность:
```bash
curl -I http://localhost/monitor/api.php
# HTTP/1.1 200 OK
```

#### nginx + php-fpm

```nginx
# /etc/nginx/sites-available/ntp-monitor
server {
    listen 80;
    server_name _;
    root /var/www/html;
    
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
    
    location /monitor/ {
        index index.html;
        try_files $uri $uri/ =404;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/ntp-monitor /etc/nginx/sites-enabled/
sudo nginx -t  # проверить конфиг
sudo systemctl restart nginx
```

### 3. Проверка зависимостей

```bash
# Проверить наличие chronyc
which chronyc && chronyc tracking
# /usr/bin/chronyc
# Reference ID    : 50505300 (PPS)

# Проверить gpsd
systemctl is-active gpsd
# active

# Проверить PPS
ls -l /dev/pps0
# crw-rw-rw- 1 root dialout 251, 0 May 10 14:32 /dev/pps0

# Проверить UART
ls -l /dev/ttyAMA0
# crw-rw---- 1 root dialout 204, 64 May 10 14:32 /dev/ttyAMA0

# Тестировать www-data
sudo -u www-data php /var/www/html/monitor/api.php | jq .tracking
# {
#   "reference_id": "50505300 (PPS)",
#   "stratum": 1,
#   ...
# }
```

### 4. Первый запуск

```bash
# Открыть в браузере
http://<IP_RPi>/monitor/index.html

# Или проверить API напрямую
curl http://<IP_RPi>/monitor/api.php | jq .

# Ожидаемый результат:
# {
#   "timestamp": "2026-05-10T14:32:45Z",
#   "tracking": { ... },
#   "sources": { ... },
#   ...
# }
```

---

## Использование

### Основные сценарии

#### 1. Проверка статуса Stratum 1 сервера

Открыть `http://<IP_RPi>/monitor/` → смотреть:
- **Stratum** (должен быть 1)
- **Смещение системы** (должно быть < 500 нс для 3D GPS фикса)
- **Reference ID** (должен быть PPS для Stratum 1)
- **Leap статус** (должен быть "Normal" при корректной UTC)

#### 2. Диагностика проблем с GPS

**GPS нет фикса:**
1. Открыть раздел **GPS / GNSS**
2. Смотреть **Режим фикса** (должен быть "3D фикс")
3. Если "No fix":
   - Проверить антенну (должна быть на открытом небе)
   - Проверить подключение (UART + PPS)
   - Нажать `↺ Перезапуск gpsd` в Repair Panel

**PPS не пульсирует:**
1. Смотреть **PPS** (должно быть "✓ /dev/pps0")
2. Если "Отсутствует":
   - Проверить GPIO4 (физический пин 7)
   - Проверить `dmesg | grep pps`
   - Нажать `↺ Перезапуск chrony` в Repair Panel

#### 3. Принудительная синхронизация

Если смещение > 1 сек:
1. Нажать кнопку `⏱ Принудительная синхронизация` в Repair Panel
2. Результат появится в текстовом окне ниже
3. Смещение должно вернуться в норму за несколько секунд

#### 4. Чтение NMEA данных с GPS модуля

1. Нажать `📡 Сырые данные NMEA` в Repair Panel
2. Появятся 20 строк NMEA предложений (GNRMC, GNGGA и т.д.)
3. Это подтверждает связь с GPS модулем

**Пример NMEA вывода:**
```
$GNRMC,143245.00,A,5545.19286,N,03737.35024,E,0.049,90.12,100526,3.91,W,D*74
$GNGGA,143245.00,5545.19286,N,03737.35024,E,2,18,1.20,155.391,M,42.129,M,,*58
$GPGSA,A,3,01,02,03,05,07,10,13,17,20,24,26,27,,1.94,1.20,1.50*0E
$GNGST,143245.00,0.000,2.123,1.876,0.812,45.234,0.987,1.234,2.345*1F
```

#### 5. Мониторинг источников времени

**Таблица "Источники времени":**
- `*` = этот источник выбран (используется для синхронизации)
- `+` = комбинируется (используется в алгоритме)
- `-` = не используется (слишком плохое качество)
- `x` = ошибка (не доступен / неправильный stratum)
- `?` = неизвестно (нет данных)
- Reach = последние 8 попыток соединения (8 бит)

**Пример интерпретации:**
```
* PPS     0   4  377      1  -159ns
  ├─ Stratum=0 (refclock)
  ├─ Poll=4 (интервал опроса 2^4=16 сек)
  ├─ Reach=377 (восьмеричная) = 11111111₂ = все 8 попыток успешны
  ├─ LastRx=1 сек назад
  └─ Offset=-159ns (минус = опережает систему)
```

#### 6. Анализ спутников (Skyplot)

**Карта неба** показывает в полярных координатах:
- Центр = зенит (90° возвышение)
- Края = горизонт (0° возвышение)
- Углы = азимут (N=вверх, E=вправо, S=вниз, W=влево)

**Цветовое кодирование:**
- Зелёные спутники = хороший SNR (≥40 dBHz)
- Жёлтые = средний SNR (30-39)
- Красные = слабый SNR (<30)
- Контур без заливки = видим, но не используется

**Рекомендации:**
- Минимум 4 спутника для 3D фикса
- Минимум 8-12 спутников для надёжности
- GDOP < 2 (HDOP < 1.5 + VDOP < 1.5)

---

## API Reference

### Основной endpoint

```
GET /monitor/api.php
```

Без параметров. Возвращает полный JSON со всеми метриками.

**Пример:**
```bash
curl http://192.168.1.10/monitor/api.php | jq .
```

### Action endpoint

```
GET /monitor/action.php?action=<ACTION>
POST /monitor/action.php (в body: action=<ACTION>)
```

**Параметры:**
- `action` — одна из: `makestep`, `restart_gpsd`, `restart_chrony`, `raw_port`, `port_info`

#### Ответ API (api.php)

**Успех:**
```json
{
  "timestamp": "2026-05-10T14:32:45+00:00",
  "timestamp_ms": 1715338365123,
  "tracking": {
    "reference_id": "50505300 (PPS)",
    "stratum": 1,
    "system_time": 0.000000477,
    "status": "good"
  },
  "sources": { ... },
  "gpsd": { ... },
  "system": { ... }
}
```

**Ошибка chronyc:**
```json
{
  "tracking": {
    "error": "chronyc tracking failed"
  }
}
```

#### Ответ Action (action.php)

**Успех (makestep):**
```json
{
  "success": true,
  "output": "200 OK\nClock was stepped by 0.000000042 seconds"
}
```

**Успех (restart):**
```json
{
  "success": true,
  "output": "gpsd active"
}
```

**Ошибка:**
```json
{
  "success": false,
  "output": "Permission denied (check sudoers)"
}
```

### Примеры запросов

#### 1. Получить весь JSON в терминале

```bash
curl http://192.168.1.10/monitor/api.php | jq .
```

#### 2. Получить только tracking

```bash
curl http://192.168.1.10/monitor/api.php | jq .tracking
```

#### 3. Получить список источников

```bash
curl http://192.168.1.10/monitor/api.php | \
  jq '.sources.list[] | {name, state, offset}'

# Вывод:
# {
#   "name": "PPS",
#   "state": "*",
#   "offset": "-159ns"
# }
```

#### 4. Проверить GPS координаты

```bash
curl http://192.168.1.10/monitor/api.php | \
  jq '.gpsd.fix | {mode_label, lat, lon, alt}'
```

#### 5. Выполнить makestep

```bash
curl "http://192.168.1.10/monitor/action.php?action=makestep" | jq .
```

#### 6. Проверить UART

```bash
curl "http://192.168.1.10/monitor/action.php?action=raw_port" | jq -r .output
```

#### 7. Получить информацию о порте

```bash
curl "http://192.168.1.10/monitor/action.php?action=port_info" | jq -r .output
```

---

## Панель управления

### Кнопки управления и диагностики

#### Управление сервисами

| Кнопка | Команда | Описание |
|--------|---------|---------|
| `⏱ Принудительная синхронизация` | `makestep` | Немедленное выравнивание часов (для больших ошибок > 1 сек) |
| `↺ Перезапуск gpsd` | `systemctl restart gpsd` | Перезагрузка GPS демона |
| `↺ Перезапуск chrony` | `systemctl restart chrony` | Перезагрузка NTP демона |

#### Диагностика UART

| Кнопка | Команда | Описание |
|--------|---------|---------|
| `📡 Сырые данные NMEA` | `stty + timeout 3 cat /dev/ttyAMA0` | Прямое чтение NMEA с порта (20 строк, 3 сек) |
| `ℹ Параметры порта` | `stty + lsof` | Текущие настройки UART и какой процесс его использует |

### Обработка результатов

После выполнения команды:
- **Зелёный текст** = успех (в `repair-output` или `port-output`)
- **Красный текст** = ошибка
- **Spinning loader** = выполняется (с текстом "Выполняется...")
- Все кнопки блокируются на время выполнения
- Окно вывода может быть прокручено (max-height: 200px)

---

## Настройка безопасности

### 1. Ограничение доступа по IP

#### Apache

```apache
# /etc/apache2/sites-enabled/000-default.conf
<Directory /var/www/html/monitor>
    <RequireAll>
        Require ip 192.168.0.0/24
        Require ip 127.0.0.1
    </RequireAll>
</Directory>
```

```bash
sudo systemctl reload apache2
```

#### nginx

```nginx
# /etc/nginx/sites-enabled/default
location /monitor/ {
    allow 192.168.0.0/24;
    allow 127.0.0.1;
    deny all;
}
```

```bash
sudo nginx -s reload
```

### 2. HTTPS (TLS/SSL)

#### Let's Encrypt (бесплатный сертификат)

```bash
sudo apt install certbot python3-certbot-apache

sudo certbot --apache -d ntp.example.com

# Автоматическое обновление
sudo systemctl enable certbot.timer
```

### 3. Аутентификация API

Добавить в начало `api.php` и `action.php`:

```php
<?php
// Базовая HTTP аутентификация
if (!isset($_SERVER['PHP_AUTH_USER']) || 
    $_SERVER['PHP_AUTH_USER'] !== 'admin' ||
    $_SERVER['PHP_AUTH_PW'] !== 'your-password') {
    header('WWW-Authenticate: Basic realm="NTP Monitor"');
    http_response_code(401);
    exit('401 Unauthorized');
}
// ... остальной код
?>
```

---

## Решение проблем

### API не доступен (HTTP 500 / 404)

```bash
# 1. Проверить файлы
ls -l /var/www/html/monitor/
# -rw-r--r-- 1 www-data www-data 8234 May 10 12:00 api.php

# 2. Проверить права
sudo chown www-data:www-data /var/www/html/monitor/*
sudo chmod 755 /var/www/html/monitor/
sudo chmod 644 /var/www/html/monitor/*.php

# 3. Проверить логи Apache
sudo tail -n 50 /var/log/apache2/error.log

# 4. Проверить синтаксис PHP
php -l /var/www/html/monitor/api.php

# 5. Тестировать напрямую
php /var/www/html/monitor/api.php | head -c 200
```

### action.php возвращает "Permission denied"

```bash
# 1. Проверить sudoers
sudo visudo -c  # проверить синтаксис

# 2. Проверить содержимое
sudo cat /etc/sudoers.d/www-ntp-monitor

# 3. Убедиться что www-data в dialout группе
id www-data
# uid=33(www-data) gid=33(www-data) groups=33(www-data),20(dialout)

# 4. Перезагрузить Apache
sudo systemctl restart apache2
```

### chronyc: Could not get online status

```bash
# 1. Проверить, запущен ли chrony
sudo systemctl status chrony

# 2. Перезагрузить chrony
sudo systemctl restart chrony

# 3. Добавить www-data в группу
sudo usermod -aG _chrony www-data
sudo systemctl restart apache2

# 4. Проверить сокет
sudo ls -la /run/chrony/
```

### gpsd не отправляет данные

```bash
# 1. Проверить статус
sudo systemctl status gpsd

# 2. Проверить UART
sudo stty -F /dev/ttyAMA0 38400 raw && sudo timeout 2 cat /dev/ttyAMA0
# Должны быть NMEA строки: $GNRMC, $GNGGA

# 3. Перезагрузить
sudo systemctl restart gpsd

# 4. Посмотреть детали
sudo gpsd -N -D 4 -n -F /run/gpsd.sock /dev/ttyAMA0
```

### PPS не пульсирует

```bash
# 1. Проверить устройство
ls /dev/pps0

# 2. Протестировать
sudo ppstest /dev/pps0
# source 0 - assert ...

# 3. Проверить логи
sudo dmesg | grep pps

# 4. Если нет — добавить overlay
sudo nano /boot/firmware/config.txt
# dtoverlay=pps-gpio,gpiopin=4
sudo reboot
```

### GPS фикс не получается

```bash
# 1. Проверить антенну (открытое небо, минимум 5 мин)

# 2. Проверить питание модуля (3.3V)

# 3. Проверить связь
sudo cgps -s
# Должны быть спутники

# 4. Перезагрузить
sudo systemctl restart gpsd

# 5. Холодный старт (если никогда не включалась)
sudo gpsctl -x '*ARDX,C,U,0*2F'  # Clear ephemeris
sudo systemctl restart gpsd
```

### Высокое смещение (>1 мкс)

```bash
# 1. Проверить sources
chronyc sources -v

# 2. Проверить что PPS выбран (*)

# 3. Проверить GPS фикс
cgps -s

# 4. Выполнить makestep (через Repair Panel)

# 5. Подождать 15-20 минут на холодный старт

# 6. Проверить качество PPS
sudo ppstest /dev/pps0
```

### Frontend не загружается

```bash
# 1. Проверить консоль браузера (F12)

# 2. Очистить кеш (Ctrl+Shift+Delete)

# 3. Проверить CORS
curl -i http://192.168.1.10/monitor/api.php | grep -i "Access-Control"

# 4. Проверить Content-Type
curl -i http://192.168.1.10/monitor/api.php | grep -i "Content-Type"

# 5. Запустить локальный сервер
cd /var/www/html/monitor/
python3 -m http.server 8000
# http://localhost:8000/
```

---

## Структура файлов

```
monitor/
├── api.php            # REST API для сбора метрик (~8 KB)
├── action.php         # API для выполнения команд (~2.5 KB)
├── index.html         # Веб-интерфейс (CSS + JS встроены) (~22 KB)
└── README.md          # Эта документация
```

**Размеры:**
- `api.php` — ~8 KB
- `action.php` — ~2.5 KB
- `index.html` — ~22 KB (включая CSS и JS)
- **Всего** — ~32.5 KB (минифицировано ~18 KB)

---

## Производительность

### Требования к ресурсам

| Ресурс | Требование | Примечание |
|--------|-----------|-----------|
| CPU | < 5% | При обновлении каждые 15 сек |
| Память | ~ 40 MB | PHP + Apache |
| Диск | ~ 50 KB | Три файла + кеш браузера |
| Пропускная способность | < 500 KB/ч | ~2 KB за запрос, каждые 15 сек |

### Оптимизация

**Снизить частоту обновления:**
```javascript
// В index.html
setInterval(refresh, 30000);  // было 15000 (15 сек)
```

**Отключить график:**
```javascript
// Закомментировать в render()
// renderChart();
```

**Отключить skyplot:**
```javascript
// Закомментировать в renderGps()
// renderSkyview(sats);
```

---

## Лицензия

Этот проект предоставляется как есть для использования в образовательных и любительских целях.

---

## Ссылки

- [chrony документация](https://chrony.troglobit.com/)
- [gpsd документация](https://gpsd.io/)
- [u-blox M10 datasheet](https://www.u-blox.com/en/product/m10-series)
- [NTP Protocol RFC 5905](https://tools.ietf.org/html/rfc5905)
- [Raspberry Pi GPIO](https://www.raspberrypi.com/documentation/computers/os.html#gpio)
- [PHP exec() функция](https://www.php.net/manual/en/function.exec.php)

---

**Версия:** 2.0 (обновлено 2026-05-10)  
**Автор:** @NoIDXMV6  
**Репо:** [chrony-ublox-M10](https://github.com/NoIDXMV6/chrony-ublox-M10)  
**Статус:** ✅ Актуально — все компоненты (api.php, action.php, index.html) документированы
