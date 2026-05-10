#!/bin/bash
# =============================================================================
# NTP Server Diagnostic & Repair Script
# Диагностика и починка GPS NTP сервера на Raspberry Pi 4 / Armbian Trixie
# =============================================================================

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; NC='\033[0m'

ok()     { echo -e "${GREEN}  ✓ $1${NC}"; }
fail()   { echo -e "${RED}  ✗ $1${NC}"; }
warn()   { echo -e "${YELLOW}  ⚠ $1${NC}"; }
info()   { echo -e "${CYAN}  → $1${NC}"; }
header() { echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"; echo -e "${BLUE}  $1${NC}"; echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"; }

ISSUES=0
FIXED=0

issue() { ((ISSUES++)); fail "$1"; }
fixed() { ((FIXED++)); ok "ИСПРАВЛЕНО: $1"; }

# =============================================================================
header "1. Проверка ядра и системы"
# =============================================================================

KERNEL=$(uname -r)
ARCH=$(uname -m)
info "Ядро: $KERNEL | Архитектура: $ARCH"

if [[ "$ARCH" != "aarch64" ]]; then
    warn "Ожидается aarch64, обнаружено: $ARCH"
else
    ok "Архитектура aarch64"
fi

if [[ -f /etc/armbian-release ]]; then
    source /etc/armbian-release 2>/dev/null || true
    ok "Armbian ${VERSION:-unknown} (${BRANCH:-unknown})"
else
    warn "Armbian не обнаружен"
fi

# =============================================================================
header "2. Проверка загрузочной конфигурации"
# =============================================================================

CONFIG=/boot/firmware/config.txt
CMDLINE=/boot/firmware/cmdline.txt

if [[ ! -f "$CONFIG" ]]; then
    issue "Файл $CONFIG не найден"
else
    ok "$CONFIG существует"

    # Проверка enable_uart
    if grep -q "^enable_uart=1" "$CONFIG"; then
        ok "enable_uart=1 установлен"
    else
        issue "enable_uart=1 отсутствует в $CONFIG"
        echo "  Добавить? [y/N]"
        read -r ans
        if [[ "$ans" =~ ^[Yy]$ ]]; then
            echo "enable_uart=1" >> "$CONFIG"
            fixed "enable_uart=1 добавлен"
        fi
    fi

    # Проверка disable-bt
    if grep -q "dtoverlay=disable-bt" "$CONFIG"; then
        ok "dtoverlay=disable-bt установлен"
    else
        issue "dtoverlay=disable-bt отсутствует — Bluetooth занимает UART"
        echo "  Добавить? [y/N]"
        read -r ans
        if [[ "$ans" =~ ^[Yy]$ ]]; then
            echo "dtoverlay=disable-bt" >> "$CONFIG"
            fixed "dtoverlay=disable-bt добавлен"
        fi
    fi

    # Проверка PPS оверлея
    if grep -q "dtoverlay=pps-gpio" "$CONFIG"; then
        PPS_PIN=$(grep "dtoverlay=pps-gpio" "$CONFIG" | grep -oP 'gpiopin=\K\d+' || echo "не указан")
        ok "dtoverlay=pps-gpio (GPIO pin: $PPS_PIN)"
    else
        issue "dtoverlay=pps-gpio отсутствует"
        echo "  Добавить pps-gpio на GPIO4? [y/N]"
        read -r ans
        if [[ "$ans" =~ ^[Yy]$ ]]; then
            echo "dtoverlay=pps-gpio,gpiopin=4" >> "$CONFIG"
            fixed "dtoverlay=pps-gpio,gpiopin=4 добавлен"
        fi
    fi
fi

# Проверка console=serial0 в cmdline
if [[ -f "$CMDLINE" ]]; then
    if grep -q "console=serial0" "$CMDLINE"; then
        issue "console=serial0 в cmdline.txt — UART занят под консоль"
        echo "  Убрать console=serial0? [y/N]"
        read -r ans
        if [[ "$ans" =~ ^[Yy]$ ]]; then
            cp "$CMDLINE" "${CMDLINE}.bak"
            sed -i 's/console=serial0,[0-9]*//g; s/  */ /g; s/^ //' "$CMDLINE"
            fixed "console=serial0 убран из cmdline.txt"
        fi
    else
        ok "console=serial0 отсутствует в cmdline.txt"
    fi
fi

