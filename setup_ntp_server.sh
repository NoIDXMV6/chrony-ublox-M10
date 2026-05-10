#!/bin/bash
# =============================================================================
# Скрипт автоматического развёртывания Stratum 1 NTP-сервера
# Платформа: Raspberry Pi 4, Armbian Trixie (Debian 13), ядро 6.x
# GNSS модуль: u-blox M10 (QUESCAN UBX-M10050-KB) с PPS
# Подключение: UART на GPIO14/GPIO15, PPS на GPIO4 (pin 7)
# =============================================================================

set -euo pipefail

# --- Цвета для вывода ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log()    { echo -e "${GREEN}[OK]${NC} $1"; }
warn()   { echo -e "${YELLOW}[WARN]${NC} $1"; }
error()  { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }
info()   { echo -e "${BLUE}[INFO]${NC} $1"; }
header() { echo -e "\n${BLUE}═══════════════════════════════════════${NC}"; \
           echo -e "${BLUE} $1${NC}"; \
           echo -e "${BLUE}═══════════════════════════════════════${NC}"; }

# =============================================================================
# ПАРАМЕТРЫ — при необходимости измени под свою конфигурацию
# =============================================================================

# GPIO пин PPS (физический пин 7 = GPIO4)
PPS_GPIO_PIN=4

# Baudrate GNSS модуля (u-blox M10 по умолчанию 38400)
GPS_BAUDRATE=38400

# Разрешённая подсеть для NTP клиентов
ALLOW_SUBNET="192.168.0.0/16"

# Fallback NTP серверы (используются если GPS недоступен)
NTP_FALLBACK="pool 2.debian.pool.ntp.org iburst"

# =============================================================================
# ПРОВЕРКИ
# =============================================================================

header "Проверка системы"

# Проверка root
if [[ $EUID -ne 0 ]]; then
    error "Скрипт должен запускаться от root. Используй: sudo $0"
fi

# Проверка архитектуры
ARCH=$(uname -m)
if [[ "$ARCH" != "aarch64" ]]; then
    warn "Ожидается aarch64, обнаружено: $ARCH. Продолжаем..."
else
    log "Архитектура: $ARCH"
fi

# Проверка ядра
KERNEL=$(uname -r)
log "Ядро: $KERNEL"

# Проверка Armbian
if [[ ! -f /etc/armbian-release ]]; then
    warn "Не обнаружен /etc/armbian-release — скрипт оптимизирован под Armbian"
else
    log "Armbian обнаружен"
fi

# Проверка /boot/firmware/config.txt
if [[ ! -f /boot/firmware/config.txt ]]; then
    error "Не найден /boot/firmware/config.txt — неподдерживаемая конфигурация"
fi
log "Конфигурационный файл RPi найден"

# =============================================================================
# ШАГ 1: РЕЗЕРВНЫЕ КОПИИ
# =============================================================================

header "Резервные копии"

BACKUP_DIR="/root/ntp_setup_backup_$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"

cp /boot/firmware/config.txt "$BACKUP_DIR/config.txt.bak"
cp /boot/firmware/cmdline.txt "$BACKUP_DIR/cmdline.txt.bak" 2>/dev/null || true
cp /boot/armbianEnv.txt "$BACKUP_DIR/armbianEnv.txt.bak" 2>/dev/null || true
[[ -f /etc/default/gpsd ]] && cp /etc/default/gpsd "$BACKUP_DIR/gpsd.bak"
[[ -f /etc/chrony/chrony.conf ]] && cp /etc/chrony/chrony.conf "$BACKUP_DIR/chrony.conf.bak"

log "Резервные копии сохранены в $BACKUP_DIR"

# =============================================================================
# ШАГ 2: УСТАНОВКА ПАКЕТОВ
# =============================================================================

header "Установка пакетов"

apt-get update -qq
apt-get install -y gpsd gpsd-clients pps-tools chrony
log "Пакеты установлены: gpsd gpsd-clients pps-tools chrony"

