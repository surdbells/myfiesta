<?php

return [

    /*
    |--------------------------------------------------------------------------
    | When a ticket reads as nearly gone
    |--------------------------------------------------------------------------
    |
    | The defaults behind the "Almost sold out" and "Only 4 left" badges on the
    | site, the phone app and the ticket page. Staff change them in the admin
    | (Platform settings); these are what applies until they do.
    |
    | A tier is almost sold out once something has sold and what is left is at
    | or under the larger of `almost_floor` places and `almost_percent` of its
    | capacity: 5 of 40, 10 of 100, 50 of 500. The floor keeps a small room
    | from reading as nearly full after one sale; the percentage keeps a
    | stadium from reading as plenty with 400 left.
    |
    | `exact_under` is the most places ever named exactly: "Only 4 left" at
    | 10 and under, a badge and no number above it. Nothing larger leaves the
    | API, so a rival cannot read an organizer's sales off the page. Zero
    | never names a number.
    |
    */

    'availability' => [
        'almost_percent' => (int) env('AVAILABILITY_ALMOST_PERCENT', 10),
        'almost_floor' => (int) env('AVAILABILITY_ALMOST_FLOOR', 5),
        'exact_under' => (int) env('AVAILABILITY_EXACT_UNDER', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | How long a front page is kept
    |--------------------------------------------------------------------------
    |
    | Seconds. The home page's sections are a dozen queries, asked by every
    | stranger who arrives, and none of them changes meaningfully in a minute.
    | A badge may lag a sale by this long on a list; the event page and the
    | ticket page are never cached, and checkout counts again under a lock.
    |
    */

    'cache_seconds' => (int) env('DISCOVERY_CACHE_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Demo events, for looking at the site on a laptop
    |--------------------------------------------------------------------------
    |
    | Whether `php artisan db:seed` also runs DemoEventsSeeder. Off unless a
    | developer turns it on, and the seeder refuses to run in production
    | whatever this says: it invents organizers, sales and posters.
    |
    */

    'seed_demo_events' => (bool) env('SEED_DEMO_EVENTS', false),

];
