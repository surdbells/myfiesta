<?php

namespace App\Services\Checkout;

use App\Exceptions\CheckoutException;
use App\Models\Code;
use App\Models\Event;
use App\Models\TaxRate;
use App\Models\TicketType;
use App\Support\Money;

/**
 * Turns quantities into a price.
 *
 * The entire input from a client is a map of ticket type id to quantity, plus
 * optionally a code and a referrer. Every figure that follows is read from the
 * database. This is the rule whose absence let buyers of the previous platform
 * choose what to pay: its checkout created a Stripe session with
 * `unit_amount => $data->total * 100`, taken straight from the request body.
 */
class Pricer
{
    /** The platform's share of the net, in basis points. 10%. */
    private const COMMISSION_BPS = 1000;

    /**
     * @param  array<string, int>  $quantities  ticket type id => quantity
     */
    public function quote(
        Event $event,
        array $quantities,
        ?string $codeInput = null,
        ?string $refSlug = null,
    ): Quote {
        $lines = $this->priceLines($event, $quantities);

        if ($lines === []) {
            throw new CheckoutException('Select at least one ticket.');
        }

        $currency = $event->currency;

        $subtotal = array_reduce(
            $lines,
            fn (Money $carry, QuoteLine $line) => $carry->plus($line->lineTotal),
            Money::zero($currency),
        );

        $code = $this->resolveCode($event, $codeInput, $refSlug);
        $discount = $this->discountFor($code, $subtotal);

        // Tax on what is actually being paid. Taxing the pre-discount price
        // overcharges the buyer on money nobody receives.
        $afterDiscount = $subtotal->minus($discount);

        $taxRate = TaxRate::resolve($event->country, $event->subdivision);
        $tax = $this->taxFor($taxRate, $afterDiscount);

        // Where the tax sits relative to the price changes both the total and
        // the commission base, and getting it backwards double-charges.
        //
        //   exclusive (Canada) — 1000 + 130 tax  => charge 1130, revenue 1000
        //   inclusive (Nigeria) — 1075 incl. 75  => charge 1075, revenue 1000
        //
        // Tax is never the organizer's money in either case, so commission is
        // taken on revenue net of it.
        if ($taxRate?->inclusive) {
            $total = $afterDiscount;
            $netRevenue = $afterDiscount->minus($tax);
        } else {
            $total = $afterDiscount->plus($tax);
            $netRevenue = $afterDiscount;
        }

        // Commission on the net. If an organizer discounts 40%, the platform
        // takes its cut of what was collected, not of the list price.
        $commission = $netRevenue->percentage(self::COMMISSION_BPS);

        return new Quote(
            event: $event,
            lines: $lines,
            subtotal: $subtotal,
            discount: $discount,
            tax: $tax,
            total: $total,
            commission: $commission,
            code: $code,
            taxRate: $taxRate,
            refSlug: $code?->ref_slug ?? $refSlug,
        );
    }

    /**
     * @param  array<string, int>  $quantities
     * @return list<QuoteLine>
     */
    private function priceLines(Event $event, array $quantities): array
    {
        $lines = [];

        foreach ($quantities as $ticketTypeId => $quantity) {
            $quantity = (int) $quantity;

            if ($quantity <= 0) {
                continue;
            }

            /** @var TicketType|null $type */
            $type = $event->ticketTypes()->whereKey($ticketTypeId)->first();

            // Belonging to this event is checked by the query, not by trusting
            // the id. Otherwise a cheap ticket type from another event could be
            // bought against this one.
            if ($type === null) {
                throw new CheckoutException('That ticket is not on sale for this event.');
            }

            if ($type->status !== 'on_sale') {
                throw new CheckoutException("{$type->name} is not currently on sale.");
            }

            if ($type->max_per_order !== null && $quantity > $type->max_per_order) {
                throw new CheckoutException(
                    "You can buy at most {$type->max_per_order} of {$type->name} in one order."
                );
            }

            $unit = new Money($type->price_amount, $event->currency);

            $lines[] = new QuoteLine(
                ticketType: $type,
                quantity: $quantity,
                unitPrice: $unit,
                lineTotal: $unit->times($quantity),
            );
        }

        return $lines;
    }

    /**
     * Find a usable code, by typed value or by the referrer on a shared link.
     *
     * A code the buyer typed that does not work is an error they can act on. A
     * ref slug that does not resolve is silently ignored — the link may be old,
     * and refusing the sale over a stale tracking parameter would be absurd.
     */
    private function resolveCode(Event $event, ?string $codeInput, ?string $refSlug): ?Code
    {
        if (filled($codeInput)) {
            $code = Code::query()
                ->usable()
                ->where('organization_id', $event->organization_id)
                ->whereRaw('upper(code) = ?', [strtoupper(trim($codeInput))])
                ->first();

            if ($code === null || ! $code->appliesTo($event)) {
                throw new CheckoutException('That code is not valid for this event.');
            }

            return $code;
        }

        if (filled($refSlug)) {
            return Code::query()
                ->usable()
                ->where('organization_id', $event->organization_id)
                ->where('ref_slug', $refSlug)
                ->get()
                ->first(fn (Code $c) => $c->appliesTo($event));
        }

        return null;
    }

    private function discountFor(?Code $code, Money $subtotal): Money
    {
        if ($code === null || ! $code->discounts()) {
            return Money::zero($subtotal->currency);
        }

        $discount = match ($code->discount_type) {
            'percentage' => $subtotal->percentage($code->discount_value),
            'fixed' => new Money($code->discount_value, $code->discount_currency),
            default => Money::zero($subtotal->currency),
        };

        // A fixed code larger than the order must not produce a negative total
        // and hand money back.
        return $discount->amount > $subtotal->amount ? $subtotal : $discount;
    }

    private function taxFor(?TaxRate $rate, Money $taxable): Money
    {
        if ($rate === null) {
            // No rate for the jurisdiction means none is charged. Inventing one
            // would be worse than charging nothing.
            return Money::zero($taxable->currency);
        }

        if ($rate->inclusive) {
            // The displayed price already contains the tax, so it is extracted
            // rather than added: at 7.5%, the tax inside 1075 is 75, not 80.
            $divisor = 10000 + $rate->rate_bps;

            return new Money(
                (int) round($taxable->amount * $rate->rate_bps / $divisor),
                $taxable->currency,
            );
        }

        return $taxable->percentage($rate->rate_bps);
    }
}