# =============================================================================
# ШАГ 3: НАСТРОЙКА /boot/firmware/config.txt
# =============================================================================

header "Настройка config.txt (UART + PPS)"

CONFIG_FILE="/boot/firmware/config.txt"

# Удаляем старые наши строки если скрипт запускается повторно
sed -i '/# === NTP SERVER SETUP ===/,/# === END NTP SERVER SETUP ===/d' "$CONFIG_FILE"

# Добавляем в конец секции [all]
cat >> "$CONFIG_FILE" << EOF

# === NTP SERVER SETUP ===
# Включить UART (PL011)
enable_uart=1
# Отключить Bluetooth от основного UART
dtoverlay=disable-bt
# PPS сигнал на GPIO${PPS_GPIO_PIN}
dtoverlay=pps-gpio,gpiopin=${PPS_GPIO_PIN}
# === END NTP SERVER SETUP ===
EOF

log "config.txt обновлён (UART + PPS на GPIO${PPS_GPIO_PIN})"

# =============================================================================
# ШАГ 4: ОСВОБОЖДЕНИЕ UART ОТ КОНСОЛИ
# =============================================================================

header "Освобождение UART от serial консоли"

CMDLINE_FILE="/boot/firmware/cmdline.txt"

if [[ -f "$CMDLINE_FILE" ]]; then
    # Убираем console=serial0,... и console=ttyAMA0,...
    sed -i 's/console=serial0,[0-9]*//g' "$CMDLINE_FILE"
    sed -i 's/console=ttyAMA0,[0-9]*//g' "$CMDLINE_FILE"
    # Убираем двойные пробелы
    sed -i 's/  */ /g' "$CMDLINE_FILE"
    sed -i 's/^ //' "$CMDLINE_FILE"
    log "Убран console=serial0 из cmdline.txt"
else
    warn "cmdline.txt не найден — пропускаем"
fi

# Отключаем serial-getty
systemctl disable serial-getty@ttyAMA0.service 2>/dev/null || true
systemctl stop serial-getty@ttyAMA0.service 2>/dev/null || true
log "serial-getty@ttyAMA0 отключён"

# =============================================================================
# ШАГ 5: ОТКЛЮЧЕНИЕ BLUETOOTH
# =============================================================================

header "Отключение Bluetooth"

systemctl disable hciuart 2>/dev/null || true
systemctl stop hciuart 2>/dev/null || true
systemctl disable bluetooth 2>/dev/null || true
systemctl stop bluetooth 2>/dev/null || true
log "Bluetooth сервисы отключены"

# =============================================================================
# ШАГ 6: UDEV ПРАВИЛА ДЛЯ PPS
# =============================================================================

header "Настройка udev правил"

cat > /etc/udev/rules.d/99-pps.rules << 'EOF'
KERNEL=="pps0", GROUP="dialout", MODE="0660"
EOF

udevadm control --reload-rules
udevadm trigger
log "udev правило для /dev/pps0 создано"

# =============================================================================
# ШАГ 7: НАСТРОЙКА GPSD
# =============================================================================

header "Настройка gpsd"

cat > /etc/default/gpsd << EOF
# Конфигурация gpsd для u-blox M10
START_DAEMON="true"
USBAUTO="false"
DEVICES="/dev/ttyAMA0 /dev/pps0"
GPSD_OPTIONS="-n"
EOF

# Добавляем gpsd в группу _chrony для доступа к SHM
usermod -aG _chrony gpsd 2>/dev/null || true
log "gpsd настроен, добавлен в группу _chrony"

# Настраиваем baudrate для u-blox M10
if [[ ! -f /etc/udev/rules.d/99-ttyAMA0-baud.rules ]]; then
    cat > /etc/udev/rules.d/99-ttyAMA0-baud.rules << EOF
