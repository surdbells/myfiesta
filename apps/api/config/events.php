<?php

return [
    /*
     * What an event can be filed under.
     *
     * A fixed list rather than free text. Typed categories split one kind of
     * night into "Nightlife", "nightlife", "Night life" and "Clubbing", and
     * every one of those became its own filter on the events page with one
     * event in it. The console offers these in a dropdown; the API accepts
     * only these.
     *
     * Covers what the previous platform's event_types held (see
     * LegacyRules::category for how its ids map here), with the two it lacked
     * that organizers here run constantly: festivals and networking.
     */
    'categories' => [
        'Nightlife',
        'Party',
        'Music',
        'Concert',
        'Festival',
        'Comedy',
        'Performing arts',
        'Food & drink',
        'Community & culture',
        'Classes & workshops',
        'Networking & business',
        'Sports',
        'Online',
        'Other',
    ],
];
