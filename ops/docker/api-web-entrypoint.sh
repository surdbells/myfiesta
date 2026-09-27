#!/bin/sh
#
# Tell the API's nginx which addresses may speak for the client.
#
# TRUSTED_PROXIES is the load balancer: the addresses (or ranges) this
# container sees it connect from. Only those may say, in X-Forwarded-For and
# X-Forwarded-Proto, who the client is and whether it was https. Laravel reads
# the same variable (config/trustedproxy.php), so the two cannot disagree.
#
# Written into two files api.nginx.conf includes. Refusing to start is the
# point: without a list every visitor looks like the load balancer and shares
# one rate limit, and with a wildcard every visitor can claim to be anybody,
# which is how the limit on guessing passwords used to be dodged.
set -eu

GEO=/etc/nginx/trusted-proxies.geo
REALIP=/etc/nginx/trusted-proxies.realip

if [ -z "${TRUSTED_PROXIES:-}" ]; then
    echo "api-web: TRUSTED_PROXIES is not set — refusing to start. Name the addresses the load balancer connects from." >&2
    exit 1
fi

: > "$GEO"
: > "$REALIP"

for proxy in $(echo "$TRUSTED_PROXIES" | tr ',' ' '); do
    case "$proxy" in
        '*' | '**' | 0.0.0.0/0 | ::/0)
            echo "api-web: TRUSTED_PROXIES trusts every address ($proxy) — refusing to start. Name the load balancer's addresses instead." >&2
            exit 1
            ;;
    esac

    # Written into nginx's configuration, so nothing but an address or a range.
    if ! echo "$proxy" | grep -Eq '^[0-9A-Fa-f.:]+(/[0-9]{1,3})?$'; then
        echo "api-web: TRUSTED_PROXIES has \"$proxy\", which is not an address or a range — refusing to start." >&2
        exit 1
    fi

    echo "set_real_ip_from $proxy;" >> "$REALIP"
    echo "$proxy 1;" >> "$GEO"
done

# Commas and nothing else is the same as not set.
if [ ! -s "$REALIP" ]; then
    echo "api-web: TRUSTED_PROXIES names no address — refusing to start." >&2
    exit 1
fi

echo "api-web: trusting forwarded headers from $TRUSTED_PROXIES"