KERNEL=="ttyAMA0", RUN+="/bin/stty -F /dev/%k ${GPS_BAUDRATE} raw"
EOF
    log "Установлен baudrate ${GPS_BAUDRATE} для ttyAMA0"
fi

systemctl enable gpsd
log "gpsd включён в автозапуск"

# =============================================================================
# ШАГ 8: НАСТРОЙКА CHRONY
# =============================================================================

header "Настройка chrony"

cat > /etc/chrony/chrony.conf << EOF
# =============================================================================
# Chrony конфигурация для Stratum 1 NTP сервера
# Источник: u-blox M10 GNSS + PPS
# =============================================================================

# Ключи NTP аутентификации
keyfile /etc/chrony/chrony.keys

# Файл дрейфа частоты
driftfile /var/lib/chrony/chrony.drift

# NTS
ntsdumpdir /var/lib/chrony

# Логи
logdir /var/log/chrony

# Защита от резких скачков
maxupdateskew 100.0

# Синхронизация RTC каждые 11 минут
rtcsync

# Список секунд координации
leapseclist /usr/share/zoneinfo/leap-seconds.list

# ─── GPS (NMEA через gpsd SHM 0) ─────────────────────────────────────────
# noselect: используется только для привязки PPS к секунде UTC
refclock SHM 0 delay 0.5 refid GPS noselect

# ─── PPS — основной высокоточный источник ────────────────────────────────
# lock GPS: фронт PPS привязывается к GPS-метке секунды
# precision 1e-7: заявленная точность 100 нс
refclock PPS /dev/pps0 lock GPS refid PPS precision 1e-7

# ─── Интернет серверы — fallback при потере GPS ───────────────────────────
${NTP_FALLBACK}

# ─── Holdover: раздавать время при потере всех источников ────────────────
local stratum 10

# ─── Разрешить клиентам в сети ───────────────────────────────────────────
allow ${ALLOW_SUBNET}

# ─── Быстрое выравнивание при старте (не более 3 раз) ────────────────────
makestep 1.0 3

# ─── Дополнительные конфиги ──────────────────────────────────────────────
confdir /etc/chrony/conf.d
EOF

systemctl enable chrony
log "chrony настроен"

# =============================================================================
# ШАГ 9: ИТОГ И ИНСТРУКЦИЯ ПО ПЕРЕЗАГРУЗКЕ
# =============================================================================

header "Установка завершена"

echo ""
echo -e "${GREEN}Все компоненты настроены успешно.${NC}"
echo ""
echo -e "${YELLOW}ТРЕБУЕТСЯ ПЕРЕЗАГРУЗКА для применения:${NC}"
echo "  • dtoverlay=disable-bt (освобождение UART от Bluetooth)"
echo "  • dtoverlay=pps-gpio,gpiopin=${PPS_GPIO_PIN} (PPS на GPIO${PPS_GPIO_PIN})"
echo ""
echo -e "${BLUE}После перезагрузки выполни проверку:${NC}"
echo ""
echo "  # Устройства появились:"
echo "  ls /dev/ttyAMA0 /dev/pps0"
echo ""
echo "  # PPS пульсирует (1 раз в секунду при наличии фикса):"
echo "  sudo ppstest /dev/pps0"
echo ""
echo "  # NMEA строки идут:"
echo "  stty -F /dev/ttyAMA0 ${GPS_BAUDRATE} raw && cat /dev/ttyAMA0"
echo ""
echo "  # Статус синхронизации (через 2-3 мин после загрузки):"
echo "  chronyc sources -v"
echo "  chronyc tracking"
echo ""
echo -e "${YELLOW}Резервные копии оригинальных файлов: ${BACKUP_DIR}${NC}"
echo ""

read -rp "Перезагрузить сейчас? [y/N]: " REBOOT_NOW
if [[ "$REBOOT_NOW" =~ ^[Yy]$ ]]; then
    info "Перезагрузка..."
    reboot
else
    warn "Не забудь перезагрузиться: sudo reboot"
fi
