# Backend CASA — Laravel exécuté en PHP-FPM (le service "nginx" du
# docker-compose fait office de reverse proxy / serveur HTTP devant ce
# conteneur). Image de développement du squelette (Lot 0) : aucune
# optimisation de production (OPcache, multi-stage minimal...) n'est faite
# ici, ce sera affiné avec le vrai code applicatif.
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        postgresql-dev \
        libzip-dev \
        zip \
        unzip \
        git \
    && docker-php-ext-install pdo pdo_pgsql zip

# Composer (binaire officiel, pas de réinstallation depuis les sources)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

# En développement, ce répertoire est de toute façon remplacé par le bind
# mount du docker-compose (./backend:/var/www/html) : ce `composer install`
# ne sert qu'à garantir une image utilisable seule (CI, build isolé).
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]
