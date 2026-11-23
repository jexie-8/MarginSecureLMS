FROM composer:2 AS dependencies

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist

COPY . .
RUN composer dump-autoload --classmap-authoritative --no-dev --no-interaction

FROM php:8.6-fpm-bookworm AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        clamav \
        clamav-freshclam \
        curl \
        libicu-dev \
        libpq-dev \
        libxml2-dev \
        libzip-dev \
        nginx \
        supervisor \
        unzip \
    && docker-php-ext-install -j"$(nproc)" intl opcache pcntl pdo_pgsql simplexml zip \
    && curl --fail --show-error --silent \
        https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem \
        --output /etc/ssl/certs/aws-rds-global-bundle.pem \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=dependencies /app /var/www/html
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/99-margin-production.ini
COPY docker/supervisord.conf /etc/supervisor/conf.d/margin.conf
COPY docker/start-container /usr/local/bin/start-container

RUN chmod +x /usr/local/bin/start-container \
    && mkdir -p \
        bootstrap/cache \
        storage/app/private \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/start-container"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
