# Signage Forge image for Podman (also builds with Docker).
#   podman build -t signageforge .
#   podman run -d -p 8080:80 -v signageforge-data:/srv/signageforge/data \
#     -e DEFAULT_URI=http://localhost:8080 -e APP_SECRET=change-me signageforge
# See docs/deployment.md for configuration and for running without containers.

FROM docker.io/library/composer:2 AS composer

FROM docker.io/library/php:8.4-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev libldap2-dev libpq-dev libzip-dev unzip \
 && docker-php-ext-install intl ldap opcache pdo_mysql pdo_pgsql zip \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY deploy/php.ini /usr/local/etc/php/conf.d/signageforge.ini
COPY deploy/apache-container.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /srv/signageforge
ENV APP_ENV=prod APP_DEBUG=0

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --classmap-authoritative --no-dev \
 && php bin/console tailwind:build --minify \
 && php bin/console asset-map:compile \
 && php bin/console cache:clear \
 && mkdir -p data/uploads \
 && chown -R www-data:www-data var data

VOLUME ["/srv/signageforge/data"]
EXPOSE 80

# Apply pending migrations, then serve.
CMD ["sh", "-c", "su -s /bin/sh www-data -c 'php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration' && exec apache2-foreground"]
