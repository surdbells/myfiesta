<?php

namespace App\Services\Checkout;

use App\Exceptions\CheckoutException;
use App\Models\Code;
use App\Models\Event;
use App\Models\InventoryHold;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a quote into a pending order, with the stock to honour it.
 *
 * Everything that must not race happens inside one transaction: counting what
 * is left, taking the hold, incrementing the code's redemption count, and
 * writing the order. The previous platform did none of this — it stored
 * `ticket_available` and `max_ticket_per_person` and consulted neither, so
 * concurrent buyers oversold and a capped promo code had no cap at all.
 *
 * Rows are locked with SELECT ... FOR UPDATE rather than checked optimistically.
 * On a popular on-sale, two buyers reaching the last ticket within the same
 * millisecond is not a rare edge case; it is the normal condition.
 */
class CheckoutService
{
    /**
     * How long stock is held while the buyer completes payment.
     *
     * Must outlive the gateway's own session expiry by a margin. Releasing a
     * hold while someone is still on the payment page sells their tickets to
     * somebody else mid-transaction, and they are then charged for nothing.
     */
    private const HOLD_MINUTES = 20;

    public function __construct(private readonly Pricer $pricer) {}

    /**
     * Price a basket without reserving anything.
     *
     * Safe to call on every cart change: it takes no locks and writes nothing.
     *
     * @param  array<string, int>  $quantities
     */
    public function quote(Event $event, array $quantities, ?string $code = null, ?string $ref = null): Quote
    {
        return $this->pricer->quote($event, $quantities, $code, $ref);
    }

    /**
     * Reserve stock and open an order.
     *
     * @param  array<string, int>  $quantities
     */
    public function reserve(
        Event $event,
        array $quantities,
        string $buyerEmail,
        string $buyerName,
        ?string $codeInput = null,
        ?string $refSlug = null,
        ?User $user = null,
        ?string $buyerPhone = null,
    ): Order {
        if ($event->status !== 'published') {
            throw new CheckoutException('Tickets for this event are not on sale.');
        }

        return DB::transaction(function () use (
            $event, $quantities, $buyerEmail, $buyerName, $codeInput, $refSlug, $user, $buyerPhone
        ) {
            // Price inside the transaction so the figures cannot be computed
            // against stock or a code that changes before the hold is taken.
            $quote = $this->pricer->quote($event, $quantities, $codeInput, $refSlug);

            foreach ($quote->lines as $line) {
                $this->takeHold($line->ticketType, $line->quantity);
            }

            if ($quote->code !== null) {
                $this->redeem($quote->code, $buyerEmail);
            }

            $order = Order::create([
                'organization_id' => $event->organization_id,
                'event_id' => $event->id,
                'user_id' => $user?->id,
                'buyer_email' => strtolower(trim($buyerEmail)),
                'buyer_name' => trim($buyerName),
                'buyer_phone' => $buyerPhone,
                'currency' => $quote->currency(),
                'subtotal_amount' => $quote->subtotal->amount,
                'discount_amount' => $quote->discount->amount,
                'tax_amount' => $quote->tax->amount,
                'tax_inclusive' => (bool) $quote->taxRate?->inclusive,
                'net_revenue_amount' => $quote->netRevenue->amount,
                'service_charge_amount' => $quote->serviceCharge->amount,
                'total_amount' => $quote->total->amount,
                'tax_rate_id' => $quote->taxRate?->id,
                'code_id' => $quote->code?->id,
                'ref_slug' => $quote->refSlug,
                'idempotency_key' => (string) Str::uuid(),
                'status' => 'pending',
            ]);

            foreach ($quote->lines as $line) {
                OrderLine::create([
                    'order_id' => $order->id,
                    'ticket_type_id' => $line->ticketType->id,
                    'ticket_type_name' => $line->ticketType->name,
                    'unit_price_amount' => $line->unitPrice->amount,
                    'quantity' => $line->quantity,
                    'line_total_amount' => $line->lineTotal->amount,
                    'discount_amount' => $line->discount->amount,
                ]);
            }

            return $order->refresh();
        });
    }

    /**
     * Reserve stock for one ticket type, or refuse.
     *
     * Availability counts tickets already issued plus holds that have not
     * expired. A hold that is still live is stock somebody else is in the
     * middle of buying, and treating it as available is how two people end up
     * paying for the same seat.
     */
    private function takeHold(TicketType $type, int $quantity): void
    {
        if ($type->quantity_available === null) {
            // Unlimited. Still recorded, so reporting on demand during an
            // on-sale does not have a hole in it.
            InventoryHold::create([
                'ticket_type_id' => $type->id,
                'quantity' => $quantity,
                'expires_at' => now()->addMinutes(self::HOLD_MINUTES),
            ]);

            return;
        }

        // Lock the row first. Everything after this is serialised per ticket
        // type, which is the narrowest lock that makes the count trustworthy.
        $locked = TicketType::query()
            ->whereKey($type->id)
            ->lockForUpdate()
            ->first();

        $issued = DB::table('tickets')
            ->where('ticket_type_id', $type->id)
            ->whereIn('status', ['valid', 'checked_in'])
            ->count();

        $held = (int) DB::table('inventory_holds')
            ->where('ticket_type_id', $type->id)
            ->where('expires_at', '>', now())
            ->sum('quantity');

        $remaining = $locked->quantity_available - $issued - $held;

        if ($remaining < $quantity) {
            throw CheckoutException::soldOut($locked->name, max(0, $remaining));
        }

        InventoryHold::create([
            'ticket_type_id' => $type->id,
            'quantity' => $quantity,
            'expires_at' => now()->addMinutes(self::HOLD_MINUTES),
        ]);
    }

    /**
     * Check a code still has a use for this buyer.
     *
     * Locked so two checkouts cannot both see the last use, and counted from
     * orders — paid ones plus checkouts still inside their hold — rather than a
     * running total. The total this replaces was incremented here and never
     * given back, so every abandoned checkout spent a use for good.
     *
     * The per-buyer limit was accepted by the console and stored, and nothing
     * read it. It is by email address: a buyer with two addresses gets two
     * goes, which is the most a checkout without accounts can promise.
     */
    private function redeem(Code $code, string $buyerEmail): void
    {
        $locked = Code::query()->whereKey($code->id)->lockForUpdate()->first();

        if ($locked->max_redemptions !== null
            && $locked->usesInFlight(self::HOLD_MINUTES) >= $locked->max_redemptions) {
            throw new CheckoutException('That code has been fully redeemed.');
        }

        if ($locked->max_per_customer !== null
            && $locked->usesInFlight(self::HOLD_MINUTES, $buyerEmail) >= $locked->max_per_customer) {
            throw new CheckoutException(
                $locked->max_per_customer === 1
                    ? 'That code can be used once per person, and this email address has already used it.'
                    : "That code can be used {$locked->max_per_customer} times per person, and this email address has used it that many times."
            );
        }
    }

}
