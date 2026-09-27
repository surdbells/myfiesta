<?php

namespace App\Services\Checkout;

use App\Models\Code;
use App\Models\Event;
use App\Models\TaxRate;
use App\Services\Settings\SellerOfRecord;
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
 *                      consideration actually paid, not the face value. In
 *                      Quebec, with QST collected, that is GST and QST side
 *                      by side; `tax` is their sum and `taxLines` has each.
 *   4. netRevenue    — what the organizer earned: the discounted price, net of
 *                      tax whichever side of the price the tax sat on
 *   5. serviceCharge — the platform's, added on top for the buyer. Not
 *                      deducted from netRevenue. Charged on the net, so the
 *                      organizer's discount is their cost and not one we
 *                      share in. Where the service charge is taxed, this is
 *                      what the buyer pays for it tax included, and
 *                      `serviceChargeTax` is how much of it is tax.
 *   6. total         — what the buyer is charged: the ticket side plus the
 *                      service charge
 *
 * The tax on the service charge is kept inside `serviceCharge` rather than
 * added to `tax`, and that is deliberate. `tax` is what the organizer's ledger
 * holds back on the ticket side, and a refund returns tax and service charge
 * in proportion; tax on the platform's own fee is neither the organizer's nor
 * part of the ticket, and folding it into `tax` would take it out of their
 * balance. Inside the service charge it travels with the fee it belongs to.
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
        // The code that opened a locked tier on this order, when one did.
        public ?Code $accessCode = null,
        // The part of serviceCharge that is tax on it. Zero unless taxed.
        public ?Money $serviceChargeTax = null,
        /** @var list<TaxLine> every tax on the order, the tickets' first */
        public array $taxLines = [],
        public ?SellerOfRecord $sellerOfRecord = null,
        /**
         * The settings this was priced under, kept on the order so a receipt
         * printed next year says what was charged and by whom, not what the
         * settings say by then.
         *
         * @var array<string, mixed>
         */
        public array $snapshot = [],
    ) {}

    /**
     * What the tax is called at checkout: "HST", or "GST + QST".
     *
     * The ticket side only. Tax on the service charge is inside the service
     * charge's own figure.
     */
    public function taxLabel(): ?string
    {
        $names = array_map(
            fn (TaxLine $line) => $line->name,
            array_values(array_filter($this->taxLines, fn (TaxLine $line) => $line->on === TaxLine::ON_TICKETS)),
        );

        return $names === [] ? $this->taxRate?->name : implode(' + ', $names);
    }

    /** @return list<array<string, mixed>> as kept on the order */
    public function taxLinesForStorage(): array
    {
        return array_map(fn (TaxLine $line) => $line->toArray(), $this->taxLines);
    }

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
