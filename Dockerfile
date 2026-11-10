# Imagen de producción de la API (T-24). La usan los servicios app, queue, scheduler y reverb de
# compose.prod.yaml. Funciona en x86 y en ARM (por ejemplo, instancias Graviton de AWS).

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

FROM php:8.3-fpm-alpine

# pdo_pgsql: PostgreSQL · gd: ImageSanitizer (JPG, PNG, WebP) · pcntl: Reverb y queue:work · opcache: rendimiento.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql gd pcntl opcache intl zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-kampus.ini

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/kampus-entrypoint
RUN chmod +x /usr/local/bin/kampus-entrypoint

USER www-data
ENTRYPOINT ["kampus-entrypoint"]
CMD ["php-fpm"]