# =============================================================================
header "3. Проверка устройств"
# =============================================================================

# ttyAMA0
if [[ -e /dev/ttyAMA0 ]]; then
    UART_OWNER=$(stat -c '%G' /dev/ttyAMA0)
    ok "/dev/ttyAMA0 существует (группа: $UART_OWNER)"

    # Проверка что BT не занял UART
    if dmesg | grep -q "hci_uart_bcm serial0"; then
        issue "Bluetooth занял ttyAMA0 (hci_uart_bcm)"
        info "Выполни: sudo systemctl disable hciuart bluetooth && sudo reboot"
    else
        ok "Bluetooth не занимает ttyAMA0"
    fi
else
    issue "/dev/ttyAMA0 не существует"
    info "Необходима перезагрузка после правки config.txt"
fi

# pps0
if [[ -e /dev/pps0 ]]; then
    PPS_OWNER=$(stat -c '%G' /dev/pps0)
    ok "/dev/pps0 существует (группа: $PPS_OWNER)"

    if lsmod | grep -q pps_gpio; then
        ok "Модуль pps_gpio загружен"
    else
        issue "Модуль pps_gpio не загружен"
    fi
else
    issue "/dev/pps0 не существует"
    info "Добавь dtoverlay=pps-gpio,gpiopin=4 в $CONFIG и перезагрузись"
fi

# =============================================================================
header "4. Определение baudrate GNSS модуля"
# =============================================================================

if [[ -e /dev/ttyAMA0 ]]; then
    info "Тестирование baudrate (может занять ~30 секунд)..."

    # Остановить gpsd чтобы освободить порт
    GPSD_WAS_RUNNING=false
    if systemctl is-active --quiet gpsd 2>/dev/null; then
        GPSD_WAS_RUNNING=true
        info "Временно останавливаем gpsd для доступа к порту..."
        systemctl stop gpsd gpsd.socket 2>/dev/null || true
        sleep 1
    fi

    FOUND_BAUD=""

    for baud in 9600 38400 4800 19200 57600 115200; do
        stty -F /dev/ttyAMA0 "$baud" raw 2>/dev/null || continue
        RAW=$(timeout 2 cat /dev/ttyAMA0 2>/dev/null | strings 2>/dev/null || true)
        COUNT=$(echo "$RAW" | grep -c '^\$G' 2>/dev/null || echo "0")
        COUNT="${COUNT//[^0-9]/}"  # убираем непечатаемые символы
        COUNT="${COUNT:-0}"
        echo "    Baudrate $baud: $COUNT NMEA строк"
        if [[ "$COUNT" -gt 0 ]]; then
            FOUND_BAUD="$baud"
            break
        fi
    done

    # Запустить gpsd обратно
    if [[ "$GPSD_WAS_RUNNING" == "true" ]]; then
        info "Запускаем gpsd обратно..."
        systemctl start gpsd 2>/dev/null || true
        sleep 2
    fi

    if [[ -n "$FOUND_BAUD" ]]; then
        ok "GNSS модуль отвечает на baudrate: $FOUND_BAUD"

        # Проверить gpsd конфиг
        GPSD_CONF=/etc/default/gpsd
        if [[ -f "$GPSD_CONF" ]]; then
            CURRENT_BAUD=$(grep -oP '\-s \K\d+' "$GPSD_CONF" || echo "не задан (автоопределение)")
            info "gpsd baudrate в конфиге: $CURRENT_BAUD"

            if grep -q "\-s $FOUND_BAUD" "$GPSD_CONF" 2>/dev/null; then
                ok "gpsd настроен на правильный baudrate $FOUND_BAUD"
            elif [[ "$CURRENT_BAUD" == "не задан (автоопределение)" ]]; then
                ok "gpsd без -s флага (автоопределение)"
            else
                issue "gpsd настроен на $CURRENT_BAUD, а модуль отвечает на $FOUND_BAUD"
                echo "  Исправить? [y/N]"
                read -r ans
                if [[ "$ans" =~ ^[Yy]$ ]]; then
                    sed -i "s/-s [0-9]*/-s $FOUND_BAUD/g" "$GPSD_CONF"
                    fixed "gpsd baudrate исправлен на $FOUND_BAUD"
                fi
            fi
        fi
    else
        issue "GNSS модуль не отвечает ни на одном baudrate"
        warn "Проверь физическое подключение RX/TX и питание модуля (должно быть 3.3V)"
    fi
