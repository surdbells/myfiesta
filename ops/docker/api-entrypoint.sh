#!/bin/sh
#
# Every process the API image runs starts here.
#
# In front of php-fpm it first asks whether production is fit to run
# (`php artisan app:preflight`) and stops if not, with every problem named at
# once — a blank webhook secret, a mailer or a text driver that only pretends
# to send, debug left on. A container that exits here is a deployment that
# fails at the door, rather than one that takes payments it can never mark
# paid.
#
# Only in front of php-fpm. The worker and the scheduler refuse to start on
# their own (AppServiceProvider), and api-migrate runs the check itself before
# it touches the schema. Every other command has to run on a box that is not
# yet right, because that is how it is put right: `key:generate --show` for the
# missing APP_KEY the check names, `tinker`, or `app:preflight` to look again.
#
# Outside APP_ENV=production the same list is printed and nothing is refused,
# so staging with a log mailer still starts.
set -eu

# The second pattern is docker-php-entrypoint's own rule: a first argument
# that is an option is an option for php-fpm.
case "${1:-}" in
    php-fpm | -*)
        php artisan app:preflight
        ;;
esac

exec docker-php-entrypoint "$@"
