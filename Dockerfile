# Indexhibit on PHP 8.5 / Apache
FROM php:8.5-apache

# Install the PHP extensions Indexhibit needs. mbstring and curl are already
# compiled into the official php image, so only build the extensions it
# doesn't ship.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libwebp-dev \
        libicu-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" \
        gd \
        mysqli \
        pdo_mysql \
        exif \
        intl \
        opcache; \
    rm -rf /var/lib/apt/lists/*

# Apache: rewrite for the app, headers for static cache control, remoteip for proxy awareness.
RUN a2enmod rewrite headers remoteip

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-indexhibit.ini

COPY . /var/www/html

COPY docker/apache-indexhibit.conf /etc/apache2/conf-available/indexhibit.conf
RUN a2enconf indexhibit

RUN chown -R www-data:www-data /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD php -r 'exit(@fsockopen("127.0.0.1", 80) ? 0 : 1);'

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
