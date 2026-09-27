<?php

/*
|--------------------------------------------------------------------------
| Tax and receipts: the defaults
|--------------------------------------------------------------------------
|
| Each of these can be changed by an administrator in the admin, under
| Configuration → Platform settings, and a change made there wins. These are
| what applies until somebody does — on a fresh install, in a test, or after
| an override is cleared.
|
| The defaults reproduce what the platform charged before any of this was a
| setting: the organizer sells the ticket, nothing is added for tax on the
| service charge, and Quebec buyers pay 5% GST without QST. Turning any of
| them on changes what the next buyer is charged, and only the next one:
| every order keeps a copy of what was applied to it.
|
| None of the numbers below are ours to make up. Leave a registration number
| empty until the business has one; an empty number is not printed, and a
| wrong one on a receipt is worse than none.
|
*/

return [

    // organizer or platform. See App\Services\Settings\SellerOfRecord for
    // what each means for the receipt and for the service charge.
    'seller_of_record' => env('TAX_SELLER_OF_RECORD', 'organizer'),

    // Whether the service charge is taxed, at the event's own rate, where the
    // organizer is the seller. Where the platform is, it always is.
    'tax_on_service_charge' => (bool) env('TAX_ON_SERVICE_CHARGE', false),

    /*
     * Quebec Sales Tax, charged on top of the 5% GST for events in Quebec.
     *
     * Off until the business is registered with Revenu Québec to collect it:
     * collecting a tax you are not registered for is taking money you cannot
     * pass on. The rate is a percentage with up to three decimals, because
     * QST is 9.975% and basis points cannot say that.
     */
    'qst' => [
        'enabled' => (bool) env('TAX_QST_ENABLED', false),
        'rate' => env('TAX_QST_RATE', '9.975'),
    ],

    // Printed on receipts next to the tax they cover, when set.
    'registration' => [
        // The CRA business number's GST/HST account, e.g. "123456789 RT0001".
        'gst_hst' => env('TAX_GST_HST_NUMBER'),
        // Revenu Québec's QST registration, e.g. "1234567890 TQ0001".
        'qst' => env('TAX_QST_NUMBER'),
        // FIRS: the Taxpayer Identification Number VAT is filed under.
        'ng_vat' => env('TAX_NG_VAT_NUMBER'),
    ],

];
