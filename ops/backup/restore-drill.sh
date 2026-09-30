#!/bin/sh
#
# The monthly restore drill (docs/OPERATIONS.md), start to finish.
#
#   ops/backup/restore-drill.sh                 the newest backup
#   ops/backup/restore-drill.sh <backup name>   one from backup:list
#   KEEP=1 ops/backup/restore-drill.sh          leave the database to look at
#
# Restores into a new database beside the live one, named for today, checks
# every table's rows against the backup's manifest, prints how long that took,
# and drops the database again unless KEEP is set. The live database is never
# touched: backup:restore refuses it by name.
#
# Run from anywhere on the host that has .env.production, .env.release and the
# compose file. It runs in the release that is running — the image .env.release
# names — so the drill checks the code a real restore would use.
# The time it prints is the restore half of the recovery time; write it into
# OPERATIONS.md under "RPO and RTO".
set -eu

cd "$(dirname "$0")/../.."

for file in .env.production .env.release; do
    if [ ! -f "$file" ]; then
        echo "restore-drill: no $file here — run it on the host the platform runs on (docs/OPERATIONS.md)." >&2
        exit 1
    fi
done

# The compose files the platform runs from, as ops/deploy/*.sh read them:
# COMPOSE_FILES, space-separated. On one box with compose.contabo.yml that is
# both files (docs/RUNBOOK-CONTABO-AAPANEL.md); read with the first alone,
# compose would find the project's network set up other than it expects.
FILES=""
for file in ${COMPOSE_FILES:-ops/docker/compose.prod.yml}; do
    FILES="$FILES -f $file"
done

COMPOSE="docker compose --env-file .env.production --env-file .env.release$FILES"
INTO="myfiesta_drill_$(date -u +%Y%m%d_%H%M)"

echo "restore-drill: the backups there are"
$COMPOSE run --rm --no-deps scheduler php artisan backup:list

if [ -n "${KEEP:-}" ]; then
    echo "restore-drill: restoring into $INTO, and keeping it. Drop it when done: DROP DATABASE $INTO;"
    $COMPOSE run --rm --no-deps scheduler php artisan backup:restore ${1:+"$1"} --into="$INTO" --create
else
    echo "restore-drill: restoring into $INTO, then dropping it"
    $COMPOSE run --rm --no-deps scheduler php artisan backup:restore ${1:+"$1"} --into="$INTO" --create --drop-after
fi
