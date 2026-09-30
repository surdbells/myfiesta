#!/usr/bin/env bash
#
# A release, start to finish, on the host the platform runs on:
#
#   bash ops/deploy/deploy.sh            the newest commit on origin/main
#   bash ops/deploy/deploy.sh <ref>      a branch on origin, a tag or a commit
#
# What OPERATIONS.md's "Deploys and rolling back" does by hand, in its order:
# fetch the ref, build its images under the commit's hash, ask them whether
# production is fit to run (app:preflight), back the database up when there
# are migrations to run, migrate (api-migrate), write .env.release, replace
# the containers, and wait until every one is healthy and the readiness check
# passes. The release it replaced is written down for rollback.sh.
#
# Safe to run again, with the same ref or another. A release already built is
# not built again (REBUILD=1 to insist), migrations already run are not run
# again, and a container already running the release is left alone. A run
# that stopped half way — a build that failed, a lost SSH session — is
# finished by running it again.
#
# Images are built here, from the checkout: CI builds every image on every
# change to prove it builds, and pushes none. A registry would move the build
# off this box; nothing here needs one.
#
# docs/RUNBOOK-CONTABO-AAPANEL.md, "Routine deploys".
#
# Everything is inside main(), called on the script's last line. The script
# checks its own directory out at another commit part way through, and bash
# reads a script as it runs it: without that, it could carry on reading the
# new commit's copy of this file from the old one's line numbers.

set -euo pipefail

SCRIPT=deploy
# shellcheck source=ops/deploy/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

# How many releases' images are kept on this host, the running one and the one
# before it included. Older ones are built again from git if ever wanted.
KEEP_RELEASES="${KEEP_RELEASES:-3}"

# A branch on origin, then a tag or a commit. A branch is looked up on origin
# so a stale local branch of the same name is never what gets deployed.
resolve() {
    git rev-parse --verify --quiet "refs/remotes/origin/$1^{commit}" \
        || git rev-parse --verify --quiet "$1^{commit}" \
        || die "$1 is not a branch on origin, a tag or a commit. Nothing was done."
}

# Built one at a time rather than in parallel: two Angular builds at once
# want more memory than a small VPS has, and one killed half way is a
# release that fails for no reason it will say. --pull, so each release
# starts from the base images' latest patches rather than whichever were
# pulled the first time. The API's image is api-migrate's, the worker's and
# the scheduler's too.
build() {
    local tag="$1" service

    for service in api api-web site console; do
        say "building $service for $tag"
        TAG="$tag" compose build --pull "$service"
    done

    # The import's image, while the move from the old platform is under way
    # (LEGACY_DB_HOST is set): the release's own with a MySQL driver, so
    # legacy-sync.sh always has one for whichever release is running.
    if [ -n "$(setting LEGACY_DB_HOST)" ] && has_service postgres; then
        say "building the legacy import's image for $tag (LEGACY_DB_HOST is set)"
        TAG="$tag" compose --profile legacy build legacy
    fi
}

# The migrations the new release would run, as its own image lists them.
# Empty on a database that has none yet.
pending_migrations() {
    TAG="$1" compose run --rm --no-deps -T api php artisan migrate:status --pending 2> /dev/null \
        | grep 'Pending' || true
}

# Every release's images except the newest few this host has run. What
# rollback.sh goes back to is always among those kept.
prune_releases() {
    local ref tag
    local -a keep

    [ -f "$STATE/history" ] || return 0

    mapfile -t keep < <(awk '{ print $2 }' "$STATE/history" | tac | awk '!seen[$0]++' | head -n "$KEEP_RELEASES")
    keep+=("$(running_tag)" "$(previous_tag)")

    docker image ls --format '{{.Repository}}:{{.Tag}}' \
        | { grep -E '^myfiesta/(api|api-legacy|api-web|web|console):' || true; } \
        | while read -r ref; do
            tag="${ref##*:}"
            if ! printf '%s\n' "${keep[@]}" | grep -qxF "$tag"; then
                say "removing $ref, older than the last $KEEP_RELEASES releases"
                docker image rm "$ref" > /dev/null || true
            fi
        done

    docker image prune -f > /dev/null || true
}

# The checkout back at the release that is still running, after a deploy that
# stopped before replacing it. Every command after this reads the compose
# files from the checkout, and the running release should be read with its
# own, not with the ones of a release that never went live. Called from the
# EXIT trap main() sets.
# shellcheck disable=SC2317,SC2329
return_to() {
    local commit
    commit="$(git rev-parse --verify --quiet "$1^{commit}" || true)"

    if [ -n "$commit" ] && is_clean; then
        git checkout --quiet --detach "$commit"
        say "the checkout is back at $1, the release still running"
    fi
}

