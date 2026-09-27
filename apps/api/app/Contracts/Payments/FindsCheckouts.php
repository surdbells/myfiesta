<?php

namespace App\Contracts\Payments;

/**
 * A processor that can say which checkout a payment was made on.
 *
 * Stripe's refund and dispute notices name the payment (pi_…), and an order
 * only learns its payment's name from the notice that says it was paid. When
 * that notice is late — a delivery that failed, and a retry an hour off — a
 * refund notice arriving first names a payment nothing here has heard of.
 * Asking which checkout it came from is how the order is found, so the notice
 * can be put off until the payment has landed instead of being dropped
 * (PaymentWebhookController).
 */
interface FindsCheckouts
{
    /**
     * The processor's id for the checkout the payment was made on, or null
     * when it knows of none. Throws when it cannot be asked.
     */
    public function checkoutFor(string $paymentReference): ?string;
}
