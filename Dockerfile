# KaffeTrack — production image for Render (or any Docker host)
#
# Three stages keep the final image small:
#   1. vendor  — installs PHP packages with Composer
#   2. assets  — builds CSS/JS with Vite (needs vendor/ because Tailwind scans
#                Laravel's pagination views and Livewire's views for class names)
#   3. app     — PHP 8.3 + Apache with the pdo_pgsql driver for Supabase

# ── 1. PHP dependencies ──────────────────────────────────────────────────────
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# ── 2. Front-end assets ─────────────────────────────────────────────────────
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY app ./app
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ── 3. Runtime ──────────────────────────────────────────────────────────────
FROM php:8.3-apache

# PostgreSQL driver (Supabase), zip for Composer, opcache for speed.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip \
    && docker-php-ext-install pdo_pgsql pgsql zip opcache \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/kaffetrack.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod rewrite headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Optimised autoloader + package discovery now that the app code is present.
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache public/uploads/products \
    && chown -R www-data:www-data storage bootstrap/cache public/uploads \
    && chmod -R ug+rwX storage bootstrap/cache public/uploads

COPY docker/start.sh /usr/local/bin/start.sh
# Strip Windows line endings in case Git converted the script to CRLF.
RUN sed -i 's/\r$//' /usr/local/bin/start.sh && chmod +x /usr/local/bin/start.sh

# Render sets $PORT (10000 by default); start.sh makes Apache listen on it.
EXPOSE 10000
CMD ["/usr/local/bin/start.sh"]
