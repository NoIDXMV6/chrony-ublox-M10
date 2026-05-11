# NTP Monitor — Веб-интерфейс мониторинга Stratum 1 сервера

Полнофункциональная система мониторинга в реальном времени для Stratum 1 NTP сервера на Raspberry Pi с u-blox M10 GNSS модулем.

<img width="1435" height="1869" alt="Screenshot_7" src="https://github.com/user-attachments/assets/266e59c0-84ff-4df9-9b1c-ff7fc45170cf" />

<img width="723" height="600" alt="Screenshot_5" src="https://github.com/user-attachments/assets/f7ba10a5-9e1b-4629-9cd3-e4fcbf0d8efe" />  
<img width="722" height="259" alt="Screenshot_6" src="https://github.com/user-attachments/assets/72d0a789-d80a-4dfd-a317-53958e67262b" />  

# NTP Monitor — Веб-интерфейс мониторинга Stratum 1 сервера

Полнофункциональная система мониторинга в реальном времени для Stratum 1 NTP сервера на Raspberry Pi с u‑blox M10 GNSS модулем.  
Поддерживает мультиязычность, защищённое управление сервисами и гибкую настройку.

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
10. [Безопасность](#безопасность)
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

Программные компоненты размещены на Raspberry Pi 4 и взаимодействуют по следующей схеме:

- **gpsd** получает данные от GNSS‑модуля (UART) и сохраняет их в SHM.
- **chrony** использует PPS‑сигнал для дисциплинирования системных часов и обслуживает NTP‑запросы.
- **Apache + PHP** предоставляют REST API (`api.php`, `action.php`) и веб‑интерфейс (`index.html`, `monitor.js`).
- Клиенты (браузеры, NTP‑устройства) подключаются по локальной сети.

### Поток данных

1. **GNSS → gpsd** (UART с настраиваемой скоростью)  
   u‑blox M10 отправляет NMEA строки по `/dev/ttyAMA0`, gpsd парсит положение, высоту, спутники и сохраняет в SHM.

2. **PPS → chrony** (GPIO4)  
   Пульсирующий сигнал 1 Hz на `/dev/pps0` дисциплинирует системные часы до наносекунд.

3. **chrony → API** (Unix socket / TCP port 323)  
   `chronyc` выполняет команды мониторинга и возвращает `tracking`, `sources`, `sourcestats`, `activity`, `clients`, `serverstats`.

4. **API → Browser** (REST JSON)  
   `api.php` собирает метрики в JSON, `action.php` выполняет привилегированные команды с парольной защитой.  
   `index.html` + `monitor.js` отображают интерактивный дашборд с поддержкой мультиязычности.

---

## Компоненты

### 1. Backend: `api.php` — Сбор метрик
**Язык:** PHP 7.4+  
**Зависимости:** `chronyc`, `gpsd`, `systemctl`, `/proc`, `/sys`  

Собирает полную статистику: tracking, sources, sourcestats, activity, clients, serverstats, gpsd, system.  
Форматирует времена в нано/микро/миллисекунды, возвращает статусы `good/warning/error`.

### 2. Backend: `action.php` — Выполнение команд
**Язык:** PHP 7.4+  
**Зависимости:** `sudo`, `systemctl`, `chronyc`, `stty`, `cat`, `strings`, `lsof`  

Поддерживаемые действия:
- `makestep` – принудительная синхронизация  
- `restart_gpsd`, `restart_chrony` – перезапуск сервисов  
- `raw_port`, `port_info` – диагностика UART  
- `switch_mode` – переключение NTP / u‑center (с автосозданием конфига `ser2net` и сбросом приёмника в NMEA)  
- `telegram_test`, `telegram_status` – проверка Telegram  
- `config_read`, `config_write` – работа с `config.json`  

**Все опасные действия защищены паролем** – файл `.monitor_pass` содержит bcrypt‑хеш, пароль передаётся через параметр `auth_pass`.

### 3. Frontend: `index.html` + `monitor.js`
**Язык:** HTML5 + CSS3 + JavaScript (vanilla)  

- `index.html` содержит только структуру (разметку)  
- `monitor.js` реализует всю клиентскую логику  

**Основные возможности:**
- **Мультиязычность** – языковые файлы `ru_locale.txt`, `en_locale.txt`; переключатель языка создаётся динамически на основе `config.json`
- **Аутентификация действий** – стилизованные диалоги ввода пароля и выбора скорости u‑center
- **Динамический интерфейс** – карточки метрик, таблица источников, GPS/GNSS, SNR‑гистограмма, Skyview (SVG), карта OpenStreetMap, график смещения, статистика источников, информация о клиентах, системные показатели
- **Панель управления** – кнопки `makestep`, перезапуск gpsd/chrony, чтение NMEA, информация о порте
- **Адаптивная вёрстка** – все панели выстраиваются в один столбец на мобильных устройствах
- **Тёмная/светлая тема** – автоматическое переключение по времени или ручной выбор; настройка через `config.json`

### 4. Конфигурация: `config.json`
Централизованное управление: интервал обновления, тема, параметры GNSS, настройки Telegram, ser2net, языки, допустимые скорости.

### 5. Стили: `style.css`
Полная поддержка тёмной и светлой темы через CSS‑переменные. Стилизованы все компоненты, включая диалоги пароля/скорости и мобильные элементы.

### 6. Дополнительные файлы
- `ru_locale.txt`, `en_locale.txt` – переводы интерфейса  
- `setup_auth.sh` – скрипт для создания файла `.monitor_pass` с хешем пароля  
- `favicon.svg` – иконка для вкладки браузера

---

## Требования

### Аппаратное обеспечение
- Raspberry Pi 4 (или любой Linux с UART + GPIO)
- u‑blox M10 GNSS модуль с PPS выходом
- Веб‑сервер (Apache с PHP или nginx + php‑fpm)

### Программное обеспечение
- Linux kernel 5.10+ (с поддержкой PPS‑GPIO)
- PHP 7.4+ с модулями `cli`, `curl`
- chrony 4.0+, gpsd 3.20+, pps‑tools, ser2net (опционально)

### Разрешения
Пользователь `www-data` должен иметь права на выполнение системных команд через sudo. Настройка sudoers описана в разделе «Установка».

---

## Установка и настройка

### 1. Копирование файлов
Скопируйте все файлы в `/var/www/html/monitor/` (или другую директорию веб‑сервера).  

Установите владельца `www-data:www-data` и права:

    sudo chown -R www-data:www-data /var/www/html/monitor/
    sudo find /var/www/html/monitor/ -type d -exec chmod 755 {} \;
    sudo find /var/www/html/monitor/ -type f -exec chmod 644 {} \;
    sudo chmod 600 /var/www/html/monitor/config.json

### 2. Создание пароля администратора
Выполните от root:

    cd /var/www/html/monitor/
    sudo bash setup_auth.sh

Скрипт запросит пароль и создаст файл `.monitor_pass` с bcrypt‑хешем.  
Пароль потребуется для всех действий на панели управления (makestep, перезапуски, переключение режимов).

### 3. Настройка sudo для www‑data
Создайте файл `/etc/sudoers.d/www-ntp-monitor` со следующим содержимым:

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
    www-data ALL=(ALL) NOPASSWD: /bin/stty
    www-data ALL=(ALL) NOPASSWD: /usr/bin/lsof

Проверьте права:

    sudo chmod 440 /etc/sudoers.d/www-ntp-monitor
    sudo visudo -c

Добавьте `www-data` в группы `dialout` (для доступа к UART) и `tty` (если требуется).

### 4. Конфигурация
Отредактируйте `config.json`:
- укажите скорость UART (`gnss.baudrate`)
- при необходимости измените порт ser2net (`ser2net.port`)
- добавьте языки в `languages` (можно добавить свои файлы локализации)
- список допустимых скоростей для u‑center — `ser2net.baudrate_options`

### 5. Проверка
Откройте в браузере `http://<IP_RPi>/monitor/`.  
При первом входе отобразится дашборд. Для действий управления потребуется пароль, заданный на шаге 2.

---

## Использование

### Интерфейс и навигация
- Верхняя панель: часы, хостнейм, статусы сервисов, кнопки управления темой и языком, кнопка обновления.
- Переключатель языка (RU/EN) находится в правом верхнем углу экрана.
- Панель управления и диагностики расположена под карточками метрик.
- Все панели данных (источники, GPS, карта неба, график, клиенты) идут ниже.

### Аутентификация действий
При нажатии на любую кнопку управления (синхронизация, перезапуск, смена режима) появляется диалоговое окно для ввода пароля. Пароль кэшируется на 30 минут. При неверном вводе повторно запрашивается.

### Режим u‑center (ser2net)
1. Нажмите кнопку **u‑center**.
2. Введите пароль.
3. В появившемся диалоге выберите скорость порта (предустановлен список из `config.json`).
4. Нажмите **Подтвердить** – система остановит gpsd/chrony и запустит `ser2net`, открыв TCP‑доступ к приёмнику.
5. Подключитесь из u‑center к `tcp://<IP>:2000` (или другому порту из конфига).
6. При возврате в **NTP** сервисы автоматически перезапускаются, а приёмник сбрасывается в NMEA‑режим с корректной скоростью.

### Смена языка
Выберите нужный язык в выпадающем списке (правый верхний угол). Интерфейс мгновенно перерисуется с новыми переводами. Список языков берётся из `config.json`, можно добавлять собственные файлы локализации (формат JSON).

---

## API Reference

### Основной endpoint

    GET /monitor/api.php

Без параметров. Возвращает полный JSON со всеми метриками.

### Action endpoint

    GET /monitor/action.php?action=<ACTION>
    POST /monitor/action.php (в body: action=<ACTION>)

Для действий, требующих аутентификации, передавайте параметр `auth_pass=<пароль>`.

**Действия:**
- `makestep`, `restart_gpsd`, `restart_chrony`, `raw_port`, `port_info`
- `switch_mode` – переключение NTP/u‑center (принимает `mode=ucenter|time` и опционально `baud=<скорость>`)
- `telegram_test`, `telegram_status`
- `config_read`, `config_write`
- `server_info`, `current_mode`, `get_instructions`

**Примеры запросов:**

    curl http://192.168.1.10/monitor/api.php | jq .
    curl "http://192.168.1.10/monitor/action.php?action=makestep" | jq .

**Пример успешного ответа:**

    {
      "success": true,
      "output": "200 OK\nClock was stepped by 0.000000042 seconds"
    }

**Пример ответа при ошибке аутентификации:**

    {
      "success": false,
      "error": "Требуется пароль",
      "auth_required": true
    }

---

## Панель управления

### Кнопки управления и диагностики

#### Управление сервисами

| Кнопка | Команда | Описание |
|--------|---------|----------|
| ⏱ Принудительная синхронизация | `makestep` | Немедленное выравнивание часов |
| ↺ Перезапуск gpsd | `systemctl restart gpsd` | Перезагрузка GPS демона |
| ↺ Перезапуск chrony | `systemctl restart chrony` | Перезагрузка NTP демона |

#### Диагностика UART

| Кнопка | Команда | Описание |
|--------|---------|----------|
| 📡 Сырые данные NMEA | `raw_port` | Прямое чтение NMEA с порта |
| ℹ Параметры порта | `port_info` | Текущие настройки UART и использующие процессы |

---

## Конфигурация

Файл `config.json` содержит централизованные настройки:

- `monitor` – интервал обновления, тема оформления, заголовок
- `server` – порт NTP, разрешённые подсети
- `gnss` – устройство, скорость UART, пин PPS
- `map` – включение карты и источник тайлов
- `telegram` – токены, прокси, пороговые значения для алертов
- `ser2net` – порт, скорость, список доступных скоростей для u‑center
- `languages` – перечень доступных языков (объект, где ключ – код языка, значение – объект с `name` и `file`)

Изменения вступают в силу после перезагрузки страницы.

---

## Тема оформления

Тёмная и светлая темы реализованы через CSS‑переменные в `style.css`.  
Выбор темы осуществляется в `config.json` (`monitor.theme`) или вручную через кнопку в интерфейсе.  
Автоматическое переключение по времени суток (если выбрано `auto`).

---

## Безопасность

- Действия, изменяющие состояние системы (`makestep`, перезапуски, смена режима), защищены паролем, хеш которого хранится в файле `.monitor_pass`.
- Конфиденциальные данные (`config.json`, `.monitor_pass`) рекомендуется защитить правами `600`.
- Доступ к веб‑интерфейсу можно ограничить по IP стандартными средствами Apache или nginx.
- Рекомендуется использовать HTTPS для шифрования трафика (Let's Encrypt).

---

## Решение проблем

### При вводе пароля ничего не происходит / ошибка 401
- Убедитесь, что файл `.monitor_pass` существует и доступен для чтения пользователю `www-data`.
- Очистите кэш пароля в браузере: выполните в консоли `clearAuthPassword()` или перезагрузите страницу.

### Не открывается порт u‑center
- Проверьте, что `ser2net` установлен и файрвол не блокирует выбранный порт.
- Убедитесь, что конфигурационный файл `/etc/ser2net.yaml` создан (создаётся автоматически при первом переключении).
- В случае конфликта с `gpsd` измените порт в `config.json` (по умолчанию `2000`).

### Мобильная версия отображается некорректно
- Обновите страницу со сбросом кэша (Ctrl+Shift+R).
- Проверьте, что в `style.css` присутствуют media queries для `max-width: 767px`.

---

## Структура файлов

monitor/  
├── index.html # Разметка интерфейса  
├── monitor.js # Клиентская логика  
├── api.php # Сбор метрик  
├── action.php # Выполнение команд (с аутентификацией)  
├── config.json # Конфигурация  
├── style.css # Стили и темы  
├── ru_locale.txt # Локализация (русский)  
├── en_locale.txt # Локализация (английский)  
├── setup_auth.sh # Скрипт установки пароля  
├── favicon.svg # Иконка сайта  
└── README.md # Документация  

---

## Лицензия

Проект предоставляется «как есть» для образовательных и любительских целей.

---

## Ссылки

- [chrony документация](https://chrony.troglobit.com/)
- [gpsd документация](https://gpsd.io/)
- [u-blox M10 datasheet](https://www.u-blox.com/en/product/m10-series)
- [NTP Protocol RFC 5905](https://tools.ietf.org/html/rfc5905)
- [Raspberry Pi GPIO](https://www.raspberrypi.com/documentation/computers/os.html#gpio)
- [PHP exec() функция](https://www.php.net/manual/en/function.exec.php)

---

**Версия:** 2.2 (обновлено 2026‑05)  
**Автор:** @NoIDXMV6  
**Репо:** [chrony-ublox-M10](https://github.com/NoIDXMV6/chrony-ublox-M10)  
**Статус:** ✅ Актуально — добавлена мультиязычность, аутентификация и поддержка u‑center.
**Репо:** [chrony-ublox-M10](https://github.com/NoIDXMV6/chrony-ublox-M10)  
**Статус:** ✅ Актуально — все компоненты (api.php, action.php, index.html, config.json, style.css) документированы
