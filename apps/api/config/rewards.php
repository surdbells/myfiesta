<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fiesta Points
    |--------------------------------------------------------------------------
    |
    | Earned by the holder of a ticket bought at checkout when the door lets
    | them in, once for each night, and spent on perks an organizer offers.
    | Never cash, never a discount on a price.
    |
    | `per_event` is what one night earns. `daily_cap` is the most nights that
    | earn in one day, so a person scanned into a string of free parties does
    | not farm them. Staff change both in the admin (Platform settings); these
    | are what applies until they do. Read them through PlatformSettings
    | (pointsPerEvent, pointsDailyCap), never from here, or a change staff
    | made is silently ignored.
    |
    */

    'points' => [
        'per_event' => (int) env('POINTS_PER_EVENT', 100),
        'daily_cap' => (int) env('POINTS_DAILY_CAP', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Friend discounts
    |--------------------------------------------------------------------------
    |
    | A buyer's link that takes a share off a friend's tickets and earns the
    | buyer the same off their next ones. The organizer chooses the share and
    | pays for both; this is the most any organizer may offer, in basis points
    | (2000 is 20%), so a friend discount never becomes a way to give tickets
    | away. Staff change it in the admin; read it through PlatformSettings
    | (shareMaxBps).
    |
    */

    'share' => [
        'max_bps' => (int) env('SHARE_MAX_BPS', 2000),
    ],

];