# A backup of the database as it is, by the running release's scheduler, or
# by its image when the scheduler is not running.
back_up() {
    if [ -n "$(compose ps -q --status running scheduler 2> /dev/null)" ]; then
        compose exec -T scheduler php artisan backup:run
    else
        compose run --rm --no-deps -T scheduler php artisan backup:run
    fi
}

main() {
    local ref="${1:-main}" commit new was pending

    take_lock
    log_to deploy
    check_env_files
    check_clean

    say "fetching from origin"
    git fetch --prune --tags origin

    commit="$(resolve "$ref")"
    new="$(git rev-parse --short=12 "$commit")"
    was="$(running_tag)"

    # A release .env.release names but that never ran: a first deploy that
    # stopped before starting anything. Nothing to back up, and nothing for a
    # rollback to go back to.
    if [ -n "$was" ] && [ ! -s "$STATE/history" ] && [ -z "$(compose ps -q api 2> /dev/null)" ]; then
        was=""
    fi

    if [ -n "$was" ]; then
        say "deploying $ref at $new (running now: $was)"
    else
        say "deploying $ref at $new, the first release on this host"
    fi

    git checkout --quiet --detach "$commit"

    # Until the new release is the one .env.release names, however this run
    # ends. The tag is written in now: it is hex, and by the time the trap
    # runs this function's variables may be gone.
    if [ -n "$was" ] && [ "$was" != "$new" ]; then
        # shellcheck disable=SC2064
        trap "return_to '$was'" EXIT
    fi

    # compose reads .env.release on every command and will not run one
    # without it. On the first deploy nothing is running, so the new release
    # is the one to name — and the one switch_to finds there, so no release
    # that never ran is kept as the one to roll back to.
    if [ -z "$was" ]; then
        write_release "$new"
    fi

    if [ "${REBUILD:-0}" = 1 ] || ! have_release "$new"; then
        build "$new"
    else
        say "the images for $new are already built; not building them again (REBUILD=1 to insist)"
    fi

    say "checking $new is fit to run against .env.production (app:preflight)"
    TAG="$new" compose run --rm --no-deps -T api php artisan app:preflight \
        || die "$new is not fit to run (above). Nothing was replaced: fix .env.production and run this again."

    # The database and Redis first, on one box: the migrations need them, and
    # a first deploy has neither yet. Already running, they are left alone.
    if has_service postgres; then
        say "starting Postgres and Redis if they are not running"
        compose up -d --wait postgres redis \
            || die "Postgres or Redis did not become healthy (docker compose logs postgres redis). Nothing was replaced."
    fi

    pending="$(pending_migrations "$new")"

    if [ -n "$pending" ]; then
        say "$new has migrations to run:"
        printf '%s\n' "$pending"

        # A backup of the state before them, as OPERATIONS.md's checklist
        # asks. Not before anything has ever run here: there is nothing to
        # back up.
        if [ -n "$was" ] && [ "${SKIP_BACKUP:-0}" != 1 ]; then
            say "backing the database up before they run (backup:run; SKIP_BACKUP=1 to go without)"
            back_up || die "the backup failed (above). Nothing was migrated or replaced."
        fi
    fi

    # Once, before anything serves the new code: the check again, the
    # migrations, and the caches, so a release that cannot cache stops here
    # too. Nothing has been replaced yet; a failure leaves whatever was
    # serving serving.
    say "migrating (api-migrate)"
    TAG="$new" compose run --rm -T api-migrate \
        || die "api-migrate failed (above). Nothing was replaced. Fix it and run this again."

    # Written before the containers are replaced (DEPLOYMENT.md, "A
    # release"): from here on every command runs the new release, and the
    # one it replaces is kept for rollback.sh.
    switch_to "$new"
    trap - EXIT

    say "replacing the containers"
    compose up -d --no-build \
        || die "compose could not start everything (above). .env.release names $new: put it right and run this again, or go back with bash ops/deploy/rollback.sh"

    # The worker was recreated with the new code already; said anyway, so a
    # worker that somehow was not picks up the new code between jobs.
    compose exec -T worker php artisan queue:restart || true

    say "waiting for every container to be healthy"
    if ! wait_healthy 420 "${SERVES[@]}"; then
        die "$new is live but not healthy (above): docker compose ps, and the logs of the ones named. To go back: bash ops/deploy/rollback.sh"
    fi

    check_live

    prune_releases

    if [ "$PROBLEMS" -gt 0 ]; then
        die "$new is live, and $PROBLEMS check(s) after it failed (above). If it should not be: bash ops/deploy/rollback.sh"
    fi

    if [ -n "$(previous_tag)" ]; then
        say "$new is live; $(previous_tag) is what bash ops/deploy/rollback.sh goes back to"
    else
        say "$new is live"
    fi
}

main "$@"; exit
