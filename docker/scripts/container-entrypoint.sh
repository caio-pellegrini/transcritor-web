#!/bin/sh

set -eu

mkdir -p \
    /var/www/html/storage/app/media/php \
    /var/www/html/storage/app/private \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs

if [ -d /opt/app-storage ]; then
    cp -a /opt/app-storage/. /var/www/html/storage/
fi

touch /var/www/html/storage/app/database.sqlite
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan cache:clear
    php artisan config:cache
else
    php artisan optimize:clear
fi

if [ "${1:-}" = "php-fpm" ]; then
    if [ -d /opt/app-public ]; then
        if [ "${APP_ENV:-production}" = "production" ] || [ ! -f /var/www/html/public/build/manifest.json ]; then
            cp -a /opt/app-public/. /var/www/html/public/
        fi
    fi

    php artisan migrate --force
    chown -R www-data:www-data /var/www/html/storage /var/www/html/public
fi

exec "$@"
