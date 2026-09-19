<?php

/**
 * Text messages.
 *
 * Worth more in Nigeria than in Canada: email deliverability is weaker there
 * and a phone number is the address people actually read. Deliberately narrow
 * — the ticket itself and the reminder before the doors, both of which
 * somebody asked for by buying — because a marketing text is a different legal
 * animal in both markets and is not what this is for.
 *
 * No credentials here means nothing is sent anywhere: the log driver writes
 * what it would have sent and returns success, so the whole path can be
 * exercised, tested and reviewed before an account exists. Filling in the keys
 * is the only step between that and real messages.
 */
return [

    /*
     * log      writes to the application log and sends nothing
     * termii   Nigeria, where most of the value is
     * twilio   Canada, and everywhere else
     */
    'driver' => env('SMS_DRIVER', 'log'),

    /*
     * Which countries get a text at all.
     *
     * A ticket that costs cents to email and a penny a message to text is
     * only worth texting where the text is the one that gets read. Dialling
     * codes, because that is what a number carries: +234 is Nigeria.
     */
    'countries' => array_filter(explode(',', (string) env('SMS_COUNTRIES', '234'))),

    'termii' => [
        'key' => env('TERMII_API_KEY'),
        // A registered sender id. Termii refuses anything unregistered, which
        // is the usual reason the first real message does not arrive.
        'from' => env('TERMII_SENDER_ID', 'myFiesta'),
        'endpoint' => env('TERMII_ENDPOINT', 'https://api.ng.termii.com/api/sms/send'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_ACCOUNT_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM'),
    ],

    /*
     * The secret a provider's inbound callback is checked against.
     *
     * "STOP" has to work or this is a compliance problem rather than a
     * feature, and the endpoint that receives it is public.
     */
    'inbound_secret' => env('SMS_INBOUND_SECRET'),
];
