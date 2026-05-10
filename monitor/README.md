# NTP Monitor — Веб-интерфейс мониторинга Stratum 1 сервера

Полнофункциональная система мониторинга в реальном времени для Stratum 1 NTP сервера на Raspberry Pi с u-blox M10 GNSS модулем.

---

## 📋 Содержание

1. [Архитектура системы](#архитектура-системы)
2. [Компоненты](#компоненты)
3. [Требования](#требования)
4. [Установка и настройка](#установка-и-настройка)
5. [Использование](#использование)
6. [API Reference](#api-reference)
7. [Настройка безопасности](#настройка-безопасности)
8. [Решение проблем](#решение-проблем)

---

## Архитектура системы

```
┌─────────────────────────────────────────────────────────────┐
│                    Raspberry Pi 4                           │
│                                                              │
│  ┌──────────┐  ┌────────────┐  ┌──────────────────┐        │
│  │ gpsd     │  │  chrony    │  │  Apache + PHP    │        │
│  │ ────────│  │ ──────────│  │ ─────────────────│        │
│  │• GNSS   │  │• NTP      │  │• REST API        │        │
│  │• PPS    │  │  daemon   │  │• Live Web UI     │        │
│  └────┬────┘  └────┬──────┘  └────────┬─────────┘        │
│       │            │                   │                   │
│  /dev/ttyAMA0  /dev/pps0        :80/api.php               │
│  /run/shm      chronyc          :80/index.html            │
│                                                              │
└─────────────────────────────────────────────────────────────┘
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
   - Автоматическое обновление каждые ~2 сек

---

## Компоненты

### 1. Backend: `api.php`

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

#### Выходной JSON

```json
{
  "timestamp": "2026-05-10T14:32:45+00:00",
  "timestamp_ms": 1715338365123,
  "tracking": {
    "reference_id": "50505300 (PPS)",
    "stratum": 1,
    "system_time": 0.000000477,
    "system_time_fmt": {
      "value": "477",
      "unit": "нс"
    },
    "status": "good"
  },
  "sources": {
    "list": [
      {
        "state": "*",
        "state_label": "Выбран",
        "name": "PPS",
        "stratum": 0,
        "offset": "-159ns",
        "is_refclock": true,
        "status": "good"
      }
    ],
    "status": "good"
  },
  "gpsd": {
    "available": true,
    "pps": true,
    "service_status": "active",
    "fix": {
      "mode": 3,
      "mode_label": "3D фикс",
      "lat": 55.753215,
      "lon": 37.622504,
      "alt": 155.4,
      "ept": 0.123
    },
    "sky": {
      "total": 24,
      "used": 18,
      "hdop": 1.2,
      "satellites": [
        {
          "prn": "01",
          "el": 45,
          "az": 90,
          "ss": 42,
          "used": true,
          "gnss": 0
        }
      ]
    }
  },
  "system": {
    "hostname": "ntp-server",
    "temp": 52.3,
    "uptime": "12d 03ч 45м",
    "load": [0.45, 0.38, 0.42],
    "memory": {
      "total": 3933208,
      "used": 1245609,
      "percent": 31.6
    },
    "chrony_active": true,
    "gpsd_active": true,
    "pps_device": true
  }
}
```

#### Обработка ошибок

```php
// Если chronyc не доступен
"tracking": {
  "error": "chronyc tracking failed"
}

// Если gpsd не запущен
"gpsd": {
  "available": false,
  "pps": false,
  "service_status": "inactive"
}
```

#### Безопасность

- **sudo без пароля** требуется для `chronyc clients` и `chronyc serverstats`
- **CORS включён**: `Access-Control-Allow-Origin: *`
- **Кеширование отключено**: всегда свежие данные
- **Защита от инъекций**: `escapeshellarg()` для всех параметров

---

### 2. Frontend: `index.html`

**Язык:** HTML5 + CSS3 + JavaScript (vanilla, без фреймворков)  
**Размер:** ~20 KB (минифицировано ~12 KB)

#### Стили и тема

**Цветовая схема:** Темная, с неоновыми акцентами (cyberpunk-like)

```css
--bg:        #0a0e14    /* чёрный фон */
--accent:    #00d4ff    /* голубой неон */
--green:     #39d98a    /* зелёный статус OK */
--yellow:    #f5c842    /* жёлтый статус WARNING */
--red:       #ff4d6a    /* красный статус ERROR */
--mono:      'JetBrains Mono', monospace
--sans:      'Space Grotesk', sans-serif
```

#### Макет

```
┌─────────────────────────────────────────────────────────────┐
│ NTP//MON    ntp-server    [services]  Обновлено: 14:32:45   │
├─────────────────────────────────────────────────────────────┤
│
│  [Stratum: 1] [Смещение: 477нс] [RMS: 182нс] [Частота: 3.142ppm]
│  [Спутники: 18/24] [Leap: Normal] [Онлайн: 3]
│
├─────────────────────────────────────────────────────────────┤
│
│  Источники        │  GPS / GNSS      │  Карта неба
│  ─────────────────┼──────────────────┼─────────────────
│  * PPS        0ns │  3D фикс        │  [SVG skyplot]
│  + GPS        +5ns│  55.753°N       │  ★ GPS
│  - pool.ntp  -2ms │  37.622°E       │  ◆ SBAS
│                   │  155.4м         │  ▲ Galileo
│                   │  HDOP: 1.2      │
│
├─────────────────────────────────────────────────────────────┤
│
│  Синхронизация    │  Клиенты        │  Система
│  ─────────────────┼─────────────────┼──────────────────
│  Stratum: 1      │  1 клиент       │  Temp: 52.3°C
│  Ref time: xxx   │  192.168.1.10  │  Uptime: 12d 3ч
│  Offset: 477ns   │  8 пакетов NTP │  Load: 0.45
│  Freq: 3.142ppm  │                 │  Mem: 31.6%
│
├─────────────────────────────────────────────────────────────┤
│  Статистика источников (sourcestats)
│  ────────────────────────────────────────────────────────────
│  Name      NP  NR  Span     Freq    FreqSkew  Offset  StdDev
│  PPS       50  50  3456s  -3.141   0.025    -159ns   5ns
│  GPS       40  40  2987s  +0.453   0.012    +215ns   8ns
│
└─────────────────────────────────────────────────────────────┘
```

#### Ключевые элементы

##### Header
- **Логотип** NTP//MON с акцентом
- **Хостнейм** сервера из `/proc/hostname`
- **Сервис-пилюли** (chrony/gpsd ON/OFF)
- **Время обновления** с кнопкой Refresh

##### Metric Cards (верхний ряд)
Карточки метрик с цветовой индикацией:
- `good` — зелёная полоса сверху
- `warn` — жёлтая полоса
- `error` — красная полоса

##### Блоки (grid 3x3)

**Левая колонка:**
- **Источники времени** (таблица с состояниями)
  - `*` = выбран (зелёный)
  - `+` = комбинируется (голубой)
  - `x` = ошибка (красный)
  - `?` = недоступен (серый)
  - Reach bar (8 битов, последние пинги)

**Средняя колонка:**
- **GPS / GNSS** (список параметров)
  - Режим фикса (No data / No fix / 2D / 3D)
  - Координаты (lat, lon, alt)
  - DOP значения (HDOP, VDOP, PDOP)
  - PPS статус (/dev/pps0)

**Правая колонка:**
- **Карта неба (Skyview)**
  - Полярная проекция (az/el)
  - SVG рендеринг спутников:
    - **Круг** = GPS, QZSS
    - **Квадрат** = GLONASS
    - **Треугольник вверх** = Galileo
    - **Треугольник вниз** = BeiDou
    - **Ромб** = SBAS/WAAS
  - **Цвет** по SNR:
    - Зелёный: ≥40 dBHz
    - Жёлтый: 30-39
    - Красный: <30
  - **Контур без заливки** = видим, но не используется
  - Легенда с расшифровкой

**Нижняя строка:**
- **Синхронизация** (tracking data)
- **Клиенты** (NTP клиенты, подключённые к серверу)
- **Система** (темп, uptime, нагрузка, память)

**Самый низ:**
- **Sourcestats** (таблица со статистикой источников)

#### JavaScript логика

```javascript
// Основной цикл
setInterval(fetch('/api.php'), 2000);

// Обновление элементов без перезагрузки страницы
document.getElementById('cards-row').innerHTML = ...;
document.getElementById('body-sources').innerHTML = ...;

// SVG рендеринг skyplot
// Азимут + возвышение → x,y на круге
function azel2xy(az, el) {
  r = R * (1 - el / 90);  // радиус от горизонта к зениту
  angle = (az - 90) * π / 180;
  x = CX + r * cos(angle);
  y = CY + r * sin(angle);
}

// Reach bits (восьмибитный код охвата)
function reachBits(octalStr) {
  bits = [];
  for (i=7; i>=0; i--) {
    bits.push((parseInt(octalStr, 8) >> i) & 1);
  }
  return bits;  // [1,0,1,1,1,0,1,1]
}

// Автоматическое обновление и error handling
if (!response.ok) {
  showError('API недоступен');
} else {
  hideLoader();
  render(data);
}
```

---

## Требования

### Аппаратное обеспечение

- Raspberry Pi 4 (или любой Linux ARM64 с UART + GPIO)
- u-blox M10 GNSS модуль с PPS выходом (или аналог)
- Веб-сервер (Apache с PHP или nginx + php-fpm)

### Программное обеспечение

```bash
# Основное
- Linux kernel 5.10+ (с поддержкой PPS-GPIO)
- PHP 7.4+
- Apache 2.4+ или nginx
- chrony 4.0+
- gpsd 3.20+
- pps-tools

# Для разработки (опционально)
- git
- curl
```

### Разрешения и доступ

#### sudo для www-data

`api.php` требует прав администратора для выполнения некоторых команд chronyc:

```bash
sudo nano /etc/sudoers.d/www-ntp-monitor
```

Содержимое:
```
www-data ALL=(ALL) NOPASSWD: /usr/bin/chronyc
```

Сохранить (Ctrl+O, Enter, Ctrl+X):
```bash
sudo chmod 440 /etc/sudoers.d/www-ntp-monitor
```

#### SELinux / AppArmor

Если используется, добавить разрешение для Apache на чтение `/proc/uptime` и `/sys/class/thermal`:

**Ubuntu/Debian (AppArmor):**
```bash
sudo nano /etc/apparmor.d/usr.sbin.apache2
# Добавить:
# /proc/uptime r,
# /proc/meminfo r,
# /sys/class/thermal/thermal_zone0/temp r,
sudo systemctl reload apparmor
```

**CentOS/RHEL (SELinux):**
```bash
sudo setsebool -P httpd_can_network_connect on
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/run/chrony(/.*)?"
sudo restorecon -Rv /run/chrony
```

---

## Установка и настройка

### 1. Копирование файлов

```bash
# Скопировать на Raspberry Pi
scp api.php index.html root@<IP_RPi>:/var/www/html/monitor/

# Или если репо уже клонирован:
sudo cp api.php index.html /var/www/html/monitor/
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

```bash
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
sudo systemctl restart nginx
```

### 3. Проверка зависимостей

```bash
# Проверить наличие chronyc
which chronyc
# /usr/bin/chronyc

# Проверить gpsd
systemctl is-active gpsd
# active

# Проверить PPS
ls -l /dev/pps0
# crw-rw-rw- 1 root dialout 251, 0 May 10 14:32 /dev/pps0

# Проверить UART
ls -l /dev/ttyAMA0
# crw-rw---- 1 root dialout 204, 64 May 10 14:32 /dev/ttyAMA0
```

### 4. Настройка прав доступа

```bash
# www-data должен иметь доступ к chronyc
sudo usermod -aG dialout www-data
sudo usermod -aG gpio www-data  # если необходимо

# Проверить:
sudo -u www-data chronyc tracking
# Reference ID    : 50505300 (PPS)
# Stratum         : 1
# ✓ Работает
```

### 5. Защита API (опционально)

Добавить аутентификацию в `api.php`:

```php
<?php
// Базовая HTTP аутентификация
if (!isset($_SERVER['PHP_AUTH_USER']) || 
    $_SERVER['PHP_AUTH_USER'] !== 'admin' ||
    $_SERVER['PHP_AUTH_PW'] !== 'secret_password') {
    header('WWW-Authenticate: Basic realm="NTP Monitor"');
    header('HTTP/1.0 401 Unauthorized');
    exit('401 Unauthorized');
}
// ... остальной код
?>
```

Или через токен:

```php
<?php
$token = $_GET['token'] ?? '';
if ($token !== 'your-secret-token-here') {
    http_response_code(403);
    exit('Forbidden');
}
// ... остальной код
?>
```

### 6. Первый запуск

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
- **Leap статус** (должен быть "Normal" при корректной UTC)

#### 2. Диагностика проблем с GPS

**GPS нет фикса:**
1. Открыть раздел **GPS / GNSS**
2. Смотреть **Режим фикса** (должен быть "3D фикс")
3. Если "No fix":
   - Проверить антенну (должна быть на открытом небе)
   - Проверить подключение (UART + PPS)
   - `sudo systemctl restart gpsd`

**PPS не пульсирует:**
1. Смотреть **PPS** (должно быть "✓ /dev/pps0")
2. Если "Отсутствует":
   - Проверить GPIO4 (физический пин 7)
   - Проверить `dmesg | grep pps`
   - Перезагрузиться: `sudo reboot`

#### 3. Мониторинг источников

**Таблица "Источники времени":**
- `*` = этот источник выбран (используется для синхронизации)
- `+` = комбинируется (используется в алгоритме)
- `-` = не используется (слишком плохое качество)
- `x` = ошибка (не доступен)
- `?` = неизвестно (нет данных)
- Reach = последние 8 попыток соединения (8 бит, каждый бит = одна попытка)

**Пример:**
```
* PPS     0   4  377      1  -159ns
  ├─ Stratum=0 (refclock)
  ├─ Poll=4 (интервал опроса 2^4=16 сек)
  ├─ Reach=377 (8) = 11111111₂ = все последние 8 попыток успешны
  ├─ LastRx=1 сек назад
  └─ Offset=-159ns (минус = опережает)
```

#### 4. Анализ спутников (Skyplot)

**Карта неба** показывает в полярных координатах:
- Центр = зенит (90° возвышение)
- Края = горизонт (0° возвышение)
- Углы = азимут (N=вверх, E=вправо, S=вниз, W=влево)

**Интерпретация:**
- Зелёные спутники = хороший SNR (≥40 dBHz)
- Жёлтые = средний SNR (30-39)
- Красные = слабый SNR (<30)
- Контур без заливки = видим, но не используется

**Рекомендации:**
- Минимум 4 спутника для 3D фикса
- Минимум 8-12 спутников для надёжности
- GDOP < 2 (HDOP < 1.5 + VDOP < 1.5)

#### 5. Анализ нагрузки и теплоотвода

**Система:**
- **Temp** = температура CPU (норма < 60°C, макс < 85°C)
- **Load** = средняя нагрузка за 1мин / 5мин / 15мин
- **Mem** = использование оперативной памяти

**При нагреве:**
```bash
# Снизить частоту обновления на frontend
# В index.html изменить:
setInterval(fetch, 5000);  // было 2000 (2 сек)

# Или отключить автоматическое обновление
// clearInterval(refreshTimer);
```

---

## API Reference

### Endpoint

```
GET /monitor/api.php
```

### Ответ

```json
{
  "timestamp": "ISO 8601 datetime",
  "timestamp_ms": "Unix timestamp в миллисекундах",
  
  "tracking": {
    "reference_id": "строка (PPS, GPS, pool.ntp.org и т.д.)",
    "stratum": "число (1 = Stratum 1)",
    "system_time": "число (секунды, обычно минус от 1e-9 до 1)",
    "system_time_fmt": {
      "value": "отформатированное число",
      "unit": "нс / мкс / мс / с"
    },
    "status": "good | warning | error"
  },
  
  "sources": {
    "list": [
      {
        "state": "* | + | - | x | ~ | ?",
        "state_label": "русское описание",
        "name": "имя источника",
        "stratum": "число или null",
        "offset": "строка с единицами (нс, мкс, мс)",
        "is_refclock": "boolean (GPS/PPS/etc)",
        "is_noselect": "boolean (не может быть выбран)"
      }
    ],
    "status": "good | warning | error",
    "raw": "сырой вывод chronyc"
  },
  
  "sourcestats": {
    "list": [
      {
        "name": "имя источника",
        "np": "число пакетов отправлено",
        "nr": "число пакетов получено",
        "span": "временной диапазон",
        "frequency": "��мещение частоты в ppm",
        "freq_skew": "изменение частоты",
        "offset": "среднее смещение",
        "std_dev": "стандартное отклонение"
      }
    ],
    "raw": "сырой вывод chronyc"
  },
  
  "activity": {
    "online": "число онлайн источников",
    "offline": "число оффлайн источников",
    "burst": "число в режиме burst",
    "status": "good | warning | error"
  },
  
  "clients": {
    "list": [
      {
        "hostname": "IP или имя хоста",
        "ntp": "число NTP запросов",
        "drop": "число потеряных пакетов"
      }
    ],
    "count": "число клиентов",
    "status": "good | warning | error",
    "raw": "сырой вывод chronyc"
  },
  
  "serverstats": {
    "packets_received": "число получено пакетов",
    "packets_dropped": "число потеряно пакетов",
    "cmd_packets_received": "число полученных команд",
    "nts_ke_connections": "число NTS-KE соединений",
    "status": "good | warning | error"
  },
  
  "gpsd": {
    "available": "boolean",
    "service_status": "active | inactive | failed",
    "pps": "boolean (есть ли /dev/pps0)",
    "fix": {
      "mode": "0 | 1 | 2 | 3",
      "mode_label": "Нет данных | Нет фикса | 2D фикс | 3D фикс",
      "time": "ISO 8601 UTC время",
      "lat": "число (градусы, 6 знаков)",
      "lon": "число (градусы, 6 знаков)",
      "alt": "число (метры, 1 знак)",
      "ept": "погрешность времени (секунды)",
      "epx": "погрешность долготы",
      "epy": "погрешность широты",
      "epv": "погрешность высоты"
    },
    "sky": {
      "total": "число видимых спутников",
      "used": "число используемых спутников",
      "hdop": "горизонтальное точность (число)",
      "vdop": "вертикальное (число)",
      "pdop": "полная (число)",
      "satellites": [
        {
          "prn": "номер спутника (строка)",
          "el": "возвышение в градусах (число)",
          "az": "азимут в градусах (число)",
          "ss": "SNR (signal strength) в dBHz (число)",
          "used": "используется ли этот спутник (boolean)",
          "gnss": "система (0=GPS, 1=SBAS, 2=Galileo, 3=BeiDou, 5=QZSS, 6=GLONASS)"
        }
      ]
    }
  },
  
  "system": {
    "hostname": "имя хоста",
    "temp": "температура CPU в °C (число или null)",
    "uptime": "строка формата '12d 03ч 45м'",
    "uptime_seconds": "число секунд",
    "load": "[1мин, 5мин, 15мин] массив чисел",
    "memory": {
      "total": "кБ",
      "available": "кБ",
      "used": "кБ",
      "percent": "процент использования"
    },
    "chrony_active": "boolean",
    "gpsd_active": "boolean",
    "pps_device": "boolean (есть ли /dev/pps0)",
    "uart_device": "boolean (есть ли /dev/ttyAMA0)"
  }
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
curl http://192.168.1.10/monitor/api.php | jq '.sources.list[] | {name, state, offset}'

# Вывод:
# {
#   "name": "PPS",
#   "state": "*",
#   "offset": "-159ns"
# }
# {
#   "name": "GPS",
#   "state": "+",
#   "offset": "+215ns"
# }
```

#### 4. Проверить GPS координаты

```bash
curl http://192.168.1.10/monitor/api.php | jq '.gpsd.fix | {mode_label, lat, lon, alt}'

# Вывод:
# {
#   "mode_label": "3D фикс",
#   "lat": 55.753215,
#   "lon": 37.622504,
#   "alt": 155.4
# }
```

#### 5. Получить системную информацию

```bash
curl http://192.168.1.10/monitor/api.php | jq '.system | {hostname, temp, uptime, "load_1min": .load[0]}'

# Вывод:
# {
#   "hostname": "ntp-server",
#   "temp": 52.3,
#   "uptime": "12d 03ч 45м",
#   "load_1min": 0.45
# }
```

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

Перезагрузить:
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

### 2. HTTPS (TLS/SSL)

#### Let's Encrypt (бесплатный сертификат)

```bash
sudo apt install certbot python3-certbot-apache

sudo certbot --apache -d ntp.example.com

# Автоматическое обновление сертификата
sudo systemctl enable certbot.timer
```

#### Самоподписанный сертификат

```bash
sudo openssl req -x509 -nodes -days 365 \
  -newkey rsa:2048 \
  -keyout /etc/ssl/private/ntp-monitor.key \
  -out /etc/ssl/certs/ntp-monitor.crt

# Apache
sudo a2enmod ssl
sudo nano /etc/apache2/sites-available/default-ssl.conf
# SSLCertificateFile /etc/ssl/certs/ntp-monitor.crt
# SSLCertificateKeyFile /etc/ssl/private/ntp-monitor.key
sudo a2ensite default-ssl.conf
sudo systemctl reload apache2
```

### 3. Логирование и мониторинг

#### Включить логирование Apache

```bash
sudo tail -f /var/log/apache2/access.log
```

Выставить уровень логирования в `api.php`:

```php
<?php
// Логирование запросов
error_log("API запрос от {$_SERVER['REMOTE_ADDR']} в " . date('c'));

// Логирование ошибок chronyc
if ($r['code'] !== 0) {
    error_log("chronyc ошибка: {$r['output']}");
    syslog(LOG_WARNING, "NTP Monitor API: chronyc failed");
}
?>
```

---

## Решение проблем

### API не доступен (HTTP 500 / 404)

```bash
# 1. Проверить файл
ls -l /var/www/html/monitor/api.php
# -rw-r--r-- 1 root root 8234 May 10 12:00 api.php

# 2. Проверить права
sudo chown www-data:www-data /var/www/html/monitor/
sudo chmod 755 /var/www/html/monitor/
sudo chmod 644 /var/www/html/monitor/*.php

# 3. Проверить логи Apache
sudo tail -n 50 /var/log/apache2/error.log

# 4. Проверить синтаксис PHP
php -l /var/www/html/monitor/api.php

# 5. Тестировать напрямую
php /var/www/html/monitor/api.php | head -c 100
```

### chronyc: Could not get online status

```bash
# Проверить, запущен ли chrony
sudo systemctl status chrony

# Перезагрузить chrony
sudo systemctl restart chrony

# Проверить сокет chrony
sudo ls -l /run/chrony/

# Добавить www-data в группу _chrony
sudo usermod -aG _chrony www-data
sudo systemctl restart apache2
```

### gpsd не отправляет данные

```bash
# 1. Проверить статус
sudo systemctl status gpsd

# 2. Посмотреть процесс
ps aux | grep gpsd
# Должно быть: /usr/sbin/gpsd -N -D 4 /dev/ttyAMA0

# 3. Проверить UART
sudo stty -F /dev/ttyAMA0 38400 raw && sudo timeout 2 cat /dev/ttyAMA0
# Должны быть NMEA строки: $GNRMC, $GNGGA и т.д.

# 4. Перезагрузить gpsd
sudo systemctl restart gpsd

# 5. Посмотреть детали
sudo gpsd -N -D 4 -n -F /run/gpsd.sock /dev/ttyAMA0
```

### PPS не пульсирует

```bash
# 1. Проверить наличие устройства
ls /dev/pps0

# 2. Протестировать PPS
sudo ppstest /dev/pps0
# Должны быть строки: source 0 - assert ...

# 3. Проверить оверлей в device tree
sudo dtoverlay -l | grep pps

# 4. Проверить логи ядра
sudo dmesg | grep pps
# Должно быть: pps_gpio_register: registered PPS source pps0

# 5. Если нет — добавить в /boot/firmware/config.txt:
#    dtoverlay=pps-gpio,gpiopin=4
# И перезагрузиться
sudo reboot
```

### GPS фикс не получается (No fix)

```bash
# 1. Проверить антенну
# → Поместить её на открытом небе с видом на небо
# → Жди 2-5 минут для холодного старта

# 2. Проверить питание модуля
# → Убедиться, что 3.3V подан корректно
# → Проверить светодиод на модуле (должен мигать при спутниках)

# 3. Проверить UART
sudo cgps -s
# Должны быть спутники в таблице

# 4. Перезагрузить modemu
sudo systemctl restart gpsd

# 5. Проверить логи GNSS модуля
sudo gpsctl -h -x 'version'  # только для SIRF и др.
```

### Высокое смещение (>1 мкс) или статус WARNING/ERROR

```bash
# 1. Проверить источники
chronyc sources -v
# Убедиться, что выбран PPS (*)

# 2. Если выбран GPS вместо PPS
# → GPS/PPS нужна синхронизация (холодный старт может занять время)
# → Проверить, есть ли GPS фикс: cgps -s

# 3. Если нет GPS/PPS
# → Вернуться к разделам выше

# 4. Проверить качество PPS
sudo ppstest /dev/pps0
# Должны быть субсекундные изменения в assert/clear times

# 5. Если chrony долго подстраивается
# → Подождать 10-15 минут
# → Выполнить: chronyc makestep
```

### Перегрев (Temp > 60°C)

```bash
# 1. Снизить частоту CPU
echo "powersave" | sudo tee /sys/devices/system/cpu/cpu0/cpufreq/scaling_governor

# 2. Добавить радиатор на CPU

# 3. Снизить частоту обновления фронтенда
# В index.html:
// setInterval(refresh, 5000);  // было 2000

# 4. Выключить графику (skyplot)
// renderSkyview([]);  // закомментировать

# 5. Проверить температурный порог chrony
sudo chronyc waitsync 60 60
```

### Frontend не загружается (белый экран)

```bash
# 1. Проверить консоль браузера (F12)
# Смотреть на ошибки JavaScript и CORS

# 2. Очистить кеш браузера
# Ctrl+Shift+Delete → выбрать период → очистить

# 3. Проверить CORS заголовки
curl -i http://192.168.1.10/monitor/api.php | grep -i "Access-Control"
# Access-Control-Allow-Origin: *

# 4. Проверить Content-Type
curl -i http://192.168.1.10/monitor/api.php | grep -i "Content-Type"
# Content-Type: application/json; charset=utf-8

# 5. Запустить локальный веб-сервер
cd /var/www/html/monitor/
python3 -m http.server 8000
# http://localhost:8000/
```

---

## Интеграция с системой мониторинга

### Prometheus

Добавить `api.php` обёртку, которая выдаёт метрики в формате Prometheus:

```bash
# /var/www/html/monitor/metrics.php
<?php
header('Content-Type: text/plain; charset=utf-8');

$data = json_decode(file_get_contents('api.php'), true);

// Chrony метрики
echo "# HELP chrony_stratum Current stratum\n";
echo "chrony_stratum " . ($data['tracking']['stratum'] ?? 0) . "\n";

echo "# HELP chrony_system_time_seconds System time offset\n";
echo "chrony_system_time_seconds " . ($data['tracking']['system_time'] ?? 0) . "\n";

// GPS метрики
echo "# HELP gps_satellites_visible Visible satellites\n";
echo "gps_satellites_visible " . (($data['gpsd']['sky']['total'] ?? 0)) . "\n";

echo "# HELP gps_satellites_used Used satellites\n";
echo "gps_satellites_used " . (($data['gpsd']['sky']['used'] ?? 0)) . "\n";

// Система
echo "# HELP system_temperature_celsius CPU temperature\n";
echo "system_temperature_celsius " . ($data['system']['temp'] ?? 0) . "\n";

echo "# HELP system_memory_used_bytes Memory used\n";
echo "system_memory_used_bytes " . (($data['system']['memory']['used'] ?? 0) * 1024) . "\n";
?>
```

### Grafana

1. Добавить Prometheus data source:
   ```
   URL: http://localhost:9090
   Scrape interval: 15s
   ```

2. Создать dashboard с панелями:
   ```json
   {
     "panels": [
       {
         "title": "NTP Stratum",
         "targets": [{"expr": "chrony_stratum"}]
       },
       {
         "title": "Time Offset",
         "targets": [{"expr": "chrony_system_time_seconds * 1e9"}]
       }
     ]
   }
   ```

### Alertmanager

Правило для Alert Manager:

```yaml
groups:
- name: ntp
  rules:
  - alert: HighTimeOffset
    expr: abs(chrony_system_time_seconds) > 1e-6
    for: 5m
    annotations:
      summary: "NTP time offset too high"
      value: "{{ $value | humanizeDuration }}"
  
  - alert: LowSatellites
    expr: gps_satellites_used < 4
    for: 2m
    annotations:
      summary: "Not enough GPS satellites"
```

---

## Performance и оптимизация

### Кеширование

Добавить Redis кеш для API (опционально):

```php
<?php
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$cacheKey = 'ntp_monitor_data';
$cacheTTL = 2;  // 2 секунды

if ($cachedData = $redis->get($cacheKey)) {
    echo $cachedData;
} else {
    $data = [/* собрать метрики */];
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $redis->setex($cacheKey, $cacheTTL, $json);
    echo $json;
}
?>
```

### Уменьшение размера дашборда

Убрать skyplot для низкоскоростных соединений:

```javascript
// index.html
const showSkyplot = window.innerWidth > 1200;  // только на больших экранах

if (showSkyplot) {
    renderSkyview(sats);
} else {
    $('#body-skyview').innerHTML = '<div class="err-msg">Skyplot скрыт на мобильных</div>';
}
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

---

**Версия:** 1.0  
**Дата:** 2026-05-10  
**Автор:** @NoIDXMV6  
**Репо:** [chrony-ublox-M10](https://github.com/NoIDXMV6/chrony-ublox-M10)
