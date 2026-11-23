FROM php:8.5-cli-alpine

RUN docker-php-ext-install pdo_mysql \
    && apk add --no-cache git unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
