#!/usr/bin/env bash
#
# The Flutter app carries three modes in one bundle: attendee, organizer, and
# door. Which one a user gets is decided by their token scope, not by the build.
#
# That makes the separation between them a convention, and conventions erode.
# This check fails the build when one feature imports another, so the modes stay
# independently reasoned about — and so a door-staff build cannot quietly grow a
# dependency on organizer screens.
#
# Shared code belongs in lib/core or lib/design, which every feature may import.
#
# Note: this enforces code structure only. The real access boundary is
# server-side token scope, because the organizer UI is compiled into every
# install regardless of who is signed in.

set -euo pipefail

FEATURES_DIR="apps/mobile/lib/features"
FEATURES=(attendee organizer door)
violations=0

if [ ! -d "$FEATURES_DIR" ]; then
  echo "check-feature-boundaries: $FEATURES_DIR not found, nothing to check"
  exit 0
fi

for feature in "${FEATURES[@]}"; do
  [ -d "$FEATURES_DIR/$feature" ] || continue

  for other in "${FEATURES[@]}"; do
    [ "$feature" = "$other" ] && continue

    # Matches both package: and relative imports of a sibling feature.
    hits=$(grep -rn --include='*.dart' -E \
      "import[[:space:]]+['\"](package:myfiesta/features/${other}/|.*\.\./${other}/)" \
      "$FEATURES_DIR/$feature" 2>/dev/null || true)

    if [ -n "$hits" ]; then
      echo "ERROR: feature '$feature' imports from feature '$other':"
      echo "$hits" | sed 's/^/  /'
      violations=$((violations + 1))
    fi
  done
done

if [ "$violations" -gt 0 ]; then
  echo
  echo "Move anything shared into apps/mobile/lib/core or lib/design."
  exit 1
fi

echo "check-feature-boundaries: no cross-feature imports"
