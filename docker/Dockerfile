FROM php:8.3-fpm-alpine

<<<<<<< HEAD
RUN docker-php-ext-install pdo pdo_pgsql
=======
RUN apk add --no-cache postgresql-dev libwebp-dev libpng-dev libjpeg-turbo-dev freetype-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install pdo_pgsql gd

RUN printf "upload_max_filesize=10M\npost_max_size=12M\nmemory_limit=256M\n" > /usr/local/etc/php/conf.d/uploads.ini
>>>>>>> fa34723 (Fix get ticket detail bug)

WORKDIR /var/www/html

COPY . /var/www/html

RUN mkdir -p /var/www/html/storage/uploads /var/www/html/storage/uploads/profiles /var/www/html/storage/ratelimit \
    && chown -R www-data:www-data /var/www/html/storage

EXPOSE 9000

CMD ["php-fpm"]
