FROM php:8.4-fpm-alpine
RUN apk add --no-cache icu-dev libzip-dev oniguruma-dev \
 && docker-php-ext-install pdo_mysql intl zip mbstring opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/app.ini
WORKDIR /var/www/html
