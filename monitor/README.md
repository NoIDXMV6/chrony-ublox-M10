# NTP Monitor — Веб-интерфейс мониторинга Stratum 1 сервера

Полнофункциональная система мониторинга в реальном времени для Stratum 1 NTP сервера на Raspberry Pi с u-blox M10 GNSS модулем.

<img width="1596" height="2147" alt="Screenshot_4" src="https://github.com/user-attachments/assets/84c2657b-0be1-4f37-aafa-4a3478902a1d" />
<img width="723" height="600" alt="Screenshot_5" src="https://github.com/user-attachments/assets/f7ba10a5-9e1b-4629-9cd3-e4fcbf0d8efe" />
<img width="722" height="259" alt="Screenshot_6" src="https://github.com/user-attachments/assets/72d0a789-d80a-4dfd-a317-53958e67262b" />


---

## 📋 Содержание

1. [Архитектура системы](#архитектура-системы)
2. [Компоненты](#компоненты)
3. [Требования](#требования)
4. [Установка и настройка](#установка-и-настройка)
5. [Использование](#использование)
6. [API Reference](#api-reference)
7. [Панель управления](#панель-управления)
8. [Конфигурация](#конфигурация)
9. [Тема оформления](#тема-оформления)
10. [Настройка безопасности](#настройка-безопасности)
11. [Решение проблем](#решение-проблем)

---

## Архитектура системы

```
┌────────────────────────────────────────────────────┐
│                    Raspberry Pi 4                  │
│                                                    │
│  ┌─────────┐  ┌───────────┐  ┌──────────────────┐  │
│  │ gpsd    │  │  chrony   │  │  Apache + PHP    │  │
│  │ ────────│  │ ──────────│  │ ─────────────────│  │
│  │• GNSS   │  │• NTP      │  │• REST API        │  │
│  │• PPS    │  │  daemon   │  │• Live Web UI     │  │
│  └────┬────┘  └────┬──────┘  │• Action control  │  │
│       │            │         └────────┬─────────┘  │
│  /dev/ttyAMA0  /dev/pps0        :80/api.php        │
│  /run/shm      chronyc          :80/action.php     │
│                                 :80/index.html     │
│                                                    │
└────────────────────────────────────────────────────┘
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
**Размер:** ~8 KB

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

#### Обработка ошибок

Если команда chronyc или gpsd не доступны, API возвращает `error` вместо значений, frontend отображает "--" или сообщение об ошибке.

---

### 2. Backend: `action.php` — Выполнение команд

**Язык:** PHP 7.4+  
**Зависимости:** `sudo`, `systemctl`, `chronyc`, `stty`, `cat`, `strings`, `lsof`  
**Размер:** ~2.5 KB

#### Поддерживаемые действия

| Action | Команда | Назначение | Статус |
|--------|---------|-----------|--------|
| `makestep` | `sudo chronyc makestep` | Принудительная синхронизация (если смещение > 1 сек) | 200 OK |
| `restart_gpsd` | `sudo systemctl restart gpsd` | Перезагрузка демона GPS (проверяет через 2с) | active/inactive |
| `restart_chrony` | `sudo systemctl restart chrony` | Перезагрузка NTP демона (проверяет через 2с) | active/inactive |
| `raw_port` | `stty -F /dev/ttyAMA0 && cat /dev/ttyAMA0` | Чтение сырых NMEA данных с UART (20 строк, 3 сек таймаут) | NMEA строки |
| `port_info` | `stty -F /dev/ttyAMA0` + `lsof /dev/ttyAMA0` | Информация о параметрах порта и использующих процессах | stty + lsof вывод |
| `offset_history` | `cat /var/log/chrony/measurements.log` | История смещения из лога chrony (последние 200 записей) | JSON массив точек |

#### Функция runCommand()

Вспомогательная функция для безопасного выполнения команд:
- Захватывает `stdout` и `stderr` (перенаправление `2>&1`)
- Возвращает код выхода и вывод
- Используется `exec()` с перехватом в третий аргумент
- Все параметры экранируются через `escapeshellarg()`

#### Примеры ответов

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

**Успех (raw_port):**
```json
{
  "success": true,
  "output": "$GNRMC,143245.00,A,5545.19286,N,...\n...",
  "baud": 9600
}
```

**Ошибка:**
```json
{
  "success": false,
  "output": "Permission denied"
}
```

---

### 3. Frontend: `index.html` — Интерактивный дашборд

**Язык:** HTML5 + CSS3 + JavaScript (vanilla, без фреймворков)  
**Размер:** ~22 KB (встроены CSS и JS)  
**Минифицировано:** ~13 KB

#### Основные компоненты UI

##### 1. Clock Bar (часы в реальном времени)
- Формат: `HH:MM:SS.mmm`
- Дата с названием дня недели и часовым поясом
- Обновляется каждые 10 мс
- Глобальное переходит синхронизация через `setInterval()`

##### 2. Header
- Логотип `NTP//MON` с акцентом
- Хостнейм сервера (из API)
- Сервис-пилюли (chrony/gpsd/PPS статус)
- Время последнего обновления + кнопка Refresh
- Mode badge (NTP / U-Center) для отображения режима работы

##### 3. Metric Cards (верхний ряд)
8 карточек с основными метриками:
- Stratum, Смещение, RMS offset, Частота
- Спутники (used/total), Онлайн источники
- Leap статус, Дополнительная метрика

Цветовое кодирование:
- `good` — зелёная полоса сверху
- `warn` — жёлтая полоса
- `error` — красная полоса

##### 4. Grid 3×3 (основные блоки)

**Левая колонка: Источники времени**
- Таблица с состояниями источников
- Состояния: `*`=выбран, `+`=комбинируется, `-`=не используется, `x`=ошибка, `~`=нестабилен, `?`=недоступен
- Reach bar (8 битов восьмеричного кода, последние попытки соединения)

**Средняя колонка: GPS / GNSS**
- Режим фикса (No data / No fix / 2D / 3D)
- Координаты (lat, lon, alt) с точностью 6/6/1 знака
- DOP значения (HDOP, VDOP, PDOP)
- PPS статус (/dev/pps0 exists?)
- Спутники (used / total)

**Правая колонка: Карта неба (Skyview)**
- Полярная проекция (азимут/возвышение) в SVG
- Символы спутников по GNSS ID:
  - **Круг** = GPS (0), QZSS (5)
  - **Квадрат** = GLONASS (6)
  - **Треугольник △** = Galileo (2)
  - **Треугольник ▽** = BeiDou (3)
  - **Ромб ◇** = SBAS (1)
- Цвет по SNR (Signal-to-Noise Ratio):
  - Зелёный: ≥40 dBHz (отличный сигнал)
  - Жёлтый: 30-39 dBHz (хороший сигнал)
  - Красный: <30 dBHz (слабый сигнал)
- Контур без заливки = видим, но не используется
- Легенда с расшифровкой

##### 5. Вторая строка (Sync + Clients + System)

**Синхронизация (Tracking)**
- Stratum, Reference ID, Ref time (UTC)
- Смещение, Last offset, RMS offset
- Частота, Skew, Root delay/dispersion
- Leap status

**Клиенты (NTP Clients)**
- Таблица подключённых клиентов
- Хостнейм/IP, кол-во NTP запросов, потеряно пакетов
- Status badge (warning если нет клиентов)

**Система (System)**
- Uptime, температура CPU, нагрузка (1/5/15 мин)
- Использование памяти (прогресс-бар с цветом)
- Статус chrony, gpsd, PPS, UART

##### 6. Диаграмма (Chart)

**График смещения времени (System Offset)**
- Последние ~300 точек из `offset_history`
- Ось Y: наносекунды (автомасштабирование)
- Ось X: время (HH:MM:SS)
- Сетка 4x4 с легендой
- Нулевая линия пунктиром
- Закрашенная область под графиком с градиентом
- Последняя точка с координатами
- Если нет лога chrony — "Включите логирование" подсказка

##### 7. Sourcestats

**Статистика источников**
- Таблица: Name, NP (отправлено), NR (получено), Span, Frequency, Freq Skew, Offset, StdDev

##### 8. Repair Panel (Панель управления и диагностики)

**Левая часть: Управление сервисами**
- `⏱ Принудительная синхронизация` — `makestep`
- `↺ Перезапуск gpsd` — restart GPS
- `↺ Перезапуск chrony` — restart NTP

**Правая часть: Диагностика UART**
- `📡 Сырые данные NMEA` — `raw_port`
- `ℹ Параметры порта` — `port_info`

Оба раздела имеют область вывода где появляются результаты команд.

#### JavaScript логика

**Основной цикл (из config.json):**
```javascript
// Получение данных каждые N сек (по умолчанию 15)
refresh_interval = config.monitor.refresh_interval || 15;
setInterval(refresh, refresh_interval * 1000);
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
  dec = parseInt(octalStr, 8);  // преобразование из восьмеричной
  bits = [];
  for (i = 7; i >= 0; i--) {
    bits.push((dec >> i) & 1);  // [1,0,1,1,1,0,1,1]
  }
  // 1 = успешное соединение, 0 = неудачное
}
```

**Автозагрузка config.json:**
```javascript
// При загрузке страницы
fetch('config.json')
  .then(r => r.json())
  .then(cfg => {
    config = cfg;
    // Применить параметры из конфига
    applyConfig(config);
    refresh();
  })
```

---

### 4. Конфигурация: `config.json`

**Размер:** ~1.5 KB  
**Назначение:** Централизованное управление всеми параметрами системы без редактирования кода

#### Основные разделы

```json
{
  "monitor": {
    "refresh_interval": 15,              // частота обновления API (сек)
    "theme": "auto",                     // "dark" | "light" | "auto"
    "theme_dark_from": "20:00",          // когда переходить в тёмную (если auto)
    "theme_light_from": "07:00",         // когда переходить в светлую (если auto)
    "title": "NTP Monitor"               // заголовок вкладки
  },
  
  "server": {
    "hostname": "",                      // имя сервера (пусто = из API)
    "ntp_port": 123,                     // порт NTP
    "allow_subnet": "192.168.0.0/16"     // для ограничения доступа
  },
  
  "gnss": {
    "device": "/dev/ttyAMA0",            // UART устройство GPS
    "pps_device": "/dev/pps0",           // PPS сигнал
    "baudrate": 9600,                    // скорость UART (может быть 38400)
    "pps_gpio": 4                        // GPIO пин для PPS
  },
  
  "map": {
    "enabled": true,                     // показывать ли карту
    "zoom": 14,                          // уровень зума (если реализована)
    "tile_server": "https://tile.openstreetmap.org/..."  // источник плиток
  },
  
  "telegram": {
    "enabled": true,                     // включить ли оповещения
    "bot_token": "TOKEN",                // Telegram Bot API token
    "chat_id": "ID",                     // chat ID для отправки сообщений
    "proxy_enabled": true,               // использовать ли прокси
    "proxy_host": "HOST",
    "proxy_port": 1080,
    "proxy_user": "USER",
    "proxy_pass": "PASS",
    "alerts": {
      "gpsd_down": true,                 // алерт если gpsd упал
      "chrony_down": true,               // алерт если chrony упал
      "pps_lost": true,                  // алерт если нет PPS
      "stratum_change": true,            // алерт при изменении stratum
      "offset_threshold_ms": 10          // алерт если offset > 10мс
    }
  },
  
  "ser2net": {
    "port": 2947,                        // порт ser2net (если используется)
    "baudrate": 9600                     // скорость
  }
}
```

#### Применение конфига на frontend

```javascript
// Загрузка при старте
fetch('config.json')
  .then(r => r.json())
  .then(cfg => {
    // Установить интервал обновления
    refresh_interval = cfg.monitor.refresh_interval || 15;
    
    // Применить тему
    applyTheme(cfg.monitor.theme);
    
    // Установить заголовок
    document.title = cfg.monitor.title;
    
    // Инициализировать Telegram уведомления (если включены)
    if (cfg.telegram.enabled) {
      initTelegram(cfg.telegram);
    }
  })
```

#### Применение конфига на backend

В `api.php` и `action.php` можно добавить:

```php
<?php
$config = json_decode(file_get_contents('config.json'), true);

// Использовать baudrate из конфига для raw_port
$baud = $config['gnss']['baudrate'] ?? 9600;

// Проверить разрешённые подсети (при наличии)
$allow_subnet = $config['server']['allow_subnet'] ?? '';
?>
```

---

### 5. Стили: `style.css`

**Размер:** ~12 KB  
**Назначение:** Полная стилизация с поддержкой светлой и тёмной темы  
**Встроенный в index.html:** Нет, как отдельный файл для лучшей организации

#### CSS переменные (Dark theme по умолчанию)

```css
:root {
  /* Фон и границы */
  --bg:     #0a0e14;  /* основной фон */
  --bg2:    #0f141c;  /* карточки */
  --bg3:    #151c27;  /* заголовки */
  --border: #1e2a3a;  /* основная граница */
  --border2:#243347;  /* вторичная граница */
  
  /* Текст */
  --text:   #c8d8e8;  /* основной текст */
  --text2:  #7a9ab8;  /* вторичный текст */
  --text3:  #4a6a88;  /* третичный текст (слабый) */
  
  /* Акценты */
  --accent: #00d4ff;  /* голубой неон (основной) */
  --accent2:#0099cc;  /* голубой приглушённый */
  
  /* Статусы */
  --green:  #39d98a;  /* OK (зелёный) */
  --green2: #1a7a4a;  /* фон для зелёного */
  --yellow: #f5c842;  /* WARNING (жёлтый) */
  --yellow2:#7a6010;  /* фон для жёлтого */
  --red:    #ff4d6a;  /* ERROR (красный) */
  --red2:   #7a1a28;  /* фон для красного */
  --purple: #b57aff;  /* альтернативный цвет */
  
  /* Утилиты */
  --shadow: 0 2px 12px rgba(0,0,0,.4);
  --mono:   'JetBrains Mono', monospace;
  --sans:   'Space Grotesk', sans-serif;
  --r: 6px;  /* border-radius */
}
```

#### Light theme (переопределение)

```css
[data-theme="light"] {
  --bg:     #f0f4f8;  /* светлый фон */
  --bg2:    #ffffff;  /* белый фон карточек */
  --text:   #1e293b;  /* тёмный текст */
  --accent: #0284c7;  /* синий вместо голубого */
  /* ... остальные переопределения ... */
}
```

#### Компоненты в CSS

- **Clock bar** — часы в реальном времени с градиентом
- **Header** — логотип, хостнейм, сервис-пилюли, кнопки
- **Metric cards** — сетка карточек с цветными полосами статуса
- **Blocks** — блоки информации с заголовками и бейджами
- **Tables** — стилизованные таблицы с hover эффектами
- **Buttons** — все варианты кнопок (primary, warn, green, danger)
- **Skyview** — SVG контейнер для полярной проекции спутников
- **Chart** — canvas элемент для графика смещения
- **Forms** — инпуты, textarea для Repair Panel
- **Responsive** — медиа-запросы для мобильных устройств

#### Media queries

```css
@media(max-width:1100px) {
  .grid-top { grid-template-columns: 1fr 1fr; }  /* 3 → 2 колоны */
  .grid-mid { grid-template-columns: 1fr 1fr; }
}

@media(max-width:700px) {
  .grid-top, .grid-mid, .grid-repair { grid-template-columns: 1fr; }  /* 2 → 1 колона */
  .clock-time { font-size: 2rem; }  /* масштаб часов */
}
```

#### Поддержка тем через JavaScript

```javascript
// Переключение темы
function setTheme(theme) {
  if (theme === 'auto') {
    // Определить по времени суток
    const hour = new Date().getHours();
    const darkFrom = parseInt(config.monitor.theme_dark_from.split(':')[0]);
    const lightFrom = parseInt(config.monitor.theme_light_from.split(':')[0]);
    theme = (hour >= darkFrom || hour < lightFrom) ? 'dark' : 'light';
  }
  document.documentElement.setAttribute('data-theme', theme);
  localStorage.setItem('ntp-monitor-theme', theme);
}
```

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
# NTP Monitor — www-data sudo rights
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
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart ser2net
www-data ALL=(ALL) NOPASSWD: /bin/stty
www-data ALL=(ALL) NOPASSWD: /usr/bin/lsof
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
scp api.php action.php index.html config.json style.css root@<IP_RPi>:/var/www/html/monitor/

# Или локально
sudo mkdir -p /var/www/html/monitor
sudo cp api.php action.php index.html config.json style.css /var/www/html/monitor/

# Установить права
sudo chown -R www-data:www-data /var/www/html/monitor/
sudo chmod 755 /var/www/html/monitor/
sudo chmod 644 /var/www/html/monitor/*.{php,html,json,css}
```

### 2. Редактирование config.json

```bash
sudo nano /var/www/html/monitor/config.json
```

Основные параметры:
- `refresh_interval` — частота обновления (рекомендуется 15-30 сек)
- `theme` — "dark" или "light"
- `baudrate` — скорость UART (9600 или 38400)
- `telegram.enabled` — включить ли оповещения (требует bot token)

### 3. Настройка веб-сервера

#### Apache (рекомендуется)

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

### 4. Проверка зависимостей

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
```

### 5. Первый запуск

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
- `~` = нестабилен (проблемы с качеством)
- `?` = неизвестно (нет данных)
- Reach = последние 8 попыток соединения (8 бит восьмеричного кода)

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
- `action` — одна из: `makestep`, `restart_gpsd`, `restart_chrony`, `raw_port`, `port_info`, `offset_history`

#### Примеры запросов

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
```

#### 4. Выполнить makestep

```bash
curl "http://192.168.1.10/monitor/action.php?action=makestep" | jq .
```

#### 5. Проверить UART

```bash
curl "http://192.168.1.10/monitor/action.php?action=raw_port" | jq -r .output
```

#### 6. Получить историю смещения

```bash
curl "http://192.168.1.10/monitor/action.php?action=offset_history" | jq .
```

---

## Панель управления

### Кнопки управления и диагностики

#### Управление сервисами

| Кнопка | Команда | Описание |
|--------|---------|---------|
| `⏱ Принудительная синхронизация` | `makestep` | Немедленное выравнивание часов (для больших ошибок > 1 сек) |
| `↺ Перезапуск gpsd` | `systemctl restart gpsd` | Перезагрузка GPS демона (проверяет статус через 2с) |
| `↺ Перезапуск chrony` | `systemctl restart chrony` | Перезагрузка NTP демона (проверяет статус через 2с) |

#### Диагностика UART

| Кнопка | Команда | Описание |
|--------|---------|---------|
| `📡 Сырые данные NMEA` | `raw_port` | Прямое чтение NMEA с порта (20 строк, 3 сек таймаут) |
| `ℹ Параметры порта` | `port_info` | Текущие настройки UART (stty) и использующие процессы (lsof) |

### Обработка результатов

После выполнения команды:
- **Зелёный текст** = успех (в `repair-output`)
- **Красный текст** = ошибка
- **Spinning loader** = выполняется (с текстом "Выполняется...")
- Все кнопки блокируются на время выполнения
- Окно вывода может быть прокручено (max-height: 200px)

---

## Конфигурация

### Параметры config.json

Все параметры системы хранятся в `config.json`:

```json
{
  "monitor": {
    "refresh_interval": 15,              // сек между обновлениями
    "theme": "auto",                     // "dark" | "light" | "auto"
    "theme_dark_from": "20:00",          // время перехода в тёмную
    "theme_light_from": "07:00",         // время перехода в светлую
    "title": "NTP Monitor"               // заголовок страницы
  },
  "server": { ... },
  "gnss": { ... },
  "map": { ... },
  "telegram": { ... },
  "ser2net": { ... }
}
```

### Редактирование параметров

Изменения вступают в силу **автоматически** при перезагрузке страницы (без перезагрузки сервера):

```bash
# Изменить интервал обновления
nano /var/www/html/monitor/config.json
# Измените: "refresh_interval": 30

# Перезагрузите страницу в браузере — изменения сразу применятся
```

---

## Тема оформления

### Поддерживаемые темы

1. **Dark** (по умолчанию)
   - Чёрный фон #0a0e14
   - Голубой неон #00d4ff
   - Оптимальна для ночного просмотра

2. **Light**
   - Светлый фон #f0f4f8
   - Синий акцент #0284c7
   - Оптимальна для дневного просмотра

3. **Auto**
   - Автоматическое переключение по времени суток
   - Тёмная: 20:00 - 07:00
   - Светлая: 07:00 - 20:00

### Переключение темы

```javascript
// Вручную в консоли браузера
setTheme('dark');
setTheme('light');
setTheme('auto');

// Сохраняется в localStorage
// localStorage.getItem('ntp-monitor-theme')
```

### Использование в config.json

```json
{
  "monitor": {
    "theme": "auto",
    "theme_dark_from": "20:00",
    "theme_light_from": "07:00"
  }
}
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

# Автоматическое обновление
sudo systemctl enable certbot.timer
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

### config.json не загружается

```bash
# 1. Проверить синтаксис JSON
jq . /var/www/html/monitor/config.json

# 2. Проверить права доступа
sudo chmod 644 /var/www/html/monitor/config.json

# 3. Проверить консоль браузера (F12 → Console)
# Должно быть: "config loaded: {...}"

# 4. Если нет config.json — frontend работает с defaults
```

### Тема не переключается

```javascript
// В консоли браузера (F12)
// Проверить текущую тему
document.documentElement.getAttribute('data-theme');

// Проверить config
console.log(config.monitor.theme);

// Переключить вручную
document.documentElement.setAttribute('data-theme', 'light');
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
```

---

## Структура файлов

```
monitor/
├── api.php            # REST API для сбора метрик (~8 KB)
├── action.php         # API для выполнения команд (~2.5 KB)
├── index.html         # Веб-интерфейс (CSS + JS встроены) (~22 KB)
├── config.json        # Конфигурация системы (~1.5 KB)
├── style.css          # Стили (отдельный файл для организации) (~12 KB)
└── README.md          # Эта документация
```

**Размеры (минимальные):**
- `api.php` — ~8 KB
- `action.php` — ~2.5 KB
- `index.html` — ~22 KB
- `config.json` — ~1.5 KB
- `style.css` — ~12 KB
- **Всего** — ~46 KB

**Минифицировано:**
- ~28 KB (без style.css встроенного в HTML)
- ~18 KB (если встроить style.css в index.html)

---

## Производительность

### Требования к ресурсам

| Ресурс | Требование | Примечание |
|--------|-----------|-----------|
| CPU | < 5% | При обновлении каждые 15 сек |
| Память | ~ 40 MB | PHP + Apache |
| Диск | ~ 50 KB | Четыре файла + кеш браузера |
| Пропускная способность | < 500 KB/ч | ~2-3 KB за запрос, каждые 15 сек |

### Оптимизация

**Снизить частоту обновления:**
```json
{
  "monitor": {
    "refresh_interval": 30  // было 15 (15 сек)
  }
}
```

**Отключить график (offset_history):**
```php
// В api.php закомментировать вызов
// 'offset_history' => getOffsetHistory(),
```

**Отключить skyplot:**
```javascript
// В index.html в функции renderGps()
// renderSkyview(sats);  // закомментировать
```

---

## Интеграция с внешними системами

### Telegram уведомления (если используются)

```json
{
  "telegram": {
    "enabled": true,
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

Требует PHP расширения `curl` для отправки HTTP запросов к Telegram API.

### ser2net (если используется для удалённого доступа к UART)

```json
{
  "ser2net": {
    "port": 2947,
    "baudrate": 9600
  }
}
```

Позволяет получать данные с UART через TCP соединение.

---

## История версий

### v2.1 (текущая, 2026-05-10)

**Добавлено:**
- ✅ Новый файл `config.json` для централизованной конфигурации
- ✅ Файл `style.css` для лучшей организации стилей
- ✅ Поддержка светлой и тёмной темы с автоматическим переключением
- ✅ Action `offset_history` для чтения истории смещений
- ✅ Mode badge в header (NTP / U-Center)
- ✅ Расширенная поддержка Telegram уведомлений в конфиге

**Улучшено:**
- ✅ Все стили перемещены в отдельный файл `style.css`
- ✅ Поддержка CSS переменных для обеих тем
- ✅ Лучшая организация конфигурации через JSON

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

**Версия:** 2.1 (обновлено 2026-05-10)  
**Автор:** @NoIDXMV6  
**Репо:** [chrony-ublox-M10](https://github.com/NoIDXMV6/chrony-ublox-M10)  
**Статус:** ✅ Актуально — все компоненты (api.php, action.php, index.html, config.json, style.css) документированы
