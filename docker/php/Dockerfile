# syntax=docker/dockerfile:1
# Multi-stage build for the Mnemosyne Laravel application.
# Targets:
#   app -> php-fpm runtime (also used for horizon / scheduler commands)
#   web -> nginx serving public/ and proxying PHP to the app service

# ---------- PHP base with required extensions ----------
FROM php:8.4-fpm-bookworm AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions pdo_pgsql pgsql intl zip bcmath pcntl opcache redis \
    && apt-get update \
    && apt-get install -y --no-install-recommends procps \
    && rm -rf /var/lib/apt/lists/*

# Match the host mnemosyne user so bind mounts under /srv/data/mnemosyne
# never end up root-owned.
RUN groupadd -g 1003 mnemosyne && useradd -u 1003 -g 1003 -m mnemosyne

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-mnemosyne.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-mnemosyne.conf

WORKDIR /app

# ---------- Composer vendor ----------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY apps/web/composer.json apps/web/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY apps/web/ ./
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi

# ---------- Frontend assets ----------
FROM node:22-bookworm-slim AS assets

WORKDIR /app

COPY apps/web/package.json apps/web/package-lock.json ./
RUN npm ci

COPY apps/web/ ./
RUN npm run build

# ---------- Application runtime (php-fpm) ----------
FROM base AS app

COPY --from=vendor --chown=mnemosyne:mnemosyne /app /app
COPY --from=assets --chown=mnemosyne:mnemosyne /app/public/build /app/public/build

RUN rm -rf /app/.env /app/node_modules /app/tests \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R mnemosyne:mnemosyne storage bootstrap/cache

# php-fpm master runs as root; the worker pool runs as mnemosyne (www.conf).
CMD ["php-fpm"]

# ---------- Web server ----------
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=vendor /app/public /var/www/public
COPY --from=assets /app/public/build /var/www/public/build
