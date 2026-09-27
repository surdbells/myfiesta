<?php

/**
 * Who may tell this application where a request came from.
 *
 * X-Forwarded-For and X-Forwarded-Proto are headers, and anybody can send a
 * header. Believed from anyone, they let a client choose its own address —
 * which is every per-address limit here, the one on guessing passwords
 * included, undone by a line of curl — and let plain http pass for https.
 * So they are believed only from the addresses named here: whatever
 * terminates TLS in front of this application, and nothing else.
 *
 * TRUSTED_PROXIES is a comma-separated list of addresses or CIDR ranges, the
 * same list the API's nginx reads (ops/docker/api.nginx.conf). There is no
 * wildcard: `*` is how the forwarded headers came to be believed from
 * everybody, and app:preflight refuses it in production.
 *
 * Unset is an empty list, not null, on purpose. Laravel treats a null list as
 * "decide for me" and trusts every caller whose Host header ends in
 * .on-forge.com — a header the caller writes.
 */
return [
    'proxies' => array_values(array_filter(
        array_map(trim(...), explode(',', (string) env('TRUSTED_PROXIES', ''))),
        fn (string $proxy) => $proxy !== '',
    )),
];
