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

    /*
     * Looking at events before they go on sale (EventReviews).
     */
    'review' => [
        /*
         * Who is told a new event is waiting. Addresses, comma-separated,
         * when a team inbox should get it; otherwise every member of staff
         * holding one of the roles below.
         */
        'notify' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('EVENT_REVIEW_NOTIFY', '')),
        ))),

        'notify_roles' => ['admin', 'support'],

        /*
         * How long organizers are told a review usually takes, in the email
         * that says it arrived. Words, because it is read by a person.
         */
        'typical_wait' => env('EVENT_REVIEW_TYPICAL_WAIT', 'one working day'),
    ],
];
