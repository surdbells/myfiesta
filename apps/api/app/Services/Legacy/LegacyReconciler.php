<?php

namespace App\Services\Legacy;

use App\Models\Dispute;
use App\Models\Order;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Checking the imported money against the place the money actually is.
 *
 * The old database says an order was paid because the old code said so when
 * a browser came back from Stripe — the same code that trusted the browser
 * for what had been bought. The import carries that claim across faithfully,
 * and faithfully is not the same as truly. Stripe is the record of what was
 * taken and what went back, so every imported order carrying a Stripe id is
 * asked about there and compared: whether it succeeded, for how much, in
 * which currency, what has been refunded since, and whether a bank has taken
 * it back. And no two orders may claim one payment: each would credit the
 * organizer with the same money.
 *
 * Read-only by default. With `apply`, one kind of disagreement is acted on —
 * a refund Stripe made that this database has not heard of is recorded, via
 * StripeRefundRecorder — and nothing else. A chargeback, or a payment two
 * orders share, is a person's to settle. Nothing here marks an order paid:
 * money Stripe holds for an order that is not paid here is a person to be
 * dealt with, not a row to be flipped.
 *
 * The old platform was Stripe only. Its database is the Canadian one, every
 * event in it is priced in CAD, and `_checkout` holds a Checkout Session id or
 * nothing; there is no Paystack reference anywhere in it to check.
 */
class LegacyReconciler
{
    public function __construct(
        private readonly StripeReader $stripe,
        private readonly StripeRefundRecorder $recorder,
    ) {}

    /**
     * Every imported order, oldest legacy row first, a page at a time.
     *
     * @param  array<string, true>  $done  order ids already in the report being resumed
     * @param  Closure(array<string, mixed>): void  $each  one report row per order checked
     * @return array{checked: int, skipped: int} skipped: abandoned checkouts that never reached Stripe
     */
    public function run(bool $apply, array $done, ?int $limit, Closure $each): array
    {
        $checked = $skipped = 0;

        // Every imported order, before any is asked about: the first of two
        // orders on one payment has to be told apart from a single order just
        // as much as the second does.
        $shared = self::sharedPayments();

        DB::table('legacy_map')
            ->where('source_table', 'tickets_sales')
            ->where('target_type', 'order')
            ->select(['id', 'source_id', 'target_id'])
            ->chunkById(200, function ($maps) use ($apply, $done, $limit, $each, $shared, &$checked, &$skipped) {
                $orders = Order::query()
                    ->whereIn('id', $maps->pluck('target_id'))
                    ->get()
                    ->keyBy('id');

                foreach ($maps as $map) {
                    if ($limit !== null && $checked >= $limit) {
                        return false;
                    }

                    $order = $orders[$map->target_id] ?? null;

                    if ($order === null || isset($done[$order->id])) {
                        continue;
                    }

                    $row = $this->reconcile($order, (string) $map->source_id, $apply, $shared[$order->id] ?? []);

                    if ($row === null) {
                        $skipped++;

                        continue;
                    }

                    $checked++;
                    $each($row);
                }

                return true;
            });

        return ['checked' => $checked, 'skipped' => $skipped];
    }

