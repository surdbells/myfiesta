<?php

namespace App\Services\Checkout;

use App\Exceptions\CheckoutException;
use App\Models\AddOn;
use App\Models\Code;
use App\Models\Event;
use App\Models\TaxRate;
use App\Models\TicketType;
use App\Services\Settings\PlatformSettings;
use App\Services\Settings\SellerOfRecord;
use App\Services\Sharing\ShareLinks;
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
    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly ShareLinks $shareLinks,
    ) {}

    /**
     * The service charge, in basis points, for a sale in this currency.
     *
     * Staff set it per currency in the admin; until they do, it is
     * `payments.service_charge_bps`. Never a constant here. The constant this
     * replaced said 10% while `payments.commission_bps` also said 10% and was
     * read by nothing — two declarations of one rate, one of them dead, which
     * is how a rate drifts from the one the business charges.
     */
    private function serviceChargeBps(string $currency): int
    {
        return $this->settings->serviceChargeBps($currency);
    }

    /**
     * @param  array<string, int>  $quantities  ticket type id => quantity
     * @param  array<string, int>  $addOns  add-on id => quantity
     * @param  string  $channel  online, or door for a walk-up paid on the spot
     */
    public function quote(
        Event $event,
        array $quantities,
        ?string $codeInput = null,
        ?string $refSlug = null,
        ?string $accessInput = null,
        array $addOns = [],
        string $channel = 'online',
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

        // After the tickets, always. An add-on is bought with a ticket and
        // not instead of one — a bottle on its own is a bar tab, and this is
        // not a bar.
        $lines = [...$lines, ...$this->priceAddOns($event, $addOns)];

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
        $qstPpm = $this->qstFor($event, $taxRate);

        $ticketTaxes = $this->ticketTaxes($taxRate, $qstPpm, $afterDiscount);
        $tax = TaxLine::sum($ticketTaxes, $currency);

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
        /*
         * Nothing on a door sale.
         *
         * The service charge is the buyer paying the platform for the
         * checkout, and at a door there was no checkout — the money went
         * from a hand into a tin and we never touched it. Charging for it
         * would mean invoicing an organizer for cash we cannot see, which is
         * a worse business than not charging.
         */
        $serviceChargeBps = $channel === 'door' ? 0 : $this->serviceChargeBps($currency);
        $charge = $netRevenue->percentage($serviceChargeBps);

        /*
         * Tax on the service charge, when it is taxed, at the event's own
         * rate: HST in Ontario, GST — and QST, when collected — in Quebec,
         * VAT in Nigeria.
         *
         * Where prices include tax, the service charge does too: the buyer in
         * Lagos is charged the same figure either way, and the VAT is the part
         * of it that is not the platform's. Where tax is added, it is added.
         * Either way it stays inside the service charge and out of `tax` — see
         * Quote for why that matters to the organizer's ledger.
         */
        $chargeTaxes = $charge->amount > 0 && $this->settings->serviceChargeTaxed()
            ? $this->serviceChargeTaxes($taxRate, $qstPpm, $charge)
            : [];
        $chargeTax = TaxLine::sum($chargeTaxes, $currency);

        $serviceCharge = $taxRate?->inclusive ? $charge : $charge->plus($chargeTax);

        $total = $ticketSide->plus($serviceCharge);

        $seller = $this->settings->sellerOfRecord();

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
            accessCode: $accessCode !== null && array_filter($lines, fn (QuoteLine $l) => $l->isTicket() && $l->ticketType->isLocked()) !== []
                ? $accessCode
                : null,
            serviceChargeTax: $chargeTax,
            taxLines: [...$ticketTaxes, ...$chargeTaxes],
            sellerOfRecord: $seller,
            snapshot: $this->snapshot($event, $seller, $serviceChargeBps, $qstPpm, $chargeTaxes !== []),
        );
    }

    /**
     * Quebec Sales Tax, in millionths, when this sale owes it.
     *
     * An event in Quebec, while the platform collects QST. Charged beside
     * GST, on the same price before either — QST has not been charged on top
     * of GST since 2013. Not where a rate is already inside the price: QST is
     * added at checkout, and a total that both contains one tax and adds
     * another would not reconcile with the order it becomes.
     */
    private function qstFor(Event $event, ?TaxRate $rate): ?int
    {
        if ($event->country !== 'CA' || strtoupper((string) $event->subdivision) !== 'QC' || $rate?->inclusive) {
            return null;
        }

        return $this->settings->qstPpm();
    }

    /**
     * Each tax on the tickets and extras: the jurisdiction's rate, then QST.
     *
     * @return list<TaxLine>
     */
    private function ticketTaxes(?TaxRate $rate, ?int $qstPpm, Money $taxable): array
    {
        return $this->taxesOn(TaxLine::ON_TICKETS, $rate, $qstPpm, $taxable);
    }

    /** @return list<TaxLine> */
    private function serviceChargeTaxes(?TaxRate $rate, ?int $qstPpm, Money $charge): array
    {
        return $this->taxesOn(TaxLine::ON_SERVICE_CHARGE, $rate, $qstPpm, $charge);
    }

    /**
     * The taxes on one amount.
     *
     * No rate for the jurisdiction means none is charged. Inventing one would
     * be worse than charging nothing.
     *
     * @return list<TaxLine>
     */
    private function taxesOn(string $on, ?TaxRate $rate, ?int $qstPpm, Money $base): array
    {
        $lines = [];

        if ($rate !== null) {
            $lines[] = TaxLine::charge($rate->name, $rate->rate_bps * 100, $on, $base, (bool) $rate->inclusive, $rate->id);
        }

        if ($qstPpm !== null && $qstPpm > 0) {
            $lines[] = TaxLine::charge('QST', $qstPpm, $on, $base, false);
        }

        return $lines;
    }

    /**
     * What a receipt for this sale needs to say, as things stand now.
     *
     * Kept on the order so it never has to be worked out again from settings
     * that may have moved on: who sold it, under which numbers, and what the
     * rates and switches were.
     *
     * The platform's registration numbers are printed where the platform
     * charged a tax: on everything where it is the seller, and on the tax on
     * its service charge where the organizer is. The organizer's own numbers
     * are not held anywhere, so none are printed against the ticket's tax.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Event $event, SellerOfRecord $seller, int $serviceChargeBps, ?int $qstPpm, bool $chargeTaxed): array
    {
        $platformCharged = $seller === SellerOfRecord::Platform || $chargeTaxed;

        return [
            'seller_of_record' => $seller->value,
            'service_charge_bps' => $serviceChargeBps,
            'service_charge_taxed' => $chargeTaxed,
            'qst_ppm' => $qstPpm,
            'organizer' => ['name' => $event->organization?->name],
            'platform' => [
                'name' => $this->settings->legalName() ?? config('app.name'),
                'address' => $this->settings->address($event->country),
                'registrations' => $platformCharged
                    ? $this->settings->registrations($event->country, $event->subdivision)
                    : [],
            ],
        ];
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

                if ($type->isWaiting()) {
                    throw new CheckoutException("{$type->name} opens when {$type->opensAfter->name} sells out.");
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
     * The things on the order that are not tickets.
     *
     * Priced from the database like everything else, and checked against this
     * event by the query rather than by trusting an id — otherwise a cheap
     * add-on from another event could be bought against this one.
     *
     * Stock is not checked here. Quoting takes no locks and reserves nothing,
     * so a count read now would be out of date by the time anybody paid; the
     * hold at reserve time is what decides, and it is the only thing that can.
     *
     * @param  array<string, int>  $quantities  add-on id => quantity
     * @return list<QuoteLine>
     */
    private function priceAddOns(Event $event, array $quantities): array
    {
        $lines = [];

        foreach ($quantities as $addOnId => $quantity) {
            $quantity = (int) $quantity;

            if ($quantity <= 0) {
                continue;
            }

            /** @var AddOn|null $addOn */
            $addOn = $event->addOns()->whereKey($addOnId)->first();

            if ($addOn === null) {
                throw new CheckoutException('That extra is not available for this event.');
            }

            if ($addOn->status !== 'on_sale') {
                throw new CheckoutException("{$addOn->name} is not currently available.");
            }

            if ($addOn->max_per_order !== null && $quantity > $addOn->max_per_order) {
                throw new CheckoutException(
                    "You can add at most {$addOn->max_per_order} of {$addOn->name} to one order."
                );
            }

            $unit = new Money($addOn->price_amount, $event->currency);

            $lines[] = new QuoteLine(
                ticketType: null,
                quantity: $quantity,
                unitPrice: $unit,
                lineTotal: $unit->times($quantity),
                discount: Money::zero($event->currency),
                addOn: $addOn,
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
     *
     * In this order, one code to an order:
     *   1. a code the buyer typed — they chose it, so it wins, even over a
     *      friend's link they arrived on;
     *   2. a friend's link to this night, while it has an offer: the night's
     *      hidden friend-discount code (ShareOffers), which nobody can type;
     *   3. a promoter's link, as it always was.
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
                ->typeable()
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

        if (filled($refSlug) && ($friend = $this->friendCode($event, $refSlug)) !== null) {
            return $friend;
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
     * The night's friend-discount code, when a ref is a friend's link to it.
     *
     * Null when the ref is not one, names another night's link, or the night
     * has since ended its offer — and the ref is then tried as a promoter's.
     */
    private function friendCode(Event $event, string $refSlug): ?Code
    {
        if ($this->shareLinks->live($event, $refSlug) === null) {
            return null;
        }

        $code = Code::query()
            ->usable()
            ->where('event_id', $event->id)
            ->where('purpose', Code::SHARE_FRIEND)
            ->get()
            ->first(fn (Code $c) => $c->appliesTo($event));

        // Held to the platform's largest friend discount, which staff may
        // have lowered under this offer since it was set. On this copy only,
        // for this quote: nothing saves a quote's code, and the organizer's
        // own figure stays theirs to see and change.
        $bps = $this->shareLinks->bpsFor($event);

        if ($code !== null && $bps !== null && (int) $code->discount_value > $bps) {
            $code->discount_value = $bps;
        }

        return $code;
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

        // Tickets only. A code is something an organizer puts on their
        // tickets — "20% off" said about a night, not about the bottle
        // somebody added to the table. A fixed-value code larger than the
        // tickets it applies to would otherwise spill onto the bar.
        $eligible = array_keys(array_filter(
            $lines,
            fn (QuoteLine $line) => $line->isTicket()
                && ($restricted === [] || in_array($line->ticketType->id, $restricted, true)),
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
}
