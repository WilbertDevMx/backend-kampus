#!/bin/sh
# Arranque de cada contenedor de la API (T-24): arma la caché de configuración, rutas y eventos con las
# variables del .env del servidor y luego ejecuta el comando del servicio (php-fpm, queue:work, etc.).
set -e

php artisan config:cache
php artisan route:cache
php artisan event:cache

exec "$@"