    /**
     * One order: asked about, compared, and — only if asked to — its missing
     * refunds recorded.
     *
     * Null for an order that never reached Stripe and never claimed to: one
     * of the abandoned checkouts, cancelled on import, with no session to ask
     * about. There are hundreds and none of them is a finding.
     *
     * An order whose payment another order also claims is filed as that and
     * nothing else, so --apply leaves it alone: a refund Stripe made once
     * would otherwise be written against each of them.
     *
     * @param  list<string>  $sharedWith  references of the other orders on the same Stripe payment
     * @return array<string, mixed>|null
     *
     * @throws StripeReadFailed when Stripe refuses the key, which would fail every order after it
     */
    public function reconcile(Order $order, string $legacyId, bool $apply, array $sharedWith = []): ?array
    {
        $reference = self::stripeReferenceFor($order);
        $refundedHere = self::refundedHere($order);

        if ($reference === null) {
            if (! self::claimsMoney($order->status)) {
                return null;
            }

            return $this->row($order, $legacyId, $refundedHere, ReconcileOutcome::NoStripeId, null,
                'Paid here with no Checkout Session or PaymentIntent id to check it by.');
        }

        try {
            $payment = $this->stripe->payment($reference);
        } catch (StripeReadFailed $e) {
            if ($e->stopsTheRun) {
                throw $e;
            }

            return $this->row(
                $order, $legacyId, $refundedHere,
                $e->missing ? ReconcileOutcome::MissingInStripe : ReconcileOutcome::Unreachable,
                ['reference' => $reference],
                $e->getMessage(),
            );
        }

        $disputesHere = self::disputesHere($order);

        $outcome = $sharedWith !== []
            ? ReconcileOutcome::SharedStripePayment
            : self::classify($order->status, (int) $order->total_amount, $order->currency, $refundedHere, $payment, $disputesHere);

        $action = '';

        if ($apply && $outcome === ReconcileOutcome::RefundedInStripeOnly) {
            $action = implode('; ', $this->recorder->record($order, $payment['refunds']));
        }

        $detail = self::detail($outcome, $payment, $refundedHere, $disputesHere, $sharedWith);

        return $this->row($order, $legacyId, $refundedHere, $outcome, $payment, $detail, $action);
    }

    /**
     * The comparison itself, with nothing fetched and nothing written.
     *
     * @param  array{paid: bool, amount: ?int, currency: ?string, refunds: list<array{amount: int, status: string}>, disputes?: list<array{id: string, status: string}>}  $payment
     * @param  array<string, string>  $disputesHere  Stripe's dispute id => its status in this database
     */
    public static function classify(
        string $status,
        int $total,
        string $currency,
        int $refundedHere,
        array $payment,
        array $disputesHere = [],
    ): ReconcileOutcome {
        $refundedThere = self::refundedThere($payment);

        // First, and whatever the order says here. A bank taking the money
        // back changes neither the session nor the payment, and makes no
        // refund, so every comparison below would read it as money kept —
        // and the organizer's balance would pay it out.
        if (self::disputesNotHere($payment, $disputesHere) !== []) {
            return ReconcileOutcome::DisputedInStripe;
        }

        if (! self::claimsMoney($status)) {
            // Cancelled here. Fine unless Stripe kept money for it — the old
            // platform's worst failure, a buyer who paid, closed the tab
            // before coming back, and was never sent a ticket.
            return $payment['paid'] && $refundedThere < ($payment['amount'] ?? PHP_INT_MAX)
                ? ReconcileOutcome::PaidInStripeOnly
                : ReconcileOutcome::Matched;
        }

        if (! $payment['paid']) {
            return ReconcileOutcome::NotPaidInStripe;
        }

        if ($payment['amount'] !== $total || $payment['currency'] !== strtoupper($currency)) {
            return ReconcileOutcome::AmountMismatch;
        }

        return match (true) {
            $refundedThere > $refundedHere => ReconcileOutcome::RefundedInStripeOnly,
            $refundedThere < $refundedHere => ReconcileOutcome::RefundedHereOnly,
            default => ReconcileOutcome::Matched,
        };
    }

