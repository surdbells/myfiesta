#!/usr/bin/env bash
#
# Back to the release before this one:
#
#   bash ops/deploy/rollback.sh          the one deploy.sh last replaced
#   bash ops/deploy/rollback.sh <tag>    any release whose images are here
#
# The code, not the schema (OPERATIONS.md, "Rolling back"). The schema only
# ever grows, so the older release runs against today's database; its
# api-migrate finds nothing to do. Nothing is built: the images are the ones
# deploy.sh built for that release and kept.
#
# Safe to run again. A second run finds that release already running and only
# makes sure it is up and healthy: it does not go forward again. Going
# forward is a deploy (bash ops/deploy/deploy.sh <ref>), which finds the newer
# release's images still here and does not build them again.
#
# Everything is inside main(), called on the last line, for the same reason as
# deploy.sh: the checkout is moved back to the release's commit part way
# through, this file with it.

set -euo pipefail

SCRIPT=rollback
# shellcheck source=ops/deploy/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

main() {
    local to="${1:-}" now commit

    take_lock
    log_to rollback
    check_env_files

    now="$(running_tag)"
    [ -n "$now" ] || die "nothing has been deployed here yet (no .env.release). Nothing was done."

    if [ -z "$to" ]; then
        to="$(previous_tag)"
    fi

    [ -n "$to" ] \
        || die "no earlier release is written down (.deploy/previous). Name one: bash ops/deploy/rollback.sh <tag> — docker image ls myfiesta/api lists those here."

    have_release "$to" \
        || die "the images for $to are not all on this host (docker image ls 'myfiesta/*'). Nothing was done. Build them with bash ops/deploy/deploy.sh <its commit>."

    if [ "$to" = "$now" ]; then
        say "$to is already the release; making sure it is up and healthy"
    else
        say "rolling back from $now to $to"
    fi

    # Written first, as for a deploy: from here on every command, the next
    # restore drill included, runs this release and not the one it replaces.
    switch_to "$to" rollback

    # The checkout with it, when the tag is a commit and nothing here has been
    # changed by hand, so a legacy import or a restore drill run next reads
    # the same code as the release. Not a reason to stop a rollback if not.
    commit="$(git rev-parse --verify --quiet "$to^{commit}" || true)"
    if [ -n "$commit" ] && is_clean; then
        git checkout --quiet --detach "$commit"
    else
        say "the checkout stays at $(git rev-parse --short=12 HEAD): $to is not a commit here, or the checkout has changes of its own"
    fi

    compose up -d --no-build "${SERVES[@]}"
    compose exec -T worker php artisan queue:restart || true

    say "waiting for every container to be healthy"
    wait_healthy 420 "${SERVES[@]}" \
        || die "$to is running but not healthy (above): docker compose ps, and the logs of the ones named."

    check_live

    if [ "$PROBLEMS" -gt 0 ]; then
        die "$to is running, and $PROBLEMS check(s) after it failed (above)."
    fi

    say "$to is live. Going forward again is a deploy: bash ops/deploy/deploy.sh <ref>"
}

main "$@"; exit
