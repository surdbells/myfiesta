# shellcheck shell=bash
#
# What ops/deploy/*.sh share. Sourced by each of them, never run on its own:
# where the checkout is, the compose command all of them run, the lock that
# keeps two of them off one checkout at once, and a log of every run.
#
# docs/RUNBOOK-CONTABO-AAPANEL.md says when to run which.

# The checkout, wherever the script was started from. Every path below is
# named from here, the way compose names them.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT" || exit 1

# The host's own record, ignored by git and by docker: the release before this
# one, every release in the order it ran, the lock and the logs. Readable by
# the deploy user alone: the logs of a legacy import list the old database's
# rows by id.
#
# Only this directory, and .env.release, are kept from other users. Not the
# whole run with a umask: the scripts check source out, and Docker copies a
# file into an image with the mode it has here, so a source file written 600
# is one the image's www-data cannot read.
STATE="$ROOT/.deploy"
LOGS="$STATE/logs"

# The compose files, in order: compose.prod.yml and, on one box with Postgres
# and Redis beside the application, the override for that. A host whose
# database is somewhere else sets COMPOSE_FILES=ops/docker/compose.prod.yml.
COMPOSE_FILES="${COMPOSE_FILES:-ops/docker/compose.prod.yml ops/docker/compose.contabo.yml}"

# The processes that run for good, in the order they are waited for. Not
# api-migrate, which runs once per deploy and exits; not the import, which is
# behind a profile. Read by deploy.sh and rollback.sh.
# shellcheck disable=SC2034
SERVES=(api worker scheduler api-web site console)

say() {
    printf '%s: %s\n' "$SCRIPT" "$*"
}

die() {
    printf '%s: %s\n' "$SCRIPT" "$*" >&2
    exit 1
}

# docker compose as OPERATIONS.md's `fiesta` runs it: the settings from
# .env.production, the release from .env.release. TAG=<tag> in front of it
# names a release that is not running yet, for that one command only.
compose() {
    local -a files args=(--env-file .env.production --env-file .env.release)
    local file

    read -r -a files <<< "$COMPOSE_FILES"

    for file in "${files[@]}"; do
        args+=(-f "$file")
    done

    docker compose "${args[@]}" "$@"
}

# Whether the compose files define this service: postgres and redis only
# with the one-box override. Anything after the name goes to compose before
# `config`, such as --profile legacy for a service behind a profile.
#
# The list is read whole before it is searched. Piped into grep -q, under
# pipefail, grep stops at the first match and compose — which prints one
# service per write — can be killed for writing the next one into a closed
# pipe, and then the service is "not there" because it was there first. And
# compose failing to read its files is said as that, not as a missing
# service.
has_service() {
    local name="$1" services
    shift

    services="$(compose "$@" config --services)" \
        || die "compose could not read its files ($COMPOSE_FILES) with .env.production and .env.release: what it said is above. Nothing more was done."

    grep -qxF "$name" <<< "$services"
}

# A value from .env.production, as compose would read it: the last line that
# sets it, quotes and a Windows line ending taken off.
setting() {
    sed -n "s/^$1=//p" .env.production | tail -n 1 | tr -d '\r' | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"
}

make_state() {
    mkdir -p "$STATE" "$LOGS"
    chmod 700 "$STATE" "$LOGS"
}

# One of these at a time on this checkout — a deploy, a rollback or a legacy
# import — held until the script exits, however it exits. A lock the kernel
# holds rather than a file that says "running": a file left behind by a run
# that was killed would refuse every run after it, and the kernel lets go of
# a lock when its process goes. 75 is "try again later", for cron.
take_lock() {
    make_state
    exec 9> "$STATE/lock"

    if ! flock -n 9; then
        printf '%s: a deploy, rollback or legacy sync is already running on this checkout. Nothing was done.\n' "$SCRIPT" >&2
        exit 75
    fi
}

# Everything this run prints goes to a log of its own as well as the screen,
# so a run started from cron or lost with an SSH session can still be read.
log_to() {
    make_state
    LOG="$LOGS/$1-$(date -u +%Y%m%dT%H%M%SZ).log"
    exec > >(tee -a "$LOG") 2>&1
    say "this run's log: $LOG"
}

# The release .env.release names; nothing before the first deploy.
running_tag() {
    if [ -f .env.release ]; then
        sed -n 's/^TAG=//p' .env.release | tail -n 1 | tr -d '\r'
    fi
}

# The release that ran before this one, as deploy.sh left it.
previous_tag() {
    if [ -f "$STATE/previous" ]; then
        sed -n 's/^TAG=//p' "$STATE/previous" | tail -n 1
    fi
}

# Writes .env.release on its own, for the first deploy: compose reads it on
# every command, and will not run one without it.
write_release() {
    (umask 077 && printf 'TAG=%s\n' "$1" > .env.release)
}

