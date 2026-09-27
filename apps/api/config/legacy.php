<?php

/*
 * The move from the old platform. See docs/CUTOVER.md.
 *
 * The old database's own connection is in config/database.php, as `legacy`.
 */

return [

    /*
     * The key legacy:reconcile asks Stripe with.
     *
     * Separate from STRIPE_SECRET_KEY so it can be a restricted key that can
     * only read — Checkout Sessions, PaymentIntents, Refunds and Disputes — on
     * the account the old platform charged through. The reconciliation is run
     * against live money on the day of a cutover, and a key that cannot move
     * any is the only guarantee that it will not. Left empty, the platform's
     * own secret key is used.
     *
     * Remove it once the cutover is signed off; nothing else reads it.
     */
    'stripe_key' => env('LEGACY_STRIPE_KEY'),

    /*
     * Stripe requests per second, at most. Stripe's limit is shared with the
     * live checkout on the same account, and this should never be what makes
     * a buyer's payment wait.
     */
    'stripe_rate' => (int) env('LEGACY_STRIPE_RATE', 20),

];