else
    warn "Пропуск теста baudrate — /dev/ttyAMA0 недоступен"
fi

# =============================================================================
header "5. Создание сервиса автоустановки baudrate"
# =============================================================================

SERVICE_FILE=/etc/systemd/system/gps-baudrate.service

if [[ -f "$SERVICE_FILE" ]]; then
    ok "Сервис gps-baudrate.service уже существует"
    systemctl is-enabled gps-baudrate &>/dev/null && ok "Сервис включён в автозапуск" || warn "Сервис не включён"
else
    info "Сервис gps-baudrate.service не найден"
    BAUD_TO_USE="${FOUND_BAUD:-9600}"
    echo "  Создать сервис для автоустановки baudrate $BAUD_TO_USE? [y/N]"
    read -r ans
    if [[ "$ans" =~ ^[Yy]$ ]]; then
        cat > "$SERVICE_FILE" << EOF
[Unit]
Description=Set GPS UART baudrate to ${BAUD_TO_USE}
Before=gpsd.service
After=dev-ttyAMA0.device
Requires=dev-ttyAMA0.device

[Service]
Type=oneshot
ExecStart=/bin/stty -F /dev/ttyAMA0 ${BAUD_TO_USE} raw
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF
        systemctl daemon-reload
        systemctl enable gps-baudrate
        systemctl start gps-baudrate
        fixed "Сервис gps-baudrate.service создан и запущен (baudrate: $BAUD_TO_USE)"
    fi
fi

# =============================================================================
header "6. Проверка сервисов"
# =============================================================================

for svc in gpsd chrony; do
    if systemctl is-active --quiet "$svc"; then
        ok "$svc: active"
    else
        issue "$svc: не запущен"
        echo "  Запустить $svc? [y/N]"
        read -r ans
        if [[ "$ans" =~ ^[Yy]$ ]]; then
            systemctl start "$svc"
            fixed "$svc запущен"
        fi
    fi

    if systemctl is-enabled --quiet "$svc" 2>/dev/null; then
        ok "$svc: включён в автозапуск"
    else
        issue "$svc: не включён в автозапуск"
        echo "  Включить? [y/N]"
        read -r ans
        if [[ "$ans" =~ ^[Yy]$ ]]; then
            systemctl enable "$svc"
            fixed "$svc включён в автозапуск"
        fi
    fi
done

# =============================================================================
header "7. Проверка gpsd"
# =============================================================================

if systemctl is-active --quiet gpsd; then
    info "Тестирование gpsd (5 секунд)..."
    NMEA_COUNT=$(timeout 5 gpspipe -r -n 20 2>/dev/null | grep -c '^\$G' || echo 0)

    if [[ "$NMEA_COUNT" -gt 0 ]]; then
        ok "gpsd получает NMEA данные ($NMEA_COUNT строк за 5 с)"
    else
        issue "gpsd не получает NMEA данные"
        info "Проверь: baudrate модуля, физическое подключение, /etc/default/gpsd"
    fi

    # Проверка SHM прав
    if id gpsd | grep -q _chrony; then
        ok "gpsd в группе _chrony (SHM доступен)"
    else
        issue "gpsd не в группе _chrony"
        echo "  Добавить gpsd в группу _chrony? [y/N]"
        read -r ans
        if [[ "$ans" =~ ^[Yy]$ ]]; then
            usermod -aG _chrony gpsd
            systemctl restart gpsd
            fixed "gpsd добавлен в группу _chrony"
        fi
    fi
fi

# =============================================================================
header "8. Проверка chrony"
# =============================================================================

if systemctl is-active --quiet chrony; then
    # Stratum
    STRATUM=$(chronyc tracking 2>/dev/null | grep -oP 'Stratum\s*:\s*\K\d+' || echo "N/A")
    REF=$(chronyc tracking 2>/dev/null | grep -oP 'Reference ID\s*:\s*\K\S+' || echo "N/A")
    info "Stratum: $STRATUM | Reference: $REF"

    if [[ "$STRATUM" == "1" ]]; then
        ok "Stratum 1 — синхронизирован с PPS"
    elif [[ "$STRATUM" == "10" ]]; then
        warn "Stratum 10 — holdover режим (нет источников)"
    else
        warn "Stratum $STRATUM"
    fi

    # PPS источник
    if chronyc sources 2>/dev/null | grep -q '#\* PPS'; then
        ok "PPS выбран как основной источник"
    else
        warn "PPS не выбран как основной источник"
        info "Возможно нужен GPS фикс или makestep: chronyc makestep"
    fi

    # makestep конфиг
    if grep -q "makestep.*-1" /etc/chrony/chrony.conf 2>/dev/null; then
        ok "makestep настроен без ограничений (makestep X -1)"
    else
        warn "makestep имеет ограничение по количеству применений"
        info "Рекомендуется: makestep 1.0 -1 в /etc/chrony/chrony.conf"
    fi
