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

# pg_dump and pg_restore, for the nightly backup (backup:run) and the restore
# drill (backup:restore) — docs/OPERATIONS.md. The major version matches the
# server's: pg_dump refuses a server newer than itself, so this moves with the
# database, never behind it.
RUN apk add --no-cache postgresql17-client

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

# Where backups go with BACKUP_TARGET=volume. Made here, owned by the runtime
# user, so the volume mounted over it starts out writable by that user and
# nobody else.
RUN mkdir -p /var/backups/myfiesta \
    && chown www-data:www-data /var/backups/myfiesta \
    && chmod 0700 /var/backups/myfiesta

# Which build this is, for Sentry and for every backup's manifest: the tag the
# images are built with (compose.prod.yml passes TAG). An error then names the
# release that raised it, and a rollback shows as the older release returning.
ARG RELEASE=
ENV SENTRY_RELEASE=${RELEASE}

# php-fpm, the worker and the scheduler check production is fit to run before
# they start (app:preflight), and the container stops with the list if it is
# not; then each caches its configuration, routes and events in its own
# container, which is the only place a cache can reach it. None is baked in
# here: the configuration is the environment the container is started with.
# Other commands pass straight through, so artisan still works on a box that
# is being put right.
# Line endings stripped because a checkout on Windows writes CRLF, and a
# shebang ending in a carriage return is a script that will not run.
COPY ops/docker/api-entrypoint.sh /usr/local/bin/myfiesta-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/myfiesta-entrypoint \
    && chmod 0755 /usr/local/bin/myfiesta-entrypoint

USER www-data

EXPOSE 9000

ENTRYPOINT ["myfiesta-entrypoint"]
CMD ["php-fpm"]
