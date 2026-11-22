FROM node:24-bookworm-slim AS assets
WORKDIR /src
COPY package*.json ./
RUN npm ci
COPY vite.config.ts tsconfig.json ./
COPY resources ./resources
RUN npm run build

FROM php:8.4-fpm-bookworm AS runtime
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY artisan ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources/views ./resources/views
COPY routes ./routes
RUN mkdir -p bootstrap/cache storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --optimize --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache
COPY --from=assets /src/public/build /var/www/public/build

FROM runtime AS smtp
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends postfix postfix-pgsql ca-certificates \
    && rm -rf /var/lib/apt/lists/*
COPY deploy/postfix/start.sh /usr/local/bin/mailmm-postfix
ENTRYPOINT ["sh", "/usr/local/bin/mailmm-postfix"]

FROM nginx:stable-alpine AS web
COPY nginx/conf.d/default.conf /etc/nginx/conf.d/default.conf
COPY nginx/upstreams.conf /etc/nginx/conf.d/mailmm-upstreams.conf
COPY nginx/mailmm-locations.conf /etc/nginx/mailmm/locations.conf
COPY nginx/https.conf /etc/nginx/mailmm/https.conf
COPY --from=runtime /var/www/public /var/www/public

FROM runtime AS app
CMD ["php-fpm"]
