FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    curl \
    git \
    unzip \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    && docker-php-ext-install \
    pdo_mysql \
    bcmath \
    opcache \
    intl \
    zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY local.ini /usr/local/etc/php/conf.d/local.ini

WORKDIR /var/www/html

