<?php

namespace App\Services\Payments;

use App\Models\Event;
use App\Models\Order;
use App\Models\PaymentEvidence;
use App\Services\Checkout\Quote;
use App\Services\Settings\PlatformSettings;
use Carbon\CarbonInterface;

/**
 * Buy now, pay later: Klarna and Affirm, offered on Stripe's own page.
 *
 * Three halves have to say yes before a buyer sees it. myFiesta has it
 * switched on (bnpl_enabled, in Platform settings). The organizer opted the
 * night in (events.pay_later_enabled), knowing they pay what the lender
 * charges over a card (premium). And the night is near enough
 * (bnpl_max_days_before_event, at most 110): Affirm takes a refund back for
 * 120 days after the payment and Klarna for 180, so a night further out could
 * be called off after the money can no longer go back the way it came, and
 * the ten days between leave room for refunds after the night. A night moved
 * later after it sold can still get there; support returns those another way
 * and records it (refundRefusal).
 *
 * Only in Canadian dollars, which is what both lenders take from a Canadian
 * account. What the buyer sees of it is the lenders' names and nothing else:
 * an instalment amount is the lender's to quote, on its own page, and a
 * figure of ours could be wrong in a way somebody relies on.
 */
class PayLater
{
    public const KLARNA = 'klarna';

    public const AFFIRM = 'affirm';

    public function __construct(private readonly PlatformSettings $settings) {}

    /**
     * Whether myFiesta offers paying later on this night at all, whatever its
     * organizer chose: switched on, its Stripe configuration made, and priced
     * in the one currency the lenders take. What the console asks before it
     * shows the opt-in.
     *
     * The configuration is asked about here as well as at checkout
     * (StripeGateway::waysToPay), so the event page, the quote and the
     * console never name Klarna and Affirm while Stripe's page offers neither.
     */
    public function available(Event $event): bool
    {
        return $this->settings->payLaterEnabled()
            && filled(config('payments.stripe.payment_method_configurations.pay_later'))
            && strtoupper((string) $event->currency) === $this->currency();
    }

    /**
     * Whether a buyer can choose to pay for this night later, now.
     *
     * The night must still be to come: one under way is sold at the door
     * more than online, and refunds of it are what the window protects.
     */
    public function offeredFor(Event $event, ?CarbonInterface $now = null): bool
    {
        $now ??= now();
        $startsAt = $event->starts_at;

        return $this->available($event)
            && (bool) $event->pay_later_enabled
            && $startsAt !== null
            && $startsAt->isAfter($now)
            && $startsAt->lte($now->copy()->addDays($this->settings->payLaterMaxDaysBeforeEvent()));
    }

    /**
     * Whether this order's payment page offers Klarna and Affirm: the night
     * is offered, and at least one of them lends this much.
     */
    public function eligible(Order $order): bool
    {
        $event = $order->event;

        return $event !== null
            && strtoupper((string) $order->currency) === $this->currency()
            && $this->offeredFor($event)
            && $this->providersFor((int) $order->total_amount) !== [];
    }

