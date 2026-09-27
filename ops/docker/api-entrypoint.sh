#!/bin/sh
#
# Every process the API image runs starts here.
#
# In front of the three that serve — php-fpm, the queue worker and the
# scheduler — it first asks whether production is fit to run
# (`php artisan app:preflight`) and stops if not, with every problem named at
# once — a blank webhook secret, a mailer or a text driver that only pretends
# to send, debug left on. A container that exits here is a deployment that
# fails at the door, rather than one that takes payments it can never mark
# paid. (The worker and the scheduler would refuse on their own as well —
# AppServiceProvider — and api-migrate runs the check itself before it touches
# the schema.)
#
# Then it caches the configuration, the routes and the event listeners, in
# this container. Each container has a filesystem of its own, so the caches
# api-migrate builds are thrown away with it: without this every request and
# every job read the whole configuration from scratch. Built as the process
# starts, from this container's environment, so a changed .env.production
# reaches a container when it is recreated and never half-way.
#
# Only those three. Every other command has to run on a box that is not yet
# right, because that is how it is put right: `key:generate --show` for the
# missing APP_KEY the check names, `tinker`, or `app:preflight` to look again.
#
# Outside APP_ENV=production the same list is printed and nothing is refused,
# so staging with a log mailer still starts.
set -eu

serves=no

# The first pattern is docker-php-entrypoint's own rule: a first argument
# that is an option is an option for php-fpm. The artisan commands are the
# worker and the scheduler as compose.prod.yml starts them. Not schedule:run:
# cron starts that every minute, and it is not worth three caches a minute.
case "${1:-}" in
    php-fpm | -*)
        serves=yes
        ;;
    php)
        if [ "${2:-}" = artisan ]; then
            case "${3:-}" in
                queue:work | queue:listen | schedule:work)
                    serves=yes
                    ;;
            esac
        fi
        ;;
esac

if [ "$serves" = yes ]; then
    php artisan app:preflight
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
fi

exec docker-php-entrypoint "$@"
