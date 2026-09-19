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
