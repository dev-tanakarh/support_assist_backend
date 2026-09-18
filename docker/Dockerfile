FROM php:8.3-fpm-alpine

RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo_pgsql

WORKDIR /var/www/html

COPY . /var/www/html

RUN mkdir -p /var/www/html/storage/uploads /var/www/html/storage/ratelimit \
    && chown -R www-data:www-data /var/www/html/storage

EXPOSE 9000

CMD ["php-fpm"]
