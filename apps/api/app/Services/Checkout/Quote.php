<?php

namespace App\Services\Checkout;

use App\Models\Code;
use App\Models\Event;
use App\Models\TaxRate;
use App\Support\Money;

/**
 * What an order will cost, computed entirely server-side.
 *
 * The order of operations is fixed and every component is retained, so a
 * historic order can always be explained rather than merely totalled:
 *
 *   1. subtotal      — quantities times prices held in the database
 *   2. discount      — applied to the subtotal
 *   3. tax           — on the discounted amount, because tax follows the
 *                      consideration actually paid, not the face value
 *   4. netRevenue    — what the organizer earned: the discounted price, net of
 *                      tax whichever side of the price the tax sat on
 *   5. serviceCharge — the platform's revenue, added on top for the buyer. Not
 *                      deducted from netRevenue. Charged on the net, so the
 *                      organizer's discount is their cost and not one we
 *                      share in.
 *   6. total         — what the buyer is charged: the ticket side plus the
 *                      service charge
 *
 * Nothing here reads a price, quantity limit, or tax rate from a request.
 */
final readonly class Quote
{
    public function __construct(
        public Event $event,
        /** @var list<QuoteLine> */
        public array $lines,
        public Money $subtotal,
        public Money $discount,
        public Money $tax,
        public Money $total,
        public Money $netRevenue,
        public Money $serviceCharge,
        public ?Code $code = null,
        public ?TaxRate $taxRate = null,
        public ?string $refSlug = null,
    ) {}

    public function currency(): string
    {
        return $this->event->currency;
    }

    /**
     * A zero total means no gateway is involved at all.
     *
     * Comps, full-value codes, RSVPs, and free events all land here and route
     * straight to fulfilment. Checkout code that assumes a payment session
     * exists breaks the first time an organizer comps someone, which they will
     * do in week one.
     */
    public function requiresPayment(): bool
    {
        return $this->total->amount > 0;
    }

    public function totalQuantity(): int
    {
        return array_sum(array_map(fn (QuoteLine $l) => $l->quantity, $this->lines));
    }
}
