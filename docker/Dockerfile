ARG PHP_VERSION=8.3

FROM composer:2 AS composer

FROM php:${PHP_VERSION}-cli

WORKDIR /app

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && git config --global --add safe.directory /app

COPY composer.json ./
RUN composer install --no-interaction --no-progress --prefer-dist

COPY . .

CMD ["composer", "test"]
