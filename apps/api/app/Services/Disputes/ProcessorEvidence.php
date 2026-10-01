<?php

namespace App\Services\Disputes;

use App\Contracts\Payments\DescribesPayments;
use App\Contracts\Payments\PaymentEvent;
use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Contracts\Payments\PaymentRecord;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\PaymentEvidence;
use App\Services\Payments\GatewayFee;
use App\Services\Payments\PayLater;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The processor's own record of each payment, fetched once it has landed.
 *
 * Asked for after the payment notice, never during it. The notice is what
 * issues the tickets, and a processor slow to answer a second question must
 * not be the reason somebody's tickets are late — so the notice only leaves a
 * note that the record is wanted (expect), and a sweep every five minutes
 * does the asking (disputes:collect-evidence), trying again on a widening gap
 * for about a day before it says it gave up. A paid order the notice never
 * left a note for is picked up by the sweep as well.
 *
 * What is kept is chosen by each gateway (describePayment), from the
 * processor's API rather than from anything a browser sent, and fixed once
 * written: the database refuses to change a captured row.
 */
class ProcessorEvidence
{
    public function __construct(private readonly PaymentGatewayRegistry $gateways) {}

    /**
     * Say that an order's payment record is wanted, with what the signed
     * notice itself said that is worth keeping.
     *
     * Once per order; a second notice about the same payment changes nothing.
     */
    public function expect(Order $order, ?PaymentEvent $notice = null): void
    {
        if ($order->gateway === null || ! $this->canAsk($order->gateway)) {
            return;
        }

        $checkout = $this->fromNotice($notice);

        PaymentEvidence::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'event_id' => $order->event_id,
            'gateway' => $order->gateway,
            'payment_reference' => $order->gateway_payment_reference ?? $order->gateway_reference,
            'status' => PaymentEvidence::PENDING,
            'attempts' => 0,
            'next_attempt_at' => now(),
            'checkout' => $checkout === null ? null : json_encode($checkout),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Ask about every record that is due. Returns how many were asked about.
     */
    public function collectDue(): int
    {
        $this->enrolMissing();

        $due = PaymentEvidence::query()
            ->where('status', PaymentEvidence::PENDING)
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('next_attempt_at')
            ->limit((int) config('disputes.evidence.batch', 50))
            ->pluck('id');

        foreach ($due as $id) {
            $this->collect((string) $id);
        }

        return $due->count();
    }

    /**
     * Ask the processor about one payment, and keep what it says.
     *
     * Claimed first by counting the attempt and setting the next one, in a
     * single update that only succeeds against the count it read: two sweeps
     * reaching the same row ask once between them, and one killed half-way
     * leaves a row that is simply due again later.
     */
    public function collect(string $id): void
    {
        $row = PaymentEvidence::query()->with('order')->find($id);

        if ($row === null || $row->status !== PaymentEvidence::PENDING || $row->order === null) {
            return;
        }

        $attempt = $row->attempts + 1;
        $next = $this->nextTry($attempt);

        $claimed = PaymentEvidence::query()
            ->whereKey($row->id)
            ->where('status', PaymentEvidence::PENDING)
            ->where('attempts', $row->attempts)
            ->update(['attempts' => $attempt, 'next_attempt_at' => $next, 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $gateway = $this->gateways->all()[$row->gateway] ?? null;

        if (! $gateway instanceof DescribesPayments) {
            $this->failed($row, $attempt, null, 'This processor cannot be asked for its record of a payment.');

            return;
        }

        try {
            $record = $gateway->describePayment($row->order);
        } catch (Throwable $e) {
            $this->failed($row, $attempt, $next, $e->getMessage());

            return;
        }

        $fee = $this->feeFor($row->order, $record);

        // Captured, and what the payment cost written down, together: the
        // capture is what makes this the only time the cost is written, and
        // the row cannot be changed after it.
        DB::transaction(function () use ($row, $record, $fee) {
            $captured = PaymentEvidence::query()->whereKey($row->id)->where('status', PaymentEvidence::PENDING)->update([
                'status' => PaymentEvidence::CAPTURED,
                'payment_reference' => $record->reference,
                'facts' => json_encode($record->facts),
                'receipt_email' => $record->receiptEmail,
                'method_type' => $record->methodType,
                'fee_amount' => $fee,
                'captured_at' => now(),
                'next_attempt_at' => null,
                'last_error' => null,
                'updated_at' => now(),
            ]);

            if ($captured === 1 && $fee !== null) {
                $this->charged($row->order, $row, $record, $fee);
            }
        });
    }

    /**
     * Everything the processor took for the payment: its own figure, or for
     * a lender that gave none, the lender's published rate. Null for a card
     * whose figure did not come back, which keeps the published card rate
     * the order already has (Fulfiller).
     */
    private function feeFor(Order $order, PaymentRecord $record): ?int
    {
        if ($record->fee !== null) {
            return $record->fee;
        }

        $method = $record->methodType;

        return $order->gateway !== null && app(PayLater::class)->isPayLater($method)
            ? GatewayFee::on($order->total, $order->gateway.':'.$method)->amount
            : null;
    }

    /**
     * What taking the payment cost, now the processor has said how it was
     * paid and what it kept.
     *
     * The order's processor fee becomes the processor's own figure. It was
     * the published card rate until now (Fulfiller), which is right for most
     * cards and short for a foreign one, and well short for a lender: Klarna
     * and Affirm charge about twice what a card does. When the processor
     * gives no figure for a lender, its published rate stands in.
     *
     * A payment with a lender costs the organizer who opted in the
     * difference over a card (PayLater::premium), taken off their balance as
     * an adjustment. Only on a sale: a payment that was turned away never
     * reached their balance, and its whole cost is the platform's.
     *
     * The order's processor fee is the platform's side of the payment, set
     * against its service charge on every report (Metrics: net take). So
     * when the organizer pays part of it, the order keeps only the rest,
     * and the whole of what the processor took stays on the evidence row
     * (fee_amount), where the admin's order page reads both.
     */
    private function charged(Order $order, PaymentEvidence $row, PaymentRecord $record, int $fee): void
    {
        if ($order->paid_at === null || $order->gateway === null) {
            return;
        }

        $payLater = app(PayLater::class);
        $method = $record->methodType;

        $premium = $payLater->isPayLater($method) && $this->optedIn($order, $row)
            && LedgerEntry::query()->where('order_id', $order->id)->where('type', 'sale')->exists()
                ? $payLater->premium($order, $fee)
                : 0;

        Order::query()->whereKey($order->id)->update(['gateway_fee_amount' => $fee - $premium]);

        if ($premium <= 0) {
            return;
        }

        LedgerEntry::create([
            'organization_id' => $order->organization_id,
            'event_id' => $order->event_id,
            'order_id' => $order->id,
            'type' => 'adjustment',
            'amount' => -$premium,
            'currency' => $order->currency,
            'reason' => 'Paid later with '.$payLater->name((string) $method)." on order {$order->reference}: the lender's fee over a card's",
            'occurred_at' => now(),
        ]);
    }

    /**
     * Whether the night was offered paying later when this was bought.
     *
     * The session says so when its notice was kept (StripeGateway marks the
     * ones it offered); otherwise the night's opt-in as it stands, which it
     * almost always still is minutes after the sale.
     */
    private function optedIn(Order $order, PaymentEvidence $row): bool
    {
        $offered = $row->checkout['pay_later_offered'] ?? null;

        return is_bool($offered) ? $offered : (bool) $order->event?->pay_later_enabled;
    }

    /**
     * Paid orders the notice never left a note for.
     *
     * A notice that failed after the payment was recorded, or a payment
     * recorded before this existed and still recent. Only for a while
     * (disputes.evidence.look_back_days): the sweep is not a backfill.
     */
    private function enrolMissing(): void
    {
        $gateways = array_keys(array_filter($this->gateways->all(), fn ($gateway) => $gateway instanceof DescribesPayments));

        if ($gateways === []) {
            return;
        }

        Order::query()
            ->whereIn('gateway', $gateways)
            ->whereNotNull('gateway_reference')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', now()->subDays((int) config('disputes.evidence.look_back_days', 7)))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('payment_evidence')
                ->whereColumn('payment_evidence.order_id', 'orders.id'))
            ->limit((int) config('disputes.evidence.batch', 50))
            ->get()
            ->each(fn (Order $order) => $this->expect($order));
    }

    /**
     * When to ask again after this attempt, or null when this was the last.
     */
    private function nextTry(int $attempt): ?\DateTimeInterface
    {
        $gaps = array_values((array) config('disputes.evidence.retry_after_minutes', []));
        $gap = $gaps[$attempt - 1] ?? null;

        return $gap === null ? null : now()->addMinutes((int) $gap);
    }

    /**
     * Written down, and tried again later — or, after the last try, left for
     * a person with the processor's last answer beside it.
     */
    private function failed(PaymentEvidence $row, int $attempt, ?\DateTimeInterface $next, string $error): void
    {
        $gaveUp = $next === null;

        PaymentEvidence::query()->whereKey($row->id)->where('status', PaymentEvidence::PENDING)->update([
            'status' => $gaveUp ? PaymentEvidence::GAVE_UP : PaymentEvidence::PENDING,
            'last_error' => Str::limit($error, 480),
            'updated_at' => now(),
        ]);

        Log::log($gaveUp ? 'warning' : 'info', $gaveUp
            ? 'Gave up asking the payment processor for its record of a payment. Nothing about the order is affected; a dispute on it would be answered without that record.'
            : 'Could not get the payment processor\'s record of a payment yet. It will be asked again.', [
                'order' => $row->order?->reference,
                'gateway' => $row->gateway,
                'attempt' => $attempt,
                'error' => $error,
            ]);
    }

    /**
     * What a signed payment notice says that a dispute can use: for Stripe,
     * whether its own terms box was ticked on the payment page. Read from the
     * notice because Stripe signed it, and kept as it said it.
     *
     * @return array<string, mixed>|null
     */
    private function fromNotice(?PaymentEvent $notice): ?array
    {
        // The session as it completed, or as it said later that a payment
        // which took a while to clear went through.
        if ($notice === null || ! in_array($notice->raw['type'] ?? null, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return null;
        }

        $session = $notice->raw['data']['object'] ?? [];

        return [
            'session' => $session['id'] ?? null,
            'terms_of_service_asked' => $session['consent_collection']['terms_of_service'] ?? null,
            'terms_of_service' => $session['consent']['terms_of_service'] ?? null,
            // Whether the page offered Klarna and Affirm (StripeGateway).
            'pay_later_offered' => ($session['metadata']['pay_later'] ?? null) === 'offered',
        ];
    }

    private function canAsk(string $gateway): bool
    {
        return ($this->gateways->all()[$gateway] ?? null) instanceof DescribesPayments;
    }
}
