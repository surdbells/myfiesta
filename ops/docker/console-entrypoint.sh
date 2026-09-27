#!/bin/sh
#
# Tell the console where the API and the public site are.
#
# The console is a static build with no server of its own to render addresses
# into the page, so they are stamped into index.html when the container starts.
# One image therefore serves staging and production, which is the whole reason
# neither address is compiled into the bundle.
#
# Without this the tags ship empty, the browser falls back to
# http://127.0.0.1:8000, and every organizer's console quietly calls their own
# laptop for an API that is not there.
set -eu

INDEX=/usr/share/nginx/html/index.html

if [ ! -f "$INDEX" ]; then
    echo "console: no index.html to stamp" >&2
    exit 1
fi

if [ -z "${API_BASE_URL:-}" ]; then
    echo "console: API_BASE_URL is not set — refusing to start rather than serving a console that calls localhost" >&2
    exit 1
fi

if [ -z "${PUBLIC_URL:-}" ]; then
    echo "console: PUBLIC_URL is not set — the links to an organizer's own public pages would go nowhere" >&2
    exit 1
fi

# Matched loosely on purpose: a build may render content="" as a bare
# attribute, and an exact string that quietly matches nothing is how this
# failed on the public site before it was caught by a check.
stamp() {
    name=$1
    value=$2

    sed -i "s|<meta name=\"$name\"[^>]*>|<meta name=\"$name\" content=\"$value\">|" "$INDEX"

    if ! grep -q "<meta name=\"$name\" content=\"$value\">" "$INDEX"; then
        echo "console: could not stamp $name into index.html" >&2
        exit 1
    fi
}

stamp api-base "$API_BASE_URL"
stamp public-base "$PUBLIC_URL"

echo "console: stamped api-base=$API_BASE_URL public-base=$PUBLIC_URL"

# The API's origin, for the Content-Security-Policy in console.nginx.conf:
# scheme, host and port, and nothing after — a source with a path in it
# matches that one path only, and the console calls everything under it.
API_ORIGIN=$(printf '%s' "$API_BASE_URL" | sed -E 's#^(https?://[^/?#]+).*$#\1#')

# Written into nginx's configuration, so nothing but an address.
if ! printf '%s' "$API_ORIGIN" | grep -Eq '^https?://[A-Za-z0-9.-]+(:[0-9]+)?$'; then
    echo "console: API_BASE_URL is not an http(s) address — refusing to start" >&2
    exit 1
fi

printf 'map $host $console_api_origin {\n    default "%s";\n}\n' "$API_ORIGIN" > /etc/nginx/conf.d/00-console-origin.conf

echo "console: the policy allows the API at $API_ORIGIN"

# Where errors are reported (Sentry), if anywhere: optional, and stamped
# either way so a DSN from an earlier container never lingers in the page.
# Empty reports nothing and the console never downloads the reporter
# (src/app/core/error-reporting.ts). A DSN that is not one refuses to start,
# rather than turning reporting off without saying so.
SENTRY_DSN=${SENTRY_DSN:-}
SENTRY_ORIGIN=""

if [ -n "$SENTRY_DSN" ]; then
    if ! printf '%s' "$SENTRY_DSN" | grep -Eq '^https://[A-Za-z0-9]+@[A-Za-z0-9.-]+(:[0-9]+)?/[0-9]+$'; then
        echo "console: SENTRY_DSN is not a Sentry DSN (https://key@host/project) — refusing to start. Leave it empty to report nothing." >&2
        exit 1
    fi

    # Scheme, host and port: never the key in front of the host.
    SENTRY_ORIGIN=$(printf '%s' "$SENTRY_DSN" | sed -E 's#^https://[^@]+@([^/]+)/.*$#https://\1#')
fi

# Labels only; anything but a plain word is dropped rather than written into
# the page.
SENTRY_ENVIRONMENT=$(printf '%s' "${SENTRY_ENVIRONMENT:-production}" | tr -cd 'A-Za-z0-9._-')
SENTRY_RELEASE=$(printf '%s' "${SENTRY_RELEASE:-}" | tr -cd 'A-Za-z0-9._@+-')

stamp sentry-dsn "$SENTRY_DSN"
stamp sentry-environment "$SENTRY_ENVIRONMENT"
stamp sentry-release "$SENTRY_RELEASE"

printf 'map $host $console_sentry_origin {\n    default "%s";\n}\n' "$SENTRY_ORIGIN" > /etc/nginx/conf.d/01-console-sentry.conf

if [ -n "$SENTRY_ORIGIN" ]; then
    echo "console: errors are reported to $SENTRY_ORIGIN (release ${SENTRY_RELEASE:-unset}, $SENTRY_ENVIRONMENT)"
else
    echo "console: SENTRY_DSN is empty, so errors are not reported anywhere"
fi
