<?php

return [
    /*
     * Which gateway handles which currency is decided by each adapter's
     * supports() method, not here — adding a processor should not mean editing
     * checkout. These are only credentials.
     */
    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
    ],

    /*
     * The platform's share of net revenue, in basis points.
     *
     * Taken on revenue after any organizer discount and net of tax, because
     * neither the discount nor the tax is money the platform earned.
     */
    'commission_bps' => (int) env('PLATFORM_COMMISSION_BPS', 1000),
];
