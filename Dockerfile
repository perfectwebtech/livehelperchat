# syntax=docker/dockerfile:1

# ---- Stage 1: resolve PHP dependencies without shipping Composer in the image
FROM composer:2 AS vendor
WORKDIR /app
COPY lhc_web/composer.json lhc_web/composer.lock ./
# composer.json sets vendor-dir to lib/vendor
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
        --no-scripts --ignore-platform-reqs --optimize-autoloader

# ---- Stage 2: runtime image
FROM php:8.3-apache

# System libraries for the PHP extensions Live Helper Chat uses
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libicu-dev zlib1g-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" mysqli pdo pdo_mysql zip gd bcmath intl opcache \
    && rm -rf /var/lib/apt/lists/*

# Production php.ini with sensible opcache settings
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'expose_php=Off'; \
        echo 'upload_max_filesize=20M'; \
        echo 'post_max_size=20M'; \
    } > "$PHP_INI_DIR/conf.d/zz-lhc.ini"

RUN a2enmod headers rewrite

COPY ./lhc_web /var/www/html
COPY --from=vendor /app/lib/vendor /var/www/html/lib/vendor

WORKDIR /var/www/html

# Runtime-writable directories
RUN mkdir -p cache/compiledtemplates cache/cacheconfig cache/translations cache/userinfo var/storage var/userphoto \
    && chown -R www-data:www-data cache var settings

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1/index.php/system/health > /dev/null || exit 1
