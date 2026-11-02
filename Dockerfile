# Tienda eSIM - imagen para Railway (PHP 8.3 + Apache)
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql gd opcache \
 && rm -rf /var/lib/apt/lists/*
# (mbstring, curl, openssl, fileinfo, session, json y ctype ya vienen en la imagen oficial)

RUN a2enmod rewrite headers expires remoteip \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-tienda.ini"
COPY docker/apache-tienda.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/tienda-entrypoint
COPY . /var/www/html/

# Copia de los .htaccess de uploads para restaurarlos si se monta un Volume vacío encima
RUN chmod +x /usr/local/bin/tienda-entrypoint \
 && rm -rf /var/www/html/docker /var/www/html/Dockerfile /var/www/html/.dockerignore /var/www/html/railway.json \
 && mkdir -p /opt/tienda-defaults /var/www/html/uploads/products /var/www/html/uploads/private/qr \
 && cp -a /var/www/html/uploads /opt/tienda-defaults/uploads \
 && chown -R root:root /var/www/html \
 && chown -R www-data:www-data /var/www/html/uploads /var/www/html/config \
 && find /var/www/html -type d -exec chmod 755 {} + \
 && find /var/www/html -type f -exec chmod 644 {} +

ENV PORT=8080 APACHE_DOCUMENT_ROOT=/var/www/html
EXPOSE 8080
ENTRYPOINT ["tienda-entrypoint"]
CMD ["apache2-foreground"]
