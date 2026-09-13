<?php

namespace App\Services\Checkout;

use App\Exceptions\CheckoutException;
use App\Models\Code;
use App\Models\Event;
use App\Models\TaxRate;
use App\Models\TicketType;
use App\Support\Allocation;
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
    /**
     * The service charge, in basis points.
     *
     * Read from configuration rather than fixed as a constant here. The
     * constant this replaces said 10% while `payments.commission_bps` also
     * said 10% and was read by nothing — two declarations of one rate, one of
     * them dead, which is how a rate drifts from the one the business charges.
     */
    private function serviceChargeBps(): int
    {
        return (int) config('payments.service_charge_bps', 800);
    }

    /**
     * @param  array<string, int>  $quantities  ticket type id => quantity
     */
    public function quote(
        Event $event,
        array $quantities,
        ?string $codeInput = null,
        ?string $refSlug = null,
        ?string $accessInput = null,
    ): Quote {
        // Codes first: whether a locked tier may be priced at all depends on
        // what they unlock.
        $code = $this->resolveCode($event, $codeInput, $refSlug);
        $accessCode = $this->resolveAccess($event, $accessInput, $code);

        $unlocked = $accessCode?->unlocks()->pluck('ticket_types.id')->all() ?? [];

        $lines = $this->priceLines($event, $quantities, $unlocked);

        if ($lines === []) {
            throw new CheckoutException('Select at least one ticket.');
        }

        $currency = $event->currency;

        $subtotal = array_reduce(
            $lines,
            fn (Money $carry, QuoteLine $line) => $carry->plus($line->lineTotal),
            Money::zero($currency),
        );

        $lines = $this->applyDiscount($code, $lines, typed: filled($codeInput));

        $discount = array_reduce(
            $lines,
            fn (Money $carry, QuoteLine $line) => $carry->plus($line->discount),
            Money::zero($currency),
        );

        // Tax on what is actually being paid. Taxing the pre-discount price
        // overcharges the buyer on money nobody receives.
        $afterDiscount = $subtotal->minus($discount);

        $taxRate = TaxRate::resolve($event->country, $event->subdivision);
        $tax = $this->taxFor($taxRate, $afterDiscount);

        // Where the tax sits relative to the price changes what the organizer
        // actually earned, and getting it backwards double-charges.
        //
        //   exclusive (Canada)  — 1000 + 130 tax => ticket side 1130, revenue 1000
        //   inclusive (Nigeria) — 1075 incl. 75  => ticket side 1075, revenue 1000
        //
        // Tax is never the organizer's money in either case.
        if ($taxRate?->inclusive) {
            $ticketSide = $afterDiscount;
            $netRevenue = $afterDiscount->minus($tax);
        } else {
            $ticketSide = $afterDiscount->plus($tax);
            $netRevenue = $afterDiscount;
        }

        // The service charge is added for the buyer, not taken out of the
        // organizer. Somebody selling a 5000 ticket is owed 5000 and the buyer
        // is charged 5400. Reversing that direction is a pay cut to every
        // organizer on the platform, which is what this code did until the
        // live database was read.
        //
        // On the net, so an organizer who discounts 40% is charged on what was
        // collected rather than on the list price.
        $serviceCharge = $netRevenue->percentage($this->serviceChargeBps());

        $total = $ticketSide->plus($serviceCharge);

        return new Quote(
            event: $event,
            lines: $lines,
            subtotal: $subtotal,
            discount: $discount,
            tax: $tax,
            total: $total,
            netRevenue: $netRevenue,
            serviceCharge: $serviceCharge,
            code: $code,
            taxRate: $taxRate,
            refSlug: $code?->ref_slug ?? $refSlug,
            // Recorded only when it opened something in this order. A presale
            // code typed by a buyer who then bought a public ticket used nothing.
            accessCode: $accessCode !== null && array_filter($lines, fn (QuoteLine $l) => $l->ticketType->isLocked()) !== []
                ? $accessCode
                : null,
        );
    }

    /**
     * @param  array<string, int>  $quantities
     * @param  list<string>  $unlocked  ticket type ids an access code has opened
     * @return list<QuoteLine>
     */
    private function priceLines(Event $event, array $quantities, array $unlocked = []): array
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

            if ($type->isLocked() && ! in_array($type->id, $unlocked, true)) {
                // A hidden tier is not admitted to exist without its code.
                if ($type->status === 'hidden') {
                    throw new CheckoutException('That ticket is not on sale for this event.');
                }

                throw new CheckoutException(
                    "{$type->name} goes on sale "
                    .$type->sales_start_at->setTimezone($event->timezone)->format('D j M, g:i a').'.'
                );
            }

            if (! in_array($type->status, ['on_sale', 'hidden'], true)) {
                throw new CheckoutException("{$type->name} is not currently on sale.");
            }

            // Enforced now. The dates were stored and shown in the console and
            // checkout ignored them, so a tier kept selling after its end.
            if ($type->salesEnded()) {
                throw new CheckoutException("Sales for {$type->name} have ended.");
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
                discount: Money::zero($event->currency),
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
            /*
             * Every code with that name, then the one for this event.
             *
             * The console keeps names unique across an organization, but the
             * database only enforces one per event plus one organization-wide,
             * and imported or older rows can share a name. Taking the first
             * match then told a buyer their code was invalid whenever another
             * event's row came back first. An event's own code wins.
             */
            $code = Code::query()
                ->usable()
                ->where('organization_id', $event->organization_id)
                ->whereRaw('upper(code) = ?', [strtoupper(trim($codeInput))])
                ->get()
                ->filter(fn (Code $c) => $c->appliesTo($event))
                ->sortBy(fn (Code $c) => $c->event_id === null ? 1 : 0)
                ->first();

            if ($code === null) {
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

    /**
     * The code opening locked tiers for this basket, if any.
     *
     * One typed as an access code, or the discount code when it also unlocks
     * — a promoter's presale code is often both, and asking for it twice would
     * be silly. A typed access code that does not work is an error the buyer
     * can act on.
     */
    public function resolveAccess(Event $event, ?string $accessInput, ?Code $code = null): ?Code
    {
        if (filled($accessInput)) {
            $access = Code::query()
                ->usable()
                ->where('organization_id', $event->organization_id)
                ->where('unlocks_tickets', true)
                ->whereRaw('upper(code) = ?', [strtoupper(trim($accessInput))])
                ->get()
                ->filter(fn (Code $c) => $c->appliesTo($event))
                ->sortBy(fn (Code $c) => $c->event_id === null ? 1 : 0)
                ->first();

            if ($access === null) {
                throw new CheckoutException('That access code is not valid for this event.');
            }

            return $access;
        }

        return $code?->unlocks_tickets ? $code : null;
    }

    /**
     * Work out the discount and give each line its share.
     *
     * Only the ticket types the code names are discounted, and only once the
     * order holds enough of them. A buyer who typed the code is told why it
     * did nothing; one who arrived on a promoter's link is not — the link
     * still credits the promoter, and refusing a sale over a condition the
     * buyer never saw would be absurd.
     *
     * @param  list<QuoteLine>  $lines
     * @return list<QuoteLine>
     */
    private function applyDiscount(?Code $code, array $lines, bool $typed): array
    {
        if ($code === null || ! $code->discounts()) {
            return $lines;
        }

        $restricted = $code->ticketTypes()->pluck('ticket_types.id')->all();

        $eligible = array_keys(array_filter(
            $lines,
            fn (QuoteLine $line) => $restricted === [] || in_array($line->ticketType->id, $restricted, true),
        ));

        if ($eligible === []) {
            if (! $typed) {
                return $lines;
            }

            $names = $code->ticketTypes()->orderBy('sort_order')->pluck('name')->all();

            throw new CheckoutException(
                "{$code->code} only applies to ".$this->listOf($names).'. Add one of those to use it.'
            );
        }

        $count = array_sum(array_map(fn (int $i) => $lines[$i]->quantity, $eligible));

        if ($code->min_quantity !== null && $count < $code->min_quantity) {
            if (! $typed) {
                return $lines;
            }

            throw new CheckoutException(
                "{$code->code} needs at least {$code->min_quantity} "
                .($restricted === [] ? 'tickets' : 'eligible tickets')
                ." in the order — this one has {$count}."
            );
        }

        $currency = $lines[0]->lineTotal->currency;

        $eligibleTotal = array_sum(array_map(fn (int $i) => $lines[$i]->lineTotal->amount, $eligible));

        $amount = match ($code->discount_type) {
            'percentage' => (new Money($eligibleTotal, $currency))->percentage($code->discount_value)->amount,
            'fixed' => $code->discount_value,
            default => 0,
        };

        // A fixed code larger than what it applies to must not produce a
        // negative total and hand money back.
        $amount = min($amount, $eligibleTotal);

        // Split by value across the lines it applies to, so the parts add back
        // up to the discount exactly.
        $parts = Allocation::split($amount, array_map(fn (int $i) => $lines[$i]->lineTotal->amount, $eligible));

        foreach ($eligible as $position => $index) {
            $lines[$index] = $lines[$index]->withDiscount(new Money($parts[$position], $currency));
        }

        return $lines;
    }

    /** @param  list<string>  $names */
    private function listOf(array $names): string
    {
        if (count($names) <= 1) {
            return $names[0] ?? 'certain tickets';
        }

        return implode(', ', array_slice($names, 0, -1)).' or '.end($names);
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