    /**
     * The id to ask Stripe about: the payment if it is known, else the
     * session the order was made against.
     */
    public static function stripeReferenceFor(Order $order): ?string
    {
        foreach ([$order->gateway_payment_reference, $order->gateway_reference] as $candidate) {
            if (is_string($candidate) && (str_starts_with($candidate, 'pi_') || str_starts_with($candidate, 'cs_'))) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * What this database says has gone back.
     *
     * An order the old platform marked refunded arrives with no refund rows —
     * it never wrote any — so its status is taken as the whole of it.
     */
    public static function refundedHere(Order $order): int
    {
        $recorded = (int) $order->refunds()->where('status', 'succeeded')->sum('amount');

        return $order->status === 'refunded'
            ? max($recorded, (int) $order->total_amount)
            : $recorded;
    }

    /**
     * What this database already knows of disputes on the order: the
     * platform's own dispute handling, hearing from Stripe after the cutover.
     *
     * @return array<string, string> Stripe's dispute id => its status here
     */
    public static function disputesHere(Order $order): array
    {
        return Dispute::query()
            ->where('order_id', $order->id)
            ->pluck('status', 'gateway_reference')
            ->all();
    }

    /**
     * Every imported order that shares its Stripe payment with another.
     *
     * The old database has no constraint that says one Checkout Session is
     * one sale, and neither does the import. Two orders on one payment would
     * each credit the organizer with it and each match Stripe on its own, so
     * nothing but counting them together finds it.
     *
     * Counted by the ids stored on the orders, which for imported orders are
     * the sessions. Two sessions for one payment is not something Stripe
     * does; should it come to that anyway, the recorder will not write one
     * Stripe refund against two orders (StripeRefundRecorder).
     *
     * @return array<string, list<string>> order id => the other orders' references
     */
    public static function sharedPayments(): array
    {
        $claimedBy = [];

        DB::table('legacy_map')
            ->where('source_table', 'tickets_sales')
            ->where('target_type', 'order')
            ->select(['id', 'target_id'])
            ->chunkById(500, function ($maps) use (&$claimedBy): void {
                Order::query()
                    ->whereIn('id', $maps->pluck('target_id'))
                    ->get(['id', 'reference', 'gateway_reference', 'gateway_payment_reference'])
                    ->each(function (Order $order) use (&$claimedBy): void {
                        foreach ([$order->gateway_reference, $order->gateway_payment_reference] as $candidate) {
                            if (is_string($candidate) && (str_starts_with($candidate, 'pi_') || str_starts_with($candidate, 'cs_'))) {
                                $claimedBy[$candidate][$order->id] = $order->reference;
                            }
                        }
                    });
            });

        $shared = [];

        foreach ($claimedBy as $orders) {
            if (count($orders) < 2) {
                continue;
            }

            foreach ($orders as $orderId => $reference) {
                $others = array_values(array_diff_key($orders, [$orderId => true]));

                $shared[$orderId] = array_values(array_unique([...$shared[$orderId] ?? [], ...$others]));
            }
        }

        return $shared;
    }

    private static function claimsMoney(string $status): bool
    {
        return in_array($status, ['paid', 'partially_refunded', 'refunded'], true);
    }

    /**
     * Stripe's disputes on the payment that have taken the money, or may,
     * and that this database does not already hold as they stand.
     *
     * A dispute won, or an inquiry closed without becoming one, took nothing.
     * One this database already holds in the same state is being dealt with
     * by the platform's own dispute handling, which writes the chargeback to
     * the ledger when one is lost.
     *
     * @param  array<string, string>  $disputesHere
     * @return list<array{id: string, amount: int, currency: string, status: string, reason: string}>
     */
    private static function disputesNotHere(array $payment, array $disputesHere): array
    {
        return array_values(array_filter(
            $payment['disputes'] ?? [],
            function (array $dispute) use ($disputesHere): bool {
                $state = match ($dispute['status']) {
                    'won', 'warning_closed' => null,
                    'lost' => 'lost',
                    // Everything else is still open, including any status
                    // Stripe adds after this was written: a dispute nobody
                    // recognises is one for a person to look at.
                    default => 'open',
                };

                return $state !== null && ($disputesHere[$dispute['id']] ?? null) !== $state;
            },
        ));
    }

    /** Only refunds that went through. A pending or failed one is not money back. */
    private static function refundedThere(array $payment): int
    {
        return array_sum(array_map(
            fn (array $r) => $r['status'] === 'succeeded' ? (int) $r['amount'] : 0,
            $payment['refunds'] ?? [],
        ));
    }

    /**
     * @param  array<string, string>  $disputesHere
     * @param  list<string>  $sharedWith
     */
    private static function detail(
        ReconcileOutcome $outcome,
        array $payment,
        int $refundedHere,
        array $disputesHere = [],
        array $sharedWith = [],
    ): string {
        $unsettled = array_filter($payment['refunds'], fn (array $r) => $r['status'] !== 'succeeded');
        $refundedThere = self::refundedThere($payment);

        $disputed = self::disputesNotHere($payment, $disputesHere);
        $disputes = implode(', ', array_map(
            fn (array $d) => trim("{$d['id']} {$d['status']} {$d['amount']} {$d['currency']} {$d['reason']}"),
            $disputed,
        ));

        $said = match ($outcome) {
            ReconcileOutcome::NotPaidInStripe => "Stripe says {$payment['status']}.",
            ReconcileOutcome::PaidInStripeOnly => 'Stripe took this payment; the order was never paid here and has no tickets.',
            ReconcileOutcome::AmountMismatch => 'Stripe charged '.($payment['amount'] ?? '?').' '.($payment['currency'] ?? '?').'.',
            ReconcileOutcome::RefundedInStripeOnly,
            ReconcileOutcome::RefundedHereOnly => "Refunded {$refundedThere} in Stripe, {$refundedHere} here.",
            ReconcileOutcome::DisputedInStripe => "Disputed in Stripe: {$disputes}.",
            ReconcileOutcome::SharedStripePayment => 'The same Stripe payment is on '.implode(', ', $sharedWith).' as well.',
            default => '',
        };

        if ($disputed !== [] && $outcome !== ReconcileOutcome::DisputedInStripe) {
            $said = trim($said." Also disputed in Stripe: {$disputes}.");
        }

        // The other disagreement is the one the row is filed under, and
        // --apply leaves these orders alone until a person has looked. The
        // refund is still money that went back, so it is not left unsaid.
        $filedElsewhere = [
            ReconcileOutcome::AmountMismatch,
            ReconcileOutcome::NotPaidInStripe,
            ReconcileOutcome::PaidInStripeOnly,
            ReconcileOutcome::DisputedInStripe,
            ReconcileOutcome::SharedStripePayment,
        ];

        if ($refundedThere > 0 && in_array($outcome, $filedElsewhere, true)) {
            $said = trim($said." Also refunded {$refundedThere} in Stripe, {$refundedHere} here.");
        }

        if ($unsettled !== []) {
            $said = trim($said.' '.count($unsettled).' refund(s) in Stripe not yet succeeded: '
                .implode(', ', array_map(fn (array $r) => "{$r['id']} {$r['status']}", $unsettled)).'.');
        }

        return $said;
    }

    /**
     * One line of the report.
     *
     * Amounts in minor units, as they are everywhere else. No buyer's name or
     * address and nothing from a ticket: this file is going to be opened in a
     * spreadsheet and passed around, and the order reference finds everything
     * else for whoever is allowed to see it.
     *
     * @param  array<string, mixed>|null  $payment
     * @return array<string, mixed>
     */
    private function row(
        Order $order,
        string $legacyId,
        int $refundedHere,
        ReconcileOutcome $outcome,
        ?array $payment,
        string $detail,
        string $action = '',
    ): array {
        return [
            'legacy_sale_id' => $legacyId,
            'order_id' => $order->id,
            'order_reference' => $order->reference,
            'outcome' => $outcome->value,
            'status_here' => $order->status,
            'currency_here' => $order->currency,
            'total_here' => (int) $order->total_amount,
            'refunded_here' => $refundedHere,
            'stripe_reference' => $payment['reference'] ?? '',
            'payment_intent' => $payment['payment_intent'] ?? '',
            'stripe_status' => $payment['status'] ?? '',
            'stripe_currency' => $payment['currency'] ?? '',
            'stripe_amount' => $payment['amount'] ?? '',
            'stripe_refunded' => $payment === null || ! isset($payment['refunds']) ? '' : self::refundedThere($payment),
            'detail' => $detail,
            'action' => $action,
        ];
    }
}
