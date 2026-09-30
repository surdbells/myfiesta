#!/usr/bin/env bash
#
# One run of the import from the old platform, from the old app's MySQL into
# this platform's database (docs/CUTOVER.md):
#
#   bash ops/deploy/legacy-sync.sh                  import, then a reconcile dry run
#   bash ops/deploy/legacy-sync.sh --dry-run        what an import would bring; writes nothing
#   bash ops/deploy/legacy-sync.sh --posters        the posters as well, after the rows
#   bash ops/deploy/legacy-sync.sh --no-reconcile   the import alone (the hourly run)
#   bash ops/deploy/legacy-sync.sh --frozen         the last run, once the old app has stopped
#
# In order: legacy:import, then with --posters legacy:import --posters-only,
# then legacy:reconcile without --apply, which only reads. Each step runs
# whatever the one before it said, and the end of the run is a line per step
# with how it ended. Exit 0 when every step did; 1 when any did not; 75 when
# another deploy, rollback or sync holds this checkout, having done nothing.
#
# Safe to run any number of times, during the parallel run and after it. Each
# run brings only the rows the old database has that this one does not, tries
# again the ones that failed, and lists the ones the old app changed or
# deleted since they came across without touching them. Two runs never
# overlap: this script holds the checkout's lock, and legacy:import holds one
# in the database whatever started it.
#
# It runs in the `legacy` service of compose.contabo.yml: the running release's
# image with a MySQL driver added, reading the old database through an
# account that can only read it (docs/RUNBOOK-CONTABO-AAPANEL.md, "The old
# database, read-only"). Nothing here writes to the old database.
#
# The log of every run is kept in .deploy/logs, readable by the deploy user
# alone. It holds legacy ids, order references and statuses, never a name, an
# email address or a ticket code.

set -euo pipefail

SCRIPT=legacy-sync
# shellcheck source=ops/deploy/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

usage() {
    sed -n '3,10p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

STEPS=()
RESULTS=()

# Runs one step in the import's container and writes down how it ended,
# without stopping the run: the reconcile after a failed import still says
# what it can about the rows that did come across.
step() {
    local name="$1" rc
    shift

    say "--- $name"

    if compose --profile legacy run --rm -T legacy "$@"; then
        rc=0
    else
        rc=$?
    fi

    STEPS+=("$name")
    RESULTS+=("$rc")
}

# The import's image for the running release, built from the checkout when it
# is missing and the checkout is that release. deploy.sh builds it with each
# release while LEGACY_DB_HOST is set, so this is for the first run.
import_image() {
    local tag="$1"

    if docker image inspect "myfiesta/api-legacy:$tag" > /dev/null 2>&1; then
        return 0
    fi

    if [ "$(git rev-parse --verify --quiet "$tag^{commit}" || true)" != "$(git rev-parse HEAD)" ] || ! is_clean; then
        die "there is no import image for the running release $tag, and this checkout is not exactly $tag, so one built from it would be another release's code under $tag's name. Run bash ops/deploy/deploy.sh $tag first."
    fi

    say "building the import's image for $tag (once per release)"
    compose --profile legacy build legacy
}

main() {
    local dry_run=no frozen=no posters=no reconcile=yes arg tag i failed=0
    local -a import=(php artisan legacy:import)

    for arg in "$@"; do
        case "$arg" in
            --dry-run) dry_run=yes ;;
            --frozen) frozen=yes ;;
            --posters) posters=yes ;;
            --no-reconcile) reconcile=no ;;
            -h | --help)
                usage
                exit 0
                ;;
            *)
                usage >&2
                exit 64
                ;;
        esac
    done

    take_lock
    log_to legacy-sync
    check_env_files

    [ -n "$(setting LEGACY_DB_HOST)" ] \
        || die "LEGACY_DB_HOST is not set in .env.production: there is no old database to read (docs/RUNBOOK-CONTABO-AAPANEL.md, \"The old database, read-only\")."

    # Before compose is asked anything: it reads .env.release on every
    # command, and without one would fail for a reason that is not this.
    tag="$(running_tag)"
    [ -n "$tag" ] || die "no release is running here yet: bash ops/deploy/deploy.sh first. The import writes into its schema."

    # Asked with the profile on: a service behind one is left out otherwise.
    has_service legacy --profile legacy \
        || die "the compose files ($COMPOSE_FILES) have no legacy service: it is in ops/docker/compose.contabo.yml."

    import_image "$tag"

    say "release $tag; dry run: $dry_run; frozen: $frozen; posters: $posters; reconcile: $reconcile"

    if [ "$dry_run" = yes ]; then
        import+=(--dry-run)

        # A dry run counts the posters still to move rather than moving them.
        if [ "$posters" = yes ]; then
            import+=(--posters)
        fi
    fi

    if [ "$frozen" = yes ]; then
        import+=(--frozen)
    fi

    step "${import[*]:2}" "${import[@]}"

    if [ "$dry_run" = no ] && [ "$posters" = yes ]; then
        step "legacy:import --posters-only" php artisan legacy:import --posters-only
    fi

    # Only reads: the imported orders against Stripe, a new report each run
    # (docs/CUTOVER.md, "Reading the report"). Not in a dry run, which
    # imported nothing new to check.
    if [ "$dry_run" = no ] && [ "$reconcile" = yes ]; then
        step "legacy:reconcile" php artisan legacy:reconcile
    fi

    say "--- how each step ended"
    for i in "${!STEPS[@]}"; do
        printf '  %-40s exit %s\n' "${STEPS[$i]}" "${RESULTS[$i]}"
        [ "${RESULTS[$i]}" = 0 ] || failed=1
    done

    if [ "$reconcile" = yes ] && [ "$dry_run" = no ]; then
        say "the reconcile report: newest in storage/app/reconciliation (docs/RUNBOOK-CONTABO-AAPANEL.md, \"Reading what a run said\")"
    fi

    say "this run's log: $LOG"

    return "$failed"
}

main "$@"; exit
