#!/bin/bash
# Скрипт для установки пароля веб-интерфейса

MONITOR_DIR="$(cd "$(dirname "$0")" && pwd)"
PASS_FILE="$MONITOR_DIR/.monitor_pass"

echo "Настройка пароля для панели управления NTP Monitor"
echo "=================================================="
echo

read -s -p "Введите пароль: " PASSWORD
echo

if [ -z "$PASSWORD" ]; then
    echo "Пароль не может быть пустым"
    exit 1
fi

# Генерируем хеш
HASH=$(php -r "echo password_hash('$PASSWORD', PASSWORD_BCRYPT);" 2>/dev/null)

if [ -z "$HASH" ]; then
    echo "Ошибка: PHP не установлен или не работает"
    exit 1
fi

echo "$HASH" > "$PASS_FILE"
chmod 600 "$PASS_FILE"
chown www-data:www-data "$PASS_FILE" 2>/dev/null

echo
echo "Пароль установлен. Файл: $PASS_FILE"
echo "Теперь для выполнения действий (makestep, перезапуск сервисов)"
echo "веб-интерфейс будет запрашивать этот пароль."