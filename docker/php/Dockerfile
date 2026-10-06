# LOCAL DEV ONLY — production runs on shared hosting without Docker.
FROM php:8.1-apache

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip git \
    && install-php-extensions pdo_mysql intl zip gd exif \
    # PECL no longer serves pcov, so build it from the upstream repo.
    && git clone --depth 1 https://github.com/krakjoe/pcov.git /tmp/pcov \
    && cd /tmp/pcov && phpize && ./configure && make -j"$(nproc)" && make install \
    && docker-php-ext-enable pcov && cd / && rm -rf /tmp/pcov \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/api/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && printf '<Directory /var/www/api/public>\n    AllowOverride All\n</Directory>\n' > /etc/apache2/conf-enabled/consultdesk.conf

WORKDIR /var/www/api
