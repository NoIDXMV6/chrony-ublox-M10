#!/bin/bash
# NTP Monitor Watchdog (systemd service)
# Постоянно мониторит NMEA-данные. При зависании — восстанавливает связь.
# При вызове с --force-reset — принудительный сброс (ручной или через systemctl reload).

DEVICE="/dev/ttyAMA0"
BAUD="9600"                         # целевая скорость (совпадает с gnss.baudrate из config.json)
GPIO_POWER=23                       # GPIO аппаратного сброса (когда будет MOSFET)
STALL_COUNT_FILE="/tmp/ntp_watchdog_stalls"
MAX_STALLS=3                        # сколько циклов без NMEA терпеть

# Проверяет наличие NMEA за последние секунды
check_nmea() {
    timeout 3 gpspipe -r -n 5 2>/dev/null | grep -q '\$'
}

# Программный сброс (без MOSFET)
reset_software() {
    echo "[$(date)] Программный сброс GNSS..."
    systemctl stop gpsd gpsd.socket chrony ser2net 2>/dev/null
    fuser -k $DEVICE 2>/dev/null
    sleep 1

    for rate in 9600 38400 115200 460800 19200 57600 230400 921600 4800; do
        stty -F $DEVICE $rate raw 2>/dev/null
        sleep 0.5
        # UBX CFG-RST
        printf '\xb5\x62\x06\x04\x04\x00\x00\x00\x00\x00\x00\x00\x12\x7f' > $DEVICE
        sleep 2
        # PUBX на целевую скорость
        PUBX="PUBX,41,1,0007,0001,$BAUD,0"
        CSUM=0; for ((i=0; i<${#PUBX}; i++)); do CSUM=$((CSUM ^ $(printf '%d' "'${PUBX:$i:1}"))); done
        CSUM_HEX=$(printf '%02X' $CSUM)
        printf '\$%s*%s\r\n' "$PUBX" "$CSUM_HEX" > $DEVICE
        sleep 2

        stty -F $DEVICE $BAUD raw 2>/dev/null
        if check_nmea; then
            echo "[$(date)] Модуль ответил на $rate, переведён в NMEA ($BAUD)"
            systemctl start gpsd chrony
            return 0
        fi
    done
    echo "[$(date)] Программный сброс не удался"
    systemctl start gpsd chrony 2>/dev/null
    return 1
}

# Аппаратный сброс (когда будет MOSFET)
reset_hardware() {
    echo "[$(date)] Аппаратный сброс питания (GPIO$GPIO_POWER)"
    echo "$GPIO_POWER" > /sys/class/gpio/export 2>/dev/null
    echo "out" > /sys/class/gpio/gpio$GPIO_POWER/direction

    systemctl stop gpsd gpsd.socket chrony ser2net 2>/dev/null
    fuser -k $DEVICE 2>/dev/null
    sleep 1

    echo 0 > /sys/class/gpio/gpio$GPIO_POWER/value
    sleep 2
    echo 1 > /sys/class/gpio/gpio$GPIO_POWER/value
    sleep 2

    stty -F $DEVICE $BAUD raw 2>/dev/null
    systemctl start gpsd chrony
    echo "[$(date)] Аппаратный сброс завершён"
}

# === Обработка аргументов ===
if [ "$1" == "--force-reset" ]; then
    if [ -e /sys/class/gpio/gpio$GPIO_POWER ]; then
        reset_hardware
    else
        reset_software
    fi
    exit
fi

if [ "$1" != "--daemon" ]; then
    echo "Usage: $0 --daemon         # запустить как сервис"
    echo "       $0 --force-reset   # принудительный сброс"
    exit 1
fi

# === Основной цикл демона ===
echo "[$(date)] Watchdog запущен (интервал 20 сек, макс. попыток: $MAX_STALLS)"
STALLS=0
while true; do
    if check_nmea; then
        STALLS=0
        echo "[$(date)] NMEA OK"
    else
        STALLS=$((STALLS + 1))
        echo "[$(date)] NMEA отсутствует (попытка $STALLS)"
        if [ $STALLS -ge $MAX_STALLS ]; then
            echo "[$(date)] Порог зависаний превышен – запускаю восстановление"
            STALLS=0
            if [ -e /sys/class/gpio/gpio$GPIO_POWER ]; then
                reset_hardware
            else
                reset_software
            fi
        fi
    fi
    sleep 20
done