# Makes <tag> the release every compose command runs from now on, and adds it
# to the history. A deploy also writes down the release it replaces, for
# rollback.sh to go back to. A rollback leaves that as it is, so running it a
# second time finds it already done instead of going forward again.
switch_to() {
    local tag="$1" mode="${2:-deploy}" was
    was="$(running_tag)"

    make_state

    if [ "$mode" = deploy ] && [ -n "$was" ] && [ "$was" != "$tag" ]; then
        printf 'TAG=%s\n' "$was" > "$STATE/previous"
    fi

    write_release "$tag"
    printf '%s %s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$tag" "$SCRIPT" >> "$STATE/history"
}

# The files every compose command reads: there, and readable by the deploy
# user alone. .env.production holds every credential the platform has.
check_env_files() {
    local file

    [ -f .env.production ] \
        || die "there is no .env.production in $ROOT. Make it from .env.production.example first (docs/RUNBOOK-CONTABO-AAPANEL.md)."

    for file in .env.production .env.release; do
        if [ -f "$file" ] && [ "$(stat -c %a "$file")" != 600 ]; then
            chmod 600 "$file"
            say "$file could be read by others; it is 600 now"
        fi
    done
}

# Whether the working tree is exactly a commit. A deploy is what is in git,
# and a change made by hand on the server is one the next deploy would build
# into an image nobody can reproduce, or quietly throw away.
is_clean() {
    git diff --quiet && git diff --cached --quiet
}

check_clean() {
    if ! is_clean; then
        git status --short
        die "this checkout has changes of its own (above). Nothing was done: commit them where they belong, or throw them away (git checkout -- .), then run this again."
    fi
}

# The images one release is made of, all of them present on this host.
have_release() {
    local tag="$1" image

    for image in api api-web web console; do
        docker image inspect "myfiesta/$image:$tag" > /dev/null 2>&1 || return 1
    done
}

# Waits up to $1 seconds for every service named after it to be healthy.
# True once they all are; false, naming the ones that are not, when time runs
# out. A container without a healthcheck counts once it is running.
wait_healthy() {
    local timeout="$1" deadline service id status
    local -a waiting
    shift
    deadline=$((SECONDS + timeout))

    while :; do
        waiting=()

        for service in "$@"; do
            id="$(compose ps -q "$service" 2> /dev/null | head -n 1)"

            if [ -z "$id" ]; then
                status=missing
            else
                status="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$id")"
            fi

            if [ "$status" != healthy ] && [ "$status" != running ]; then
                waiting+=("$service ($status)")
            fi
        done

        if [ "${#waiting[@]}" -eq 0 ]; then
            return 0
        fi

        if [ "$SECONDS" -ge "$deadline" ]; then
            say "still not healthy after ${timeout}s: ${waiting[*]}"
            return 1
        fi

        sleep 5
    done
}

# Waits up to $1 seconds for the readiness check to pass: everything an order
# needs, the queue and the scheduler included (OPERATIONS.md, "What to watch").
# On a first deploy the queue and the scheduler fail for a minute or two,
# until each has run once.
wait_ready() {
    local timeout="$1" deadline
    deadline=$((SECONDS + timeout))

    until compose exec -T api php artisan app:health > /dev/null 2>&1; do
        if [ "$SECONDS" -ge "$deadline" ]; then
            compose exec -T api php artisan app:health || true
            return 1
        fi

        sleep 10
    done
}

# The checks after a release is live (OPERATIONS.md): ready, the same
# time-zone edition in the API and the site with the database agreeing, and
# the API answering through whatever terminates TLS on this host. Each one
# that fails is named and counted in PROBLEMS; none of them stops the others.
check_live() {
    local edition url

    PROBLEMS=0

    say "waiting for the readiness check (/api/health/ready's checks, from inside)"
    if ! wait_ready 300; then
        say "the readiness check is failing (above)"
        PROBLEMS=$((PROBLEMS + 1))
    fi

    edition="$(grep -v '^#' ops/docker/tzdata-edition | tr -d '[:space:]')"
    say "checking the API, the database and the site read time-zone edition $edition"
    if ! compose exec -T api php artisan app:time-zones --expect="$edition"; then
        PROBLEMS=$((PROBLEMS + 1))
    fi
    if ! compose exec -T site node tz-version.mjs "$edition"; then
        PROBLEMS=$((PROBLEMS + 1))
    fi

    url="$(setting APP_URL)"
    if [ -n "$url" ]; then
        if curl -fsS -o /dev/null --max-time 15 "$url/up"; then
            say "$url/up answers through the TLS in front"
        else
            # Not counted: before DNS points here, this is expected.
            say "$url/up does not answer from here. Before DNS points at this host that is expected; after, check the aaPanel site for it (docs/RUNBOOK-CONTABO-AAPANEL.md)."
        fi
    fi
}
