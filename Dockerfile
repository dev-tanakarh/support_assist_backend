FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libcurl4-openssl-dev ca-certificates \
    && docker-php-ext-install pdo_pgsql curl fileinfo \
    && printf 'upload_max_filesize=5M\npost_max_size=8M\nmax_file_uploads=10\n' > /usr/local/etc/php/conf.d/uploads.ini \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

ENV PORT=10000
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
WORKDIR /var/www/html

COPY . /var/www/html/
COPY docker/render-entrypoint.sh /usr/local/bin/render-entrypoint.sh
RUN chmod +x /usr/local/bin/render-entrypoint.sh \
    && sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/000-default.conf \
    && printf '\n<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n\nAlias /storage/uploads/ /var/www/html/storage/uploads/\n<Directory /var/www/html/storage/uploads>\n    Options -Indexes\n    AllowOverride None\n    Require all granted\n    <FilesMatch \"\\.php$\">\n        Require all denied\n    </FilesMatch>\n</Directory>\n' >> /etc/apache2/apache2.conf \
    && mkdir -p storage/uploads storage/ratelimit \
    && chown -R www-data:www-data storage

EXPOSE 10000
CMD ["/usr/local/bin/render-entrypoint.sh"]