fi

# =============================================================================
header "9. Проверка PPS сигнала"
# =============================================================================

if [[ -e /dev/pps0 ]]; then
    info "Тестирование PPS сигнала (3 секунды)..."
    PPS_OUT=$(timeout 4 ppstest /dev/pps0 2>&1 | tail -5)

    if echo "$PPS_OUT" | grep -q "assert"; then
        # Проверяем дробную часть timestamp — должна быть близко к .000
        FRAC=$(echo "$PPS_OUT" | grep -oP 'assert \d+\.\K\d+' | head -1)
        if [[ -n "$FRAC" ]]; then
            FRAC_INT=${FRAC:0:3}  # первые 3 цифры после точки = миллисекунды
            ok "PPS пульсирует (дробная часть: .${FRAC_INT}...)"
            if [[ "$FRAC_INT" -lt 50 ]] || [[ "$FRAC_INT" -gt 950 ]]; then
                ok "PPS привязан к UTC (смещение < 50 мс от секунды)"
            else
                warn "PPS смещён от секунды (~${FRAC_INT} мс) — GPS может не иметь фикса"
            fi
        fi
    else
        issue "PPS не пульсирует"
        info "Проверь подключение PPS пина и антенну"
    fi
fi

# =============================================================================
header "10. Проверка прав доступа веб-сервера"
# =============================================================================

# www-data в dialout
if id www-data 2>/dev/null | grep -q dialout; then
    ok "www-data в группе dialout (доступ к /dev/ttyAMA0)"
else
    issue "www-data не в группе dialout"
    echo "  Добавить? [y/N]"
    read -r ans
    if [[ "$ans" =~ ^[Yy]$ ]]; then
        usermod -aG dialout www-data
        fixed "www-data добавлен в группу dialout"
        info "Перезапусти Apache: sudo systemctl restart apache2"
    fi
fi

# sudoers для chronyc
if [[ -f /etc/sudoers.d/chrony-web ]]; then
    ok "/etc/sudoers.d/chrony-web существует"
    if grep -q "www-data.*chronyc" /etc/sudoers.d/chrony-web; then
        ok "www-data может выполнять chronyc через sudo"
    else
        warn "Содержимое sudoers может быть некорректным"
    fi
else
    issue "/etc/sudoers.d/chrony-web не найден"
    echo "  Создать? [y/N]"
    read -r ans
    if [[ "$ans" =~ ^[Yy]$ ]]; then
        echo "www-data ALL=(ALL) NOPASSWD: /usr/bin/chronyc" > /etc/sudoers.d/chrony-web
        chmod 440 /etc/sudoers.d/chrony-web
        fixed "sudoers для www-data создан"
    fi
fi

# =============================================================================
header "11. Итог диагностики"
# =============================================================================

echo ""
if [[ "$ISSUES" -eq 0 ]]; then
    echo -e "${GREEN}  ✓ Проблем не обнаружено${NC}"
else
    echo -e "${RED}  Обнаружено проблем: $ISSUES${NC}"
    echo -e "${GREEN}  Исправлено:         $FIXED${NC}"
    REMAINING=$((ISSUES - FIXED))
    if [[ "$REMAINING" -gt 0 ]]; then
        echo -e "${YELLOW}  Требуют внимания:  $REMAINING${NC}"
    fi
fi

echo ""
echo -e "${CYAN}  Полезные команды:${NC}"
echo "    chronyc sources -v     — статус источников"
echo "    chronyc tracking       — точность синхронизации"
echo "    gpspipe -r -n 10      — NMEA данные от gpsd"
echo "    sudo ppstest /dev/pps0 — тест PPS сигнала"
echo "    journalctl -u gpsd -n 20 — лог gpsd"
echo "    journalctl -u chrony -n 20 — лог chrony"
echo ""
