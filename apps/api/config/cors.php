<?php

return [
    /*
     * The site and the API are separate hosts, so the browser half of the
     * public site calls this API cross-origin and needs to be allowed by name.
     *
     * Named explicitly rather than wildcarded. The platform this replaces sent
     * Access-Control-Allow-Origin: * from all 93 of its endpoints, including
     * the ones returning user records and banking details, so any page on the
     * internet could read them from a logged-in victim's browser.
     */
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:4000,http://127.0.0.1:4000'))
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 3600,

    // Tokens travel in the Authorization header, not a cookie, so the browser
    // never needs to send credentials cross-origin.
    'supports_credentials' => false,
];
