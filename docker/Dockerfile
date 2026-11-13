# Production image for Symine: PHP 8.5 FPM (OPcache is built into PHP 8.5, no extension to install) (the web server, Caddy, runs in its own container: see docker-compose.yml)
FROM php:8.5-fpm AS base
RUN apt-get update && apt-get install -y --no-install-recommends \
        git subversion unzip libicu-dev libzip-dev libpq-dev libldap2-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" intl zip pdo_pgsql pdo_mysql ldap gd \
    && rm -rf /var/lib/apt/lists/*
RUN { \
        echo 'opcache.enable=1'; echo 'opcache.memory_consumption=256'; echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; echo 'realpath_cache_size=4096K'; echo 'realpath_cache_ttl=600'; \
        echo 'memory_limit=512M'; echo 'upload_max_filesize=64M'; echo 'post_max_size=64M'; echo 'expose_php=0'; \
    } > /usr/local/etc/php/conf.d/symine.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /srv/symine
ENV APP_ENV=prod APP_DEBUG=0
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY . .
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && php bin/console cache:warmup \
    && mkdir -p var/files var/log \
    && chown -R www-data:www-data var
VOLUME ["/srv/symine/var/files"]
# php-fpm listens on 9000 (Caddy: php_fastcgi symine:9000)
