<?php

namespace App\Services\Checkout;

use App\Exceptions\CheckoutException;
use App\Models\AddOn;
use App\Models\Code;
use App\Models\Event;
use App\Models\InventoryHold;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Organizations\Suspension;
use App\Services\Payments\StripeGateway;
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
     * Longer than the payment page stays open, and by a margin. Releasing a
     * hold while somebody is still on the page sells their places to somebody
     * else mid-payment, and they are then charged for nothing. Stripe's page
     * closes at thirty minutes; a payment made in its last second still has to
     * reach us as a webhook, which takes seconds and now and then minutes, so
     * the hold runs ten minutes past it. It was twenty for a long time —
     * shorter than the page it was meant to outlast.
     *
     * Written as the page's lifetime plus the margin, so the two cannot drift
     * apart by somebody changing one of them.
     *
     * Paystack's page never closes, so there is nothing there to outlive. A
     * payment that lands after its hold has gone is Fulfiller's to deal with:
     * it issues the tickets while there is still room, and gives the money
     * back when there is not.
     */
    public const HOLD_MINUTES = StripeGateway::SESSION_MINUTES + 10;

    public function __construct(
        private readonly Pricer $pricer,
        private readonly Answers $answers,
        private readonly Stock $stock,
    ) {}

    /**
     * Price a basket without reserving anything.
     *
     * Safe to call on every cart change: it takes no locks and writes nothing.
     *
     * @param  array<string, int>  $quantities
     */
    public function quote(
        Event $event,
        array $quantities,
        ?string $code = null,
        ?string $ref = null,
        ?string $access = null,
        array $addOns = [],
        string $channel = 'online',
    ): Quote {
        $this->refuseIfOver($event, $channel);

        return $this->pricer->quote($event, $quantities, $code, $ref, $access, $addOns, $channel);
    }

    /**
     * No online sale for a night that is over by its own listing.
     *
     * A payment for one arrived after the night was over and went straight
     * back (Fulfiller), with the processor's fee lost on the way — so every
     * such sale cost the platform money, and anybody could make as many as
     * they liked. The quote says so as well, before anybody types a card
     * number. The door is left alone: it stays open past the listed end, and
     * its cash is already in the tin.
     */
    private function refuseIfOver(Event $event, string $channel): void
    {
        if ($channel !== 'door' && TurnedAway::pastSelling($event, now())) {
            throw new CheckoutException('This event has ended, so tickets for it are no longer on sale.');
        }
    }

    /**
     * Reserve stock and open an order.
     *
     * @param  array<string, int>  $quantities
     */
    public function reserve(
        Event $event,
        array $quantities,
        ?string $buyerEmail,
        string $buyerName,
        ?string $codeInput = null,
        ?string $refSlug = null,
        ?User $user = null,
        ?string $buyerPhone = null,
        ?string $accessInput = null,
        array $answers = [],
        array $attendees = [],
        array $addOns = [],
        /*
         * Where the sale is happening, and how the money arrived.
         *
         * A door sale is an ordinary order in every other respect: same stock,
         * same tickets, same reports. What it carries that an online order
         * does not is how it was paid for and who took it — somebody counts a
         * tin at 3am, and the two numbers have to agree.
         */
        string $channel = 'online',
        ?string $paymentMethod = null,
        ?User $soldBy = null,
        ?string $doorPassId = null,
        /*
         * Where an online order came from: the address the request came from,
         * as the application believes it (TRUSTED_PROXIES), and the browser's
         * name for itself. Kept on the order to catch fraud and to answer a
         * disputed payment, and cleared 18 months after the night
         * (EvidenceRetention). Never for a door sale, whose request is the
         * organizer's phone and says nothing about the person paying.
         */
        ?string $purchaseIp = null,
        ?string $purchaseUserAgent = null,
        /*
         * The reference of this buyer's last attempt, when the payment page
         * could not be opened for it (CheckoutController answers 502 with it).
         *
         * Pressing the button again used to open a second order beside the
         * first, with a second hold on the same places: a buyer retrying
         * through a processor outage held their tickets two, three, four
         * times over, and an almost-sold-out tier read as sold out to
         * everybody else for the length of a hold. Now the attempt that could
         * not be paid is closed, and its stock handed to this one, inside the
         * same transaction — see replace().
         */
        ?string $retryOf = null,
    ): Order {
        // A suspended organization sells nothing, here or at the door. Read
        // from the row now: its events come off sale in the same moment, but
        // a buyer part-way through holds the event as it was a minute ago.
        if (Suspension::inForce($event->organization_id)) {
            throw new CheckoutException('This organizer is not selling tickets on myFiesta at the moment. Tickets already bought are not affected.');
        }

        if ($event->status !== 'published') {
            throw new CheckoutException('Tickets for this event are not on sale.');
        }

        $this->refuseIfOver($event, $channel);

        // Before the holds, deliberately. A refusal here costs the buyer a
        // message and nothing else; a hold taken for an order that was never
        // going to be created is stock nobody else can buy until it runs out.
        $checkedAnswers = $this->answers->check($event, $answers, $attendees, $quantities);

        $online = $channel !== 'door';

        return DB::transaction(function () use (
            $event, $quantities, $buyerEmail, $buyerName, $codeInput, $refSlug, $user, $buyerPhone,
            $accessInput, $checkedAnswers, $addOns, $channel, $paymentMethod, $soldBy, $doorPassId,
            $online, $purchaseIp, $purchaseUserAgent, $retryOf
        ) {
            if ($online && filled($retryOf)) {
                $this->replace($event, $retryOf);
            }

            // Price inside the transaction so the figures cannot be computed
            // against stock or a code that changes before the hold is taken.
            $quote = $this->pricer->quote($event, $quantities, $codeInput, $refSlug, $accessInput, $addOns, $channel);

            $holds = [];

            foreach ($quote->lines as $line) {
                $holds[] = $line->isTicket()
                    ? $this->takeHold($line->ticketType, $line->quantity)
                    : $this->takeAddOnHold($line->addOn, $line->quantity);
            }

            if ($quote->code !== null) {
                $this->redeem($quote->code, $buyerEmail);
            }

            // A presale code has limits too — "the first 200 on the list" — and
            // is checked the same way. Once, when it is also the discount code.
            if ($quote->accessCode !== null && $quote->accessCode->id !== $quote->code?->id) {
                $this->redeem($quote->accessCode, $buyerEmail);
            }

            $order = Order::create([
                'organization_id' => $event->organization_id,
                'event_id' => $event->id,
                'user_id' => $user?->id,
                // Null where nobody gave one, which only a door sale may do:
                // the person paying cash in front of you is not going to
                // spell out an address, and they are scanned in on the spot.
                'buyer_email' => filled($buyerEmail) ? strtolower(trim($buyerEmail)) : null,
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
                // Each tax, and the settings it was priced under, so the
                // receipt never has to ask today's settings about this order.
                'service_charge_tax_amount' => $quote->serviceChargeTax?->amount ?? 0,
                'tax_lines' => $quote->taxLinesForStorage(),
                'seller_of_record' => $quote->sellerOfRecord?->value,
                'pricing_snapshot' => $quote->snapshot,
                'code_id' => $quote->code?->id,
                'access_code_id' => $quote->accessCode?->id,
                'ref_slug' => $quote->refSlug,
                'idempotency_key' => (string) Str::uuid(),
                'status' => 'pending',
                'channel' => $channel,
                'payment_method' => $paymentMethod,
                'sold_by_user_id' => $soldBy?->id,
                'door_pass_id' => $doorPassId,
                'purchase_ip' => $online && filled($purchaseIp) ? substr($purchaseIp, 0, 45) : null,
                'purchase_user_agent' => $online && filled($purchaseUserAgent) ? mb_substr($purchaseUserAgent, 0, 512) : null,
            ]);

            /*
             * The holds are this order's, and say so.
             *
             * Taken before the order existed, because the stock has to be
             * counted before anything is written for it, and claimed here in
             * the same transaction — there is no moment at which a hold
             * belongs to nobody. Payment then gives up exactly these, rather
             * than whichever holds on the same tier happened to be oldest,
             * which is how paying for one order used to release somebody
             * else's while they were still on the payment page.
             */
            InventoryHold::query()
                ->whereKey(array_map(fn (InventoryHold $hold) => $hold->id, $holds))
                ->update(['order_id' => $order->id]);

            foreach ($quote->lines as $line) {
                OrderLine::create([
                    'order_id' => $order->id,
                    // Exactly one of the two, which the database holds as a
                    // check constraint rather than trusting this loop.
                    'ticket_type_id' => $line->ticketType?->id,
                    'add_on_id' => $line->addOn?->id,
                    // Snapshotted: either may be renamed or repriced later,
                    // and an order has to stay explainable afterwards.
                    'name' => $line->name(),
                    'unit_price_amount' => $line->unitPrice->amount,
                    'quantity' => $line->quantity,
                    'line_total_amount' => $line->lineTotal->amount,
                    'discount_amount' => $line->discount->amount,
                ]);
            }

            // Inside the transaction, so an order cannot exist without the
            // answers it was placed with — and a required question cannot be
            // left unanswered by a write that failed on its own.
            $this->answers->store($order, $checkedAnswers);

            return $order->refresh();
        });
    }

    /**
     * Close an attempt whose payment page never opened, and give its stock to
     * the order about to replace it.
     *
     * Only an online order of this event, still pending, never paid, and with
     * no payment session on it: one nobody can pay, because the only way to
     * pay an order is the page its session opens. So nothing is taken from
     * anybody by naming somebody else's reference here, and a reference that
     * is not such an order — paid by now, closed by the sweep, another
     * event's — is left alone and the order is simply placed.
     *
     * In the caller's transaction, before the stock is counted: the places go
     * from the old hold to the new one without ever being free in between, so
     * a retry never loses the buyer the last tickets to somebody else, and
     * nobody else ever sees them held twice. A new order rather than the old
     * one reopened, because what is being retried may have changed — a
     * corrected address, an answer — and because a payment session is opened
     * with the order's own idempotency key, which a processor refuses to
     * reuse with different parameters.
     */
    private function replace(Event $event, string $reference): void
    {
        $attempt = Order::query()
            ->where('reference', strtoupper(trim($reference)))
            ->where('event_id', $event->id)
            ->where('channel', 'online')
            ->where('status', 'pending')
            ->whereNull('gateway_reference')
            ->whereNull('paid_at')
            ->lockForUpdate()
            ->first();

        if ($attempt === null) {
            return;
        }

        InventoryHold::query()->where('order_id', $attempt->id)->delete();

        // Closed the way an abandoned checkout is (AbandonedCheckouts), which
        // the order page already explains as nothing having been charged — and
        // which stops it counting as a use of any code it carried.
        $attempt->update(['status' => 'cancelled']);
    }

    /**
     * Reserve stock for one ticket type, or refuse.
     *
     * Availability counts tickets already issued plus holds that have not
     * expired. A hold that is still live is stock somebody else is in the
     * middle of buying, and treating it as available is how two people end up
     * paying for the same seat.
     *
     * The count locks the ticket type's row, and everything after it is
     * serialised per ticket type — the narrowest lock that makes the count
     * trustworthy. An unlimited tier is not locked and still gets its hold,
     * so reporting on demand during an on-sale does not have a hole in it.
     */
    private function takeHold(TicketType $type, int $quantity): InventoryHold
    {
        $remaining = $this->stock->ticketsLeft($type);

        if ($remaining !== null && $remaining < $quantity) {
            throw CheckoutException::soldOut($type->name, max(0, $remaining));
        }

        return InventoryHold::create([
            'ticket_type_id' => $type->id,
            'quantity' => $quantity,
            'expires_at' => now()->addMinutes(self::HOLD_MINUTES),
        ]);
    }

    /**
     * The same reservation, for the thing that is not a ticket.
     *
     * Counted differently because an add-on mints nothing (Stock says how),
     * but locked and held the same way — the last table goes to one of two
     * people reaching it together, not to both.
     */
    private function takeAddOnHold(AddOn $addOn, int $quantity): InventoryHold
    {
        $remaining = $this->stock->addOnsLeft($addOn);

        if ($remaining !== null && $remaining < $quantity) {
            throw new CheckoutException($remaining <= 0
                ? "{$addOn->name} has gone."
                : "Only {$remaining} of {$addOn->name} left.");
        }

        return InventoryHold::create([
            'add_on_id' => $addOn->id,
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
    private function redeem(Code $code, ?string $buyerEmail): void
    {
        $locked = Code::query()->whereKey($code->id)->lockForUpdate()->first();

        if ($locked->max_redemptions !== null
            && $locked->usesInFlight(self::HOLD_MINUTES) >= $locked->max_redemptions) {
            throw new CheckoutException('That code has been fully redeemed.');
        }

        // A per-buyer limit needs a buyer. A door sale may have no address at
        // all, and a cap by email cannot be checked against nobody.
        if ($buyerEmail !== null && $locked->max_per_customer !== null
            && $locked->usesInFlight(self::HOLD_MINUTES, $buyerEmail) >= $locked->max_per_customer) {
            throw new CheckoutException(
                $locked->max_per_customer === 1
                    ? 'That code can be used once per person, and this email address has already used it.'
                    : "That code can be used {$locked->max_per_customer} times per person, and this email address has used it that many times."
            );
        }
    }
}
