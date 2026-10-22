FROM php:8.5-cli-alpine AS php-base
RUN apk add --no-cache libpq-dev icu-dev libzip-dev oniguruma-dev linux-headers && docker-php-ext-install pdo_pgsql intl zip mbstring pcntl
WORKDIR /app
FROM php-base AS build
RUN apk add --no-cache nodejs npm git unzip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
ENV COMPOSER_MIRROR_PATH_REPOS=1
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache && composer install --no-dev --no-interaction --optimize-autoloader && npm ci && npm run build && rm -rf node_modules
FROM php-base AS runtime
COPY --from=build /app /app
RUN addgroup -g 1000 app && adduser -D -u 1000 -G app app && chown -R app:app storage bootstrap/cache
USER app
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/up")===false?1:0);'
CMD ["php","artisan","serve","--host=0.0.0.0","--port=8080","--no-reload"]
