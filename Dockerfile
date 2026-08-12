FROM php:8.5-fpm-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        ffmpeg \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libsqlite3-dev \
        libzip-dev \
        sqlite3 \
        supervisor \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd intl pcntl pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-transcritor.conf
COPY docker/supervisor/worker.conf /etc/supervisor/conf.d/worker.conf
COPY docker/scripts/container-entrypoint.sh /usr/local/bin/container-entrypoint

RUN chmod +x /usr/local/bin/container-entrypoint

ENTRYPOINT ["container-entrypoint"]

FROM php-base AS composer-dependencies

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --prefer-dist

FROM node:24-bookworm-slim AS frontend-build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY vite.config.ts tsconfig.json ./

RUN npm run build

FROM php-base AS dev

COPY --from=node:24-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules

RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

COPY . .

RUN composer install --no-interaction --prefer-dist \
    && npm ci \
    && npm run build \
    && mkdir -p /opt/app-public /opt/app-storage \
    && cp -a public/. /opt/app-public/ \
    && cp -a storage/. /opt/app-storage/ \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000 5173

CMD ["php-fpm"]

FROM php-base AS prod

ENV APP_ENV=production
ENV APP_DEBUG=false

COPY . .
COPY --from=composer-dependencies /app/vendor ./vendor
COPY --from=frontend-build /app/public/build ./public/build
RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p /opt/app-public /opt/app-storage \
    && cp -a public/. /opt/app-public/ \
    && cp -a storage/. /opt/app-storage/ \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]