    /**
     * The lenders that take an order of this size, in the order the page
     * names them.
     *
     * @return list<string>
     */
    public function providersFor(int $amount): array
    {
        $providers = [];

        foreach ($this->providers() as $provider => $terms) {
            if ($amount >= (int) $terms['min'] && $amount <= (int) $terms['max']) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * On the event page: who lends for this night, or null when nobody can
     * pay for it later.
     *
     * @return array{providers: list<string>}|null
     */
    public function forEvent(Event $event): ?array
    {
        return $this->offeredFor($event)
            ? ['providers' => array_keys($this->providers())]
            : null;
    }

    /**
     * On a quote: whether this basket can be paid for later, and with whom.
     *
     * Null when the night is not offered, or the basket is free and there is
     * nothing to pay. Not eligible, with nobody named, when it is offered but
     * no lender takes an order this size.
     *
     * @return array{eligible: bool, providers: list<string>}|null
     */
    public function forQuote(Quote $quote): ?array
    {
        if ($quote->total->amount <= 0
            || strtoupper($quote->total->currency) !== $this->currency()
            || ! $this->offeredFor($quote->event)) {
            return null;
        }

        $providers = $this->providersFor($quote->total->amount);

        return ['eligible' => $providers !== [], 'providers' => $providers];
    }

    /** "Klarna", for a lender's name in Stripe's word. */
    public function name(string $provider): string
    {
        return (string) ($this->providers()[$provider]['name'] ?? ucfirst($provider));
    }

    public function isPayLater(?string $method): bool
    {
        return $method !== null && array_key_exists($method, $this->providers());
    }

    /**
     * What a payment with this lender cost over what a card would have.
     *
     * The platform pays a card's fee out of the service charge on every
     * order, as it always has. The rest is the price of the organizer's
     * opt-in, and theirs to pay: taken off their balance as an adjustment
     * once the processor has said what it charged (ProcessorEvidence).
     */
    public function premium(Order $order, int $fee): int
    {
        $card = GatewayFee::on($order->total, (string) $order->gateway)->amount;

        return max(0, $fee - $card);
    }

    /**
     * How the order was paid, in Stripe's word: card, klarna, affirm. Null
     * until the processor has been asked (ProcessorEvidence), and for a sale
     * that never went through one.
     */
    public function methodOf(Order $order): ?string
    {
        $method = PaymentEvidence::query()->where('order_id', $order->id)->value('method_type');

        return is_string($method) && $method !== '' ? $method : null;
    }

    /**
     * When the order's lender stops taking its money back, or null for a
     * payment that can always go back the way it came (a card, a wallet).
     */
    public function windowClosesAt(Order $order): ?CarbonInterface
    {
        $method = $this->methodOf($order);

        if (! $this->isPayLater($method) || $order->paid_at === null) {
            return null;
        }

        return $order->paid_at->copy()->addDays((int) $this->providers()[$method]['refund_days']);
    }

    /**
     * Why the order's money can no longer go back through its lender, or
     * null when it can.
     *
     * Klarna and Affirm take a refund back only for so long after the
     * payment, and Stripe refuses one after that. Asked before the refund is
     * tried, so whoever asked is told what happens instead of being shown
     * Stripe's refusal: support returns the money another way and records it
     * on the order (RefundService::recordMadeElsewhere, from the admin's
     * order page), and nothing has moved in the meantime. Written for the
     * organizer, who is the one refunding from the console.
     */
    public function refundRefusal(Order $order, ?CarbonInterface $now = null): ?string
    {
        $closes = $this->windowClosesAt($order);

        if ($closes === null || $closes->isAfter($now ?? now())) {
            return null;
        }

        $method = (string) $this->methodOf($order);
        $days = (int) $this->providers()[$method]['refund_days'];
        $name = $this->name($method);
        $paid = $order->paid_at?->copy()->timezone($order->event->timezone ?? 'UTC')->format('j F Y');

        return "{$name} takes money back only within {$days} days of a payment, and this order was paid with {$name} on {$paid}, so it cannot go back that way. "
            ."Write to myFiesta support with the order's reference: they return the money another way and record it on the order, and its tickets stop working then. "
            .'Nothing has been refunded yet, and the tickets still work.';
    }

    /**
     * Why a ticket paid for with a lender cannot be handed back for resale,
     * or null when it can.
     *
     * A ticket handed back is paid for when somebody else buys the place,
     * which can be any time until resale closes before the night. If the
     * lender's window shuts before the night, that payment could be refused
     * after the ticket has already stopped working, and the holder would be
     * left with neither (Resale::payBack). Keeping the ticket is the better
     * deal for them.
     */
    public function resaleRefusal(?Order $order, Event $event): ?string
    {
        $closes = $order === null ? null : $this->windowClosesAt($order);

        if ($closes === null || ($event->starts_at !== null && $closes->isAfter($event->starts_at))) {
            return null;
        }

        $name = $this->name((string) $this->methodOf($order));

        return "This ticket was paid for with {$name}, which can no longer take the money back before this event, so it cannot be handed back. Your ticket still works at the door.";
    }

    /**
     * How many of these orders can no longer be refunded through their
     * lender: what a cancellation would leave for support to return by hand.
     *
     * @param  iterable<Order>  $orders
     */
    public function pastRefundWindow(iterable $orders, ?CarbonInterface $now = null): int
    {
        $byId = [];

        foreach ($orders as $order) {
            $byId[$order->id] = $order;
        }

        if ($byId === []) {
            return 0;
        }

        $methods = PaymentEvidence::query()
            ->whereIn('order_id', array_keys($byId))
            ->whereIn('method_type', array_keys($this->providers()))
            ->pluck('method_type', 'order_id');

        $now ??= now();
        $past = 0;

        foreach ($methods as $orderId => $method) {
            $paidAt = $byId[$orderId]->paid_at ?? null;
            $days = (int) $this->providers()[$method]['refund_days'];

            if ($paidAt !== null && ! $paidAt->copy()->addDays($days)->isAfter($now)) {
                $past++;
            }
        }

        return $past;
    }

    /**
     * What each lender charges, what a card does, and how close to the night
     * it is offered: what the organizer is told before opting in.
     *
     * @return array{card: array{bps: int, flat: int}, klarna: array{bps: int, flat: int}, affirm: array{bps: int, flat: int}}
     */
    public function fees(): array
    {
        $rate = function (string $gateway): array {
            $rates = (array) config("payments.gateway_fees.{$gateway}", []);

            return ['bps' => (int) ($rates['bps'] ?? 0), 'flat' => (int) ($rates['flat'] ?? 0)];
        };

        return [
            'card' => $rate('stripe'),
            self::KLARNA => $rate('stripe:'.self::KLARNA),
            self::AFFIRM => $rate('stripe:'.self::AFFIRM),
        ];
    }

    public function maxDaysBeforeEvent(): int
    {
        return $this->settings->payLaterMaxDaysBeforeEvent();
    }

    private function currency(): string
    {
        return strtoupper((string) config('payments.pay_later.currency', 'CAD'));
    }

    /** @return array<string, array{name: string, min: int, max: int, refund_days: int}> */
    private function providers(): array
    {
        return (array) config('payments.pay_later.providers', []);
    }
}
