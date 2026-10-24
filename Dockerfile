# syntax=docker/dockerfile:1.7
#
# Container build for PENTANA, next to the existing server deployment (DEPLOYMENT.md).
# One Dockerfile, two runtime images, mirroring production's nginx + PHP-FPM split:
#
#   docker build --target app -t pentana-app .   # PHP-FPM: the web app, and the scheduler
#   docker build --target web -t pentana-web .   # nginx: static files, FastCGI to the app
#
# PHP 8.4 matches provision-server.sh; composer.lock needs >= 8.4.

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

# ---------------------------------------------------------------------------------------------
# base: PHP-FPM with the extensions the app uses (same list as provision-server.sh)
# ---------------------------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath gd intl opcache pdo_pgsql zip

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# ---------------------------------------------------------------------------------------------
# vendor: production Composer dependencies, cached on the lock file alone
# ---------------------------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# ---------------------------------------------------------------------------------------------
# assets: Vite build. Tailwind scans Blade views and a vendor view, so it needs the source tree
# ---------------------------------------------------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS assets

WORKDIR /var/www/html
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund

COPY --from=vendor /var/www/html ./
RUN npm run build

# ---------------------------------------------------------------------------------------------
# app: the runtime image. Runs as www-data; storage/ is the only writable path
# ---------------------------------------------------------------------------------------------
FROM base AS app

COPY docker/php/pentana.ini /usr/local/etc/php/conf.d/99-pentana.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-pentana.conf
COPY docker/entrypoint.sh /usr/local/bin/pentana-entrypoint
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=vendor --chown=www-data:www-data /var/www/html ./
COPY --from=assets --chown=www-data:www-data /var/www/html/public/build ./public/build

# Writable storage, created here so an empty named volume is seeded with the right owner.
# public/storage is a relative symlink, so the same path resolves in the web image too.
RUN rm -f .env bootstrap/cache/*.php \
    && mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache \
    && ln -sfn ../storage/app/public public/storage \
    && composer clear-cache

USER www-data

# package:discover is a composer script we skipped above; it needs no database or .env.
RUN php artisan package:discover --ansi

EXPOSE 9000
ENTRYPOINT ["pentana-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------------------------
# web: unprivileged nginx serving public/ and passing PHP to the app container
# ---------------------------------------------------------------------------------------------
FROM nginxinc/nginx-unprivileged:1.28-alpine AS web

COPY docker/nginx/default.conf.template /etc/nginx/templates/default.conf.template
COPY --from=app /var/www/html/public /var/www/html/public

ENV PHP_FPM_HOST=app:9000
EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=6 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/up || exit 1
