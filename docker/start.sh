#!/usr/bin/env bash
# Container start-up: listen on Render's port, cache config, run pending migrations, start Apache.
set -e

PORT="${PORT:-10000}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

cd /var/www/html

if [ -z "${APP_KEY}" ]; then
    echo "ERROR: APP_KEY is not set. Copy it from your local .env into Render → Environment." >&2
    exit 1
fi

# Bake environment variables into cached config/routes/views for speed.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Applies only NEW migrations; never drops data (this is `migrate`, not `migrate:fresh`).
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

# Caches were built as root; let Apache (www-data) read and write them.
chown -R www-data:www-data storage bootstrap/cache

exec apache2-foreground
