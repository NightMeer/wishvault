#!/bin/sh
set -e

# Datenverzeichnis (SQLite, Bilder-Cache, Logs) für www-data beschreibbar machen
mkdir -p /var/www/data
chown -R www-data:www-data /var/www/data

# Container-Umgebung für cron bereitstellen (cron-Prozesse erben sie sonst nicht)
mkdir -p /etc/wishvault
php -r 'file_put_contents("/etc/wishvault/env.json", json_encode(getenv(), JSON_UNESCAPED_UNICODE));'
chown root:www-data /etc/wishvault/env.json
chmod 640 /etc/wishvault/env.json

# Zeitplan der Hintergrundjobs aus CRON_SCHEDULE (Standard: alle 15 Minuten)
SCHEDULE="${CRON_SCHEDULE:-*/15 * * * *}"
if [ "$(echo "$SCHEDULE" | awk '{print NF}')" -ne 5 ]; then
    echo "WARNUNG: CRON_SCHEDULE '$SCHEDULE' ungültig – verwende */15 * * * *"
    SCHEDULE="*/15 * * * *"
fi
cat > /etc/cron.d/wishvault <<CRON
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
$SCHEDULE www-data php /var/www/app/cron.php >> /var/www/data/cron.log 2>&1
CRON
chmod 0644 /etc/cron.d/wishvault

# Zeitzone
if [ -n "$TZ" ] && [ -f "/usr/share/zoneinfo/$TZ" ]; then
    ln -snf "/usr/share/zoneinfo/$TZ" /etc/localtime && echo "$TZ" > /etc/timezone
fi

cron
echo "WishVault gestartet (Cron: $SCHEDULE)"

# Startskript des Basis-Images (Apache-/PHP-Config, Apache im Vordergrund)
exec /startup.sh
