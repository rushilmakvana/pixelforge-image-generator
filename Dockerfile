# Production image for Render (or any Docker host).
# One container runs the web server, the queue worker and the scheduler,
# because Render's free tier has no separate background workers.

# --- 1. PHP dependencies (needed before the frontend build: app.ts imports Ziggy from vendor/) ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

# --- 2. Frontend assets ---
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
COPY --from=vendor /app/vendor/tightenco ./vendor/tightenco
ENV VITE_APP_NAME=PromptForge
RUN npm run build

# --- 3. Runtime: FrankenPHP (Caddy + PHP in one binary) ---
FROM dunglas/frankenphp:1-php8.3
RUN install-php-extensions pdo_pgsql intl zip opcache pcntl bcmath \
    # The base image grants frankenphp the capability to bind ports < 1024. Hosts that drop
    # capabilities (Render) then refuse to exec it ("Operation not permitted"). We listen on
    # $PORT (> 1024), so remove it.
    && setcap -r /usr/local/bin/frankenphp
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
RUN mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && composer dump-autoload --optimize --no-dev \
    && chmod +x docker/start.sh

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PORT=8080
EXPOSE 8080

CMD ["docker/start.sh"]
