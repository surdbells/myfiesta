<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Who runs this, and how to reach them
    |--------------------------------------------------------------------------
    |
    | The operator's own details, shown on the public site's contact, terms,
    | privacy and refund pages (GET /api/contact).
    |
    | Configuration rather than copy, because none of it is ours to write:
    | the registered name, the number it is registered under and the address
    | post reaches belong to whoever operates the platform, and differ between
    | staging and production. CASL and the NDPA both require a reachable
    | operator; Stripe and Paystack both ask to see these pages before an
    | account goes live. Until they are filled in, the pages say so rather
    | than inventing something.
    |
    */

    'contact' => [
        // The registered legal name, e.g. "Example Events Inc." — not the
        // brand, which is already on every page.
        'company_name' => env('CONTACT_COMPANY_NAME'),

        // Whatever it is registered under: a Canadian corporation number, a
        // CAC RC number. Shown as written.
        'company_number' => env('CONTACT_COMPANY_NUMBER'),

        // The inbox a person reads. The same one security emails ask people to
        // reply to, unless there is reason for two.
        //
        // `?:` rather than env()'s default throughout: an empty line in .env
        // reads as "", which is set, and would stop the fallback from ever
        // being reached.
        'support_email' => env('CONTACT_SUPPORT_EMAIL') ?: env('MAIL_SUPPORT_ADDRESS'),

        // Requests about personal data. Falls back to support: one inbox
        // answered is better than two addresses and one read.
        'privacy_email' => env('CONTACT_PRIVACY_EMAIL') ?: env('CONTACT_SUPPORT_EMAIL') ?: env('MAIL_SUPPORT_ADDRESS'),

        // Optional. Left empty, no number is shown rather than a dead one.
        'phone' => env('CONTACT_PHONE'),

        // Where post reaches the operator, per market, one line each. At least
        // one is required for the pages to stop saying they are incomplete.
        'addresses' => [
            'CA' => env('CONTACT_ADDRESS_CA'),
            'NG' => env('CONTACT_ADDRESS_NG'),
        ],
    ],

];
