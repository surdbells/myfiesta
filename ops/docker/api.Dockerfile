# The API, as one image.
#
# php-fpm rather than a built-in server: this is the process that takes money,
# and a single-threaded dev server in front of it is not a thing to find out
# about on a Friday night. Nginx sits in front in the compose file.
#
# Three containers run from this one image — the web process, the queue worker
# and the scheduler — because they must be the same code. A worker running last
# week's release while the site runs this week's is how a job deserialises into
# a class that has changed underneath it.
FROM php:8.3-fpm-alpine AS base

# intl for currency and date formatting, gd for the image work Intervention
# does on uploads, pdo_pgsql because the schema is Postgres and nothing else,
# pcntl so a worker can be told to stop between jobs rather than mid-job.
RUN apk add --no-cache \
        icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev postgresql-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" intl zip gd pdo_pgsql bcmath pcntl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY ops/docker/php.ini /usr/local/etc/php/conf.d/myfiesta.ini

WORKDIR /var/www/api

# --- dependencies, cached apart from the source --------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY apps/api/composer.json apps/api/composer.lock ./

# No dev dependencies in an image that faces the internet: Pail, Faker and
# PHPUnit have no business on a production host.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

# The source, then the autoloader — in that order so a change to a controller
# does not reinstall every dependency, and in this stage because composer
# lives here and deliberately not in the image that ships.
COPY apps/api .

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# --- the image that runs -------------------------------------------------------
FROM base AS runtime

COPY --from=vendor /var/www/api .

# Writable by the runtime user and nobody else. Storage holds private files —
# identity documents, data exports — and is a mounted volume in production so
# a redeploy does not take somebody's export with it.
RUN chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
