FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    curl \
    git \
    unzip \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    sqlite-dev \
    && docker-php-ext-install \
    pdo_mysql \
    pdo_sqlite \
    bcmath \
    opcache \
    intl \
    zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY local.ini /usr/local/etc/php/conf.d/local.ini
COPY entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]

