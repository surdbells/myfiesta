<?php

namespace App\Contracts\Payments;

use App\Models\Order;

/**
 * The boundary between the domain and a payment processor.
 *
 * Defined before either implementation, deliberately. Cashier is excellent and
 * it is also Stripe-shaped: build against it first and Stripe's vocabulary ends
 * up in the domain, at which point adding Paystack means either bending Paystack
 * into Stripe's model or writing a second, parallel checkout. Neither is a thing
 * to discover in week six.
 *
 * So the domain speaks in orders and outcomes, and each implementation
 * translates. Cashier still does the work inside the Stripe adapter — it is just
 * not visible from outside it.
 *
 * Two rules bind every implementation:
 *
 *   Amounts come from the order, which computed them from prices held in the
 *   database. No implementation accepts an amount from a caller. This is the
 *   rule whose absence let buyers of the previous platform choose what to pay.
 *
 *   A signed webhook is the only thing that may mark an order paid. The
 *   browser's return from checkout is a navigation event, not evidence.
 */
interface PaymentGateway
{
    /**
     * Machine name, as stored on orders.gateway.
     */
    public function name(): string;

    /**
     * Whether this gateway handles a currency.
     *
     * Routing is a property of the gateway rather than a conditional at the
     * call site, so adding a third processor does not mean editing checkout.
     */
    public function supports(string $currency): bool;

    /**
     * Begin a payment for an order.
     *
     * The order already knows its total, currency, and buyer. Implementations
     * must send the idempotency key so a retried request cannot charge twice.
     *
     * Never called for a zero-total order — comps, full-value codes, RSVPs, and
     * free events have nothing to charge and route straight to fulfilment.
     */
    public function createCheckout(Order $order, CheckoutOptions $options): CheckoutSession;

    /**
     * Verify a webhook payload came from the processor.
     *
     * Stripe signs with its own scheme, Paystack with HMAC-SHA512. The
     * mechanics differ; the obligation does not. An implementation that cannot
     * verify a signature must return false rather than assume good faith.
     */
    public function verifySignature(string $payload, array $headers): bool;

    /**
     * Translate a verified webhook into something the domain understands.
     *
     * Returns null for events we do not act on, which is most of them.
     */
    public function parseWebhook(string $payload, array $headers): ?PaymentEvent;

    /**
     * Return money for an order, in part or in full.
     *
     * The resulting ledger entries are the caller's responsibility; this moves
     * the money and reports what happened.
     *
     * The key names one refund on our side. Implementations send it wherever
     * the processor accepts one, so however many times the same refund is
     * sent — a retry after a timeout, a follow-up an hour later — the
     * processor pays it back once. A processor that takes no key is asked
     * whether it already has the refund before it is ever sent twice
     * (FindsRefunds).
     *
     * An answer nobody can trust — a timeout, the processor's own error — is
     * RefundResult::unknown(), never failed(): failed tells the organizer it
     * is safe to try again.
     */
    public function refund(
        Order $order,
        int $amountMinorUnits,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): RefundResult;
}
