<?php

namespace App\Services\Refunds;

use App\Contracts\Payments\FindsRefunds;
use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Contracts\Payments\RefundNotice;
use App\Contracts\Payments\TotalsRefunds;
use App\Enums\Permission;
use App\Enums\Role;
use App\Mail\RefundMadeElsewhere;
use App\Mail\SoldOutWhilePaying;
use App\Models\Code;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Integrations\Payloads;
use App\Services\Integrations\Webhooks;
use App\Support\Allocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Returning money, and turning off the tickets it bought.
 *
 * Refunds are by ticket, never by amount. An organizer refunding "£40" leaves
 * every ticket valid and the door with no idea, so the person who was paid back
 * still walks in — and the shortfall only surfaces at reconciliation, weeks
 * later, as a number nobody can attribute. Naming the tickets makes the
 * proportion exact and voiding them part of the same act. The exceptions are
 * an order with no tickets to name (refundUnfulfilled), which goes back whole,
 * and a refund made in the processor's own dashboard (recordMadeElsewhere),
 * which named no tickets because nobody here was asked.
 *
 * The gateway call happens outside the database transaction, deliberately.
 * Holding a row lock open across an HTTP request to Stripe is how a payment
 * processor's slow afternoon becomes a database incident. Instead the refund is
 * written as pending under the lock — which reserves the amount against the
 * cap, and its tickets against a second refund — then attempted, then settled
 * under the lock again.
 *
 * One Refund row is one refund at the processor, however many times it is
 * sent. The row's id goes with every request as its idempotency key, and a
 * request that gets no answer leaves the row waiting (unanswered_at) instead
 * of failed. Failed says "nothing moved, try again", and after a timeout
 * nobody knows that: a retry under a new row was how a buyer got paid twice.
 */
class RefundService
{
    /** How long a refund that got no answer waits before the processor is asked about it. */
    public const FOLLOW_UP_AFTER_MINUTES = 10;

    /**
     * How long a pending refund with no answer on record has been sitting.
     *
     * Every refund is pending while it is sent, for a second or two. One still
     * pending half an hour later, with nothing written about an answer, is a
     * request that stopped half way — a worker that died mid-call — and is
     * asked about like any other.
     */
    public const STALLED_AFTER_MINUTES = 30;

    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<string>|null  $ticketIds  null refunds everything still refundable
     *
     * @throws RefundRefused when the request cannot be honoured
     */
    public function refund(
        Order $order,
        ?array $ticketIds = null,
        ?User $issuer = null,
        ?string $reason = null,
    ): Refund {
        $refund = $this->reserve($order, $ticketIds, $issuer, $reason);

        $result = $this->attempt($order, $refund);

        [$settled, $settledHere] = $this->settleOnce($order, $refund, $result, withTickets: true);

        // The processor's own notice got there while our request was still on
        // its way back — a timeout, and a retry a second later — and settled
        // it, and audited it. Audited again here, one refund would read as two
        // in a log nobody may correct.
        if (! $settledHere && $settled->status !== 'pending') {
            return $settled;
        }

        // Money leaving the platform is the single most important thing to be
        // able to attribute afterwards. Recorded whether or not the provider
        // accepted it — a refused refund is exactly what somebody investigates.
        $this->auditor->record(
            $this->auditAction($settled),
            $order,
            $issuer,
            metadata: [
                'refund_id' => $settled->id,
                'amount' => $settled->amount,
                'currency' => $order->currency,
                'tickets' => $ticketIds === null ? 'all remaining' : count($ticketIds),
                'reason' => $reason,
                'failure' => $settled->failure_reason,
            ],
        );

        if ($settled->status === 'pending') {
            Log::warning('A refund got no answer from the payment processor. It is waiting to be asked about again.', [
                'refund_id' => $settled->id,
                'order' => $order->reference,
                'why' => $settled->failure_reason,
            ]);
        }

        $this->afterRefusal($order, $settled);

        return $settled;
    }

    /**
     * All of it back, for a payment that never became tickets.
     *
     * The one refund that is not by ticket, because there are none. The money
     * landed after the places it was for had been sold to somebody else, and
     * fulfilment issued nothing — so there is nothing to void and nothing to
     * split, and the buyer gets back exactly what they were charged. The
     * service charge too: it was the platform's fee for selling them a
     * ticket, and nobody sold them one.
     *
     * The same three steps as any refund, for the same reason: written as
     * pending under the order's lock, sent to the processor outside it, and
     * settled under the lock again. The buyer is told once the processor has
     * said yes, whenever that is — now, or when a refund that got no answer
     * is confirmed later.
     *
     * @throws RefundRefused when the order has tickets, was never charged
     *                       through a processor, or has its money on the way
     *                       back already
     */
    public function refundUnfulfilled(Order $order, ?string $reason = null): Refund
    {
        $refund = $this->reserveUnfulfilled($order, $reason);

        [$settled, $settledHere] = $this->settleOnce($order, $refund, $this->attempt($order, $refund), withTickets: false);

        // The processor's own notice got there first, and whatever it settled
        // has been recorded and told already.
        if (! $settledHere && $settled->status !== 'pending') {
            return $settled;
        }

        if ($settled->status === 'failed') {
            // No organizer is going to press a button for this one: they never
            // sold anything. Somebody here has to, and this is how they hear.
            Log::alert('A payment that became no tickets could not be returned.', [
                'order' => $order->reference,
                'refund_id' => $settled->id,
                'failure' => $settled->failure_reason,
            ]);
        }

        if ($settled->status === 'pending') {
            Log::warning('A payment that became no tickets is on its way back, unconfirmed. It will be asked about again.', [
                'order' => $order->reference,
                'refund_id' => $settled->id,
                'why' => $settled->failure_reason,
            ]);
        }

        $this->auditor->record(
            $this->auditAction($settled),
            $order,
            metadata: [
                'refund_id' => $settled->id,
                'amount' => $settled->amount,
                'currency' => $order->currency,
                'tickets' => 0,
                'reason' => $reason,
                'failure' => $settled->failure_reason,
            ],
        );

        $this->tellTheBuyerIfDone($order, $settled);

        $this->afterRefusal($order, $settled);

        return $settled;
    }

    // --- refunds that got no answer -----------------------------------------

    /**
     * Ask about every refund that has waited long enough for an answer.
     *
     * Run every few minutes (refunds:follow-up). Returns how many were looked
     * at; each one is settled, or left waiting for the next run.
     */
    public function followUpWaiting(int $limit = 100): int
    {
        $due = Refund::query()
            ->where('status', 'pending')
            ->where('source', Refund::FROM_PLATFORM)
            ->where($this->dueForFollowUp(...))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($due as $refund) {
            try {
                $this->followUp($refund);
            } catch (Throwable $e) {
                // One refund the processor chokes on must not stop the rest.
                Log::error('Following up a refund threw.', [
                    'refund_id' => $refund->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $due->count();
    }

    /**
     * Find out what happened to one refund that got no answer, and finish it.
     *
     * The processor is asked first whether it has the refund. If it does, that
     * is the answer, and nothing is sent. Only if it does not is the refund
     * sent again — under the same key, so even a processor that had it after
     * all pays it once. A processor that cannot be asked leaves the refund
     * waiting for the next run: guessing is what this whole arrangement is
     * here to avoid.
     */
    public function followUp(Refund $refund): Refund
    {
        // One follow-up at a time. Claiming it moves unanswered_at to now,
        // which takes it out of what the next run — or a second worker in
        // this one — would pick.
        $claimed = Refund::query()
            ->whereKey($refund->id)
            ->where('status', 'pending')
            ->where($this->dueForFollowUp(...))
            ->update(['unanswered_at' => now()]);

        if ($claimed === 0) {
            return $refund->refresh();
        }

        $order = $refund->order;
        $gateway = $this->gatewayFor($order);

        if (! $gateway instanceof FindsRefunds) {
            Log::alert('A refund is waiting on a payment processor that cannot be asked about it. A person has to check it by hand.', [
                'refund_id' => $refund->id,
                'order' => $order->reference,
                'gateway' => $order->gateway,
            ]);

            return $refund->refresh();
        }

        try {
            $found = $gateway->findRefund(
                $order,
                $refund->id,
                (int) $refund->amount,
                $refund->created_at,
                $this->knownProcessorReferences($order, except: $refund),
            );
        } catch (Throwable $e) {
            Log::warning('The payment processor could not be asked about a refund. It stays waiting.', [
                'refund_id' => $refund->id,
                'order' => $order->reference,
                'exception' => $e->getMessage(),
            ]);

            return $this->stillWaiting($refund->refresh(), $order);
        }

        $outcome = match (true) {
            $found === null => $this->attempt($order, $refund),
            $found->succeeded => AttemptOutcome::succeeded($found->reference),
            default => AttemptOutcome::failed($found->failureReason ?? 'The payment processor could not pay the refund out.'),
        };

        $settled = $this->finish($order, $refund, $outcome, 'refunds:follow-up');

        $this->afterRefusal($order, $settled);

        return $settled;
    }

    /**
     * A refund of ours came to nothing. Count what the processor has refunded.
     *
     * A no can mean the money has already gone back some other way. Stripe
     * announces refunds made in its dashboard as a running total, and a
     * refund of ours still waiting for an answer counts as on record against
     * it (ProcessorRefunds) — so a dashboard refund made while ours waits is
     * hidden behind ours. If ours then turns out never to have arrived, and
     * is refused when it is sent again because the money is already back
     * with the buyer, nothing announces that total again: the buyer has
     * everything back, the tickets still open the door, and the organizer's
     * balance still counts the sale.
     *
     * So after any refusal the processor is asked for its own total, and it
     * is read exactly as the announcement would have been, now that ours is
     * no longer on record. Usually it finds nothing new. A processor that
     * cannot be asked leaves things as they are, and says so.
     */
    private function afterRefusal(Order $order, Refund $refund): void
    {
        if ($refund->status !== 'failed') {
            return;
        }

        $gateway = $this->gatewayFor($order);

        if (! $gateway instanceof TotalsRefunds) {
            return;
        }

        try {
            $total = $gateway->refundedSoFar($order);
        } catch (Throwable $e) {
            Log::warning('A refund was refused, and the payment processor could not then be asked what it has refunded on the payment.', [
                'refund_id' => $refund->id,
                'order' => $order->reference,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        // Resolved here, not injected: ProcessorRefunds records what it finds
        // through this class, and each needing the other at construction
        // would build neither.
        app(ProcessorRefunds::class)->reconcile($order, $total);
    }

    /**
     * The processor's own word on a refund of ours that was still pending.
     *
     * Its notice can arrive before the answer to our request does, or instead
     * of one that never came. Either way it is the answer, and the refund is
     * settled by it exactly as if the request had come back.
     */
    public function settleFromProcessor(Refund $refund, RefundNotice $notice): Refund
    {
        $outcome = $notice->status === RefundNotice::FAILED
            ? AttemptOutcome::failed('The payment processor reported that this refund failed.')
            : AttemptOutcome::succeeded((string) ($notice->processorReference ?? ''));

        return $this->finish($refund->order, $refund, $outcome, 'processor notice');
    }

    /**
     * Settle a refund of ours from an answer that came later, and say so.
     *
     * The audit entry is written only when this call is what settled it: a
     * notice and a follow-up can both arrive for one refund, and the second
     * finds it already done.
     */
    private function finish(Order $order, Refund $refund, AttemptOutcome $outcome, string $how): Refund
    {
        [$settled, $settledHere] = $this->settleOnce(
            $order,
            $refund,
            $outcome,
            withTickets: $refund->tickets()->exists(),
        );

        if (! $settledHere) {
            return $settled->status === 'pending' ? $this->stillWaiting($settled, $order) : $settled;
        }

        $this->auditor->record(
            $this->auditAction($settled),
            $order,
            metadata: [
                'refund_id' => $settled->id,
                'amount' => $settled->amount,
                'currency' => $order->currency,
                'tickets' => $settled->tickets()->count(),
                'reason' => $settled->reason,
                'failure' => $settled->failure_reason,
                'settled_by' => $how,
            ],
        );

        if ($settled->status === 'failed' && $settled->tickets()->doesntExist()) {
            Log::alert('A payment that became no tickets could not be returned.', [
                'order' => $order->reference,
                'refund_id' => $settled->id,
                'failure' => $settled->failure_reason,
            ]);
        }

        $this->tellTheBuyerIfDone($order, $settled);

        return $settled;
    }

    /** A day without an answer is somebody's job, not the next run's. */
    private function stillWaiting(Refund $refund, Order $order): Refund
    {
        if ($refund->created_at !== null && $refund->created_at->lt(now()->subDay())) {
            Log::alert('A refund has waited more than a day for the payment processor. A person has to check it by hand.', [
                'refund_id' => $refund->id,
                'order' => $order->reference,
                'gateway' => $order->gateway,
                'why' => $refund->failure_reason,
            ]);
        }

        return $refund;
    }

    /**
     * Pending refunds that are due to be asked about.
     *
     * Sent with no answer a while ago, or never marked as answered at all long
     * after they were started.
     */
    private function dueForFollowUp(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('unanswered_at', '<=', now()->subMinutes(self::FOLLOW_UP_AFTER_MINUTES))
            ->orWhere(fn (Builder $q) => $q
                ->whereNull('unanswered_at')
                ->where('created_at', '<=', now()->subMinutes(self::STALLED_AFTER_MINUTES))));
    }

    /** @return list<string> */
    private function knownProcessorReferences(Order $order, Refund $except): array
    {
        return Refund::query()
            ->where('order_id', $order->id)
            ->whereKeyNot($except->id)
            ->whereNotNull('gateway_reference')
            ->where('gateway_reference', '<>', '')
            ->pluck('gateway_reference')
            ->all();
    }

    // --- refunds made at the processor -----------------------------------------

    /**
     * A refund somebody made in the processor's dashboard, written down.
     *
     * The money has already gone; this makes the platform agree. Called with
     * the order locked, by whatever matched the processor's notice against
     * our own refunds and found nothing (ProcessorRefunds).
     *
     * When it takes the order to fully refunded, every ticket still working is
     * refunded with it: the buyer has all their money back and the door must
     * stop letting them in. When it does not, no ticket is touched. Choosing
     * which ones would be a guess, and a wrong guess turns away somebody who
     * paid — so the money is recorded, the order shows what is left, and the
     * organizer is told to say which tickets it was for.
     *
     * The ledger is written the way any refund's is, so the organizer's
     * balance stops counting money that went back. Not for an order that was
     * never a sale — a late payment with no room left — whose ledger never
     * held anything.
     *
     * The organizer is told, unless the caller says not to. Only the cutover
     * does (legacy:reconcile --apply, through StripeRefundRecorder): the
     * refunds it writes down went back before the switch, some of them months
     * ago, and one email per refund on the morning of it would be hundreds of
     * messages about nothing that just happened. Quiet means no email
     * and no order.refunded to the organizer's integrations, which never heard
     * of these orders as paid either. Everything else is the same, the audit
     * entry included, and it says the organizer was not told.
     */
    public function recordMadeElsewhere(
        Order $order,
        int $amount,
        ?string $processorReference = null,
        bool $tellTheOrganizer = true,
    ): ?Refund {
        return DB::transaction(function () use ($order, $amount, $processorReference, $tellTheOrganizer) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            $counted = Refund::query()
                ->where('order_id', $locked->id)
                ->whereIn('status', ['pending', 'succeeded'])
                ->get(['amount', 'tax_amount', 'service_charge_amount', 'service_charge_tax_amount']);

            $left = $locked->total_amount - (int) $counted->sum('amount');

            if ($amount <= 0 || $left <= 0) {
                Log::alert('The payment processor reports a refund on an order with nothing left to refund. Nothing was recorded.', [
                    'order' => $locked->reference,
                    'amount' => $amount,
                    'left' => $left,
                    'processor_reference' => $processorReference,
                ]);

                return null;
            }

            if ($amount > $left) {
                Log::alert('The payment processor reports more refunded than was left on the order. Only what was left is recorded.', [
                    'order' => $locked->reference,
                    'amount' => $amount,
                    'left' => $left,
                ]);

                $amount = $left;
            }

            $whole = $amount === $left;
            $wasASale = in_array($locked->status, ['paid', 'partially_refunded'], true);

            // The last refund takes whatever tax and service charge is left,
            // so an order refunded in pieces still nets to exactly zero. One
            // that leaves money on the order takes its proportion, rounded
            // down, and leaves the rounding to whichever refund comes last.
            // The same for the part of the service charge that is tax.
            [$tax, $serviceCharge, $chargeTax] = $whole
                ? [
                    max(0, $locked->tax_amount - (int) $counted->sum('tax_amount')),
                    max(0, $locked->service_charge_amount - (int) $counted->sum('service_charge_amount')),
                    max(0, $locked->service_charge_tax_amount - (int) $counted->sum('service_charge_tax_amount')),
                ]
                : [
                    intdiv($locked->tax_amount * $amount, $locked->total_amount),
                    intdiv($locked->service_charge_amount * $amount, $locked->total_amount),
                    intdiv($locked->service_charge_tax_amount * $amount, $locked->total_amount),
                ];

            $tax = min($tax, $amount);
            $serviceCharge = min($serviceCharge, $amount - $tax);
            $chargeTax = min($chargeTax, $serviceCharge);

            $refund = Refund::create([
                'order_id' => $locked->id,
                'event_id' => $locked->event_id,
                'organization_id' => $locked->organization_id,
                'issued_by' => null,
                'source' => Refund::FROM_PROCESSOR,
                'currency' => $locked->currency,
                'amount' => $amount,
                'tax_amount' => $tax,
                'service_charge_amount' => $serviceCharge,
                'service_charge_tax_amount' => $chargeTax,
                'gateway' => $locked->gateway,
                'gateway_reference' => $processorReference,
                'status' => 'pending',
                'reason' => 'Refunded in the '.$this->processorName($locked).' dashboard',
                'confirmed_at' => now(),
            ]);

            $tickets = $whole && $wasASale ? $this->refundableTickets($locked, null) : collect();

            if ($tickets->isNotEmpty()) {
                $refund->tickets()->attach($tickets->pluck('id')->all());
            }

            $outcome = AttemptOutcome::succeeded((string) $processorReference);

            $settled = $wasASale
                ? $this->settle($locked, $refund, $outcome, announce: $tellTheOrganizer)
                : $this->settleUnfulfilled($locked, $refund, $outcome);

            $this->auditor->record('refund.made_elsewhere', $locked, metadata: [
                'refund_id' => $settled->id,
                'amount' => $settled->amount,
                'currency' => $locked->currency,
                'gateway' => $locked->gateway,
                'processor_reference' => $processorReference,
                'whole_order' => $whole,
                'tickets' => $tickets->count(),
                'organizer_told' => $wasASale && $tellTheOrganizer,
            ]);

            if ($wasASale && $tellTheOrganizer) {
                // After commit, so an organizer is never told about a refund
                // that then rolled back.
                DB::afterCommit(fn () => $this->tellTheOrganizer($locked, $settled, $whole));
            } elseif (! $wasASale) {
                Log::warning('A payment that never became a sale was refunded at the processor, and is recorded.', [
                    'order' => $locked->reference,
                    'refund_id' => $settled->id,
                    'amount' => $settled->amount,
                ]);
            }

            return $settled;
        });
    }

    /**
     * Everybody who can refund on this organization hears about it.
     *
     * The people who would otherwise have done it here, and who now have to
     * know it happened somewhere else — and, when it did not cover the whole
     * order, say which tickets it was for.
     */
    private function tellTheOrganizer(Order $order, Refund $refund, bool $whole): void
    {
        $roles = collect(Role::cases())
            ->filter(fn (Role $role) => in_array(Permission::RefundsProcess, Permission::forRole($role), true))
            ->map(fn (Role $role) => $role->value)
            ->values()
            ->all();

        $organization = $order->organization;

        $emails = $organization?->members()
            ->wherePivotIn('role', $roles)
            ->whereNotNull('users.email')
            ->pluck('users.email')
            ->map(fn (string $email) => strtolower($email))
            ->reject(fn (string $email) => str_ends_with($email, '@erased.invalid'))
            ->unique()
            ->values() ?? collect();

        if ($emails->isEmpty() && filled($organization?->contact_email)) {
            $emails = collect([$organization->contact_email]);
        }

        $emails->each(fn (string $email) => Mail::to($email)->queue(
            new RefundMadeElsewhere($order->fresh(), $refund->fresh(), $whole, $this->processorName($order)),
        ));
    }

    private function processorName(Order $order): string
    {
        return match ($order->gateway) {
            'stripe' => 'Stripe',
            'paystack' => 'Paystack',
            default => 'payment processor',
        };
    }

    // --- the steps ------------------------------------------------------------

    /**
     * Write the refund as pending, under the order's lock.
     *
     * Everything that decides whether a refund is allowed happens here, in one
     * place, while nothing else can be deciding the same thing.
     */
    private function reserve(
        Order $order,
        ?array $ticketIds,
        ?User $issuer,
        ?string $reason,
    ): Refund {
        return DB::transaction(function () use ($order, $ticketIds, $issuer, $reason) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if (! in_array($locked->status, ['paid', 'partially_refunded'], true)) {
                throw RefundRefused::because(
                    'Only a paid order can be refunded. This one is '.$locked->status.'.'
                );
            }

            if ($locked->total_amount === 0) {
                // Nothing was ever charged — a comp, an RSVP, or an order fully
                // covered by a code. There is no money to send back, and saying
                // so is better than a gateway call for zero.
                throw RefundRefused::because(
                    'This order was free, so there is nothing to return.'
                );
            }

            // A ticket already in a refund that has not finished is not
            // refunded again. The cap below would not stop it — two tickets'
            // worth fits inside a two-ticket order however it is split — and
            // a refund that got no answer may well have paid it back already.
            if ($ticketIds !== null && $this->inAnUnfinishedRefund($locked, $ticketIds)) {
                throw RefundRefused::because(
                    'A refund for some of those tickets is already under way. It will show here once the payment processor confirms it.'
                );
            }

            $tickets = $this->refundableTickets($locked, $ticketIds);

            if ($tickets->isEmpty()) {
                throw RefundRefused::because(match (true) {
                    $ticketIds === null && $this->inAnUnfinishedRefund($locked, null) => 'The rest of this order is already being refunded. It will show here once the payment processor confirms it.',
                    $ticketIds === null => 'Every ticket on this order has already been refunded.',
                    default => 'Those tickets are not on this order, or have already been refunded.',
                });
            }

            $share = $this->shareFor($locked, $tickets);

            if ($share['amount'] <= 0) {
                throw RefundRefused::because(
                    'Those tickets were free, so there is nothing to return.'
                );
            }

            // Pending refunds count against the cap as well as succeeded ones.
            // A refund in flight is money the buyer is about to have.
            $alreadyOut = (int) Refund::query()
                ->where('order_id', $locked->id)
                ->whereIn('status', ['pending', 'succeeded'])
                ->sum('amount');

            if ($alreadyOut + $share['amount'] > $locked->total_amount) {
                throw RefundRefused::because(
                    'That is more than is left on this order to refund.'
                );
            }

            $refund = Refund::create([
                'order_id' => $locked->id,
                'event_id' => $locked->event_id,
                'organization_id' => $locked->organization_id,
                'issued_by' => $issuer?->id,
                'currency' => $locked->currency,
                'amount' => $share['amount'],
                'tax_amount' => $share['tax'],
                'service_charge_amount' => $share['service_charge'],
                'service_charge_tax_amount' => $share['service_charge_tax'],
                'gateway' => $locked->gateway,
                'status' => 'pending',
                'reason' => $reason,
            ]);

            $refund->tickets()->attach($tickets->pluck('id')->all());

            return $refund;
        });
    }

    /**
     * The whole order, written as pending under its lock.
     *
     * Only an order that never became tickets. A paid one has them, and is
     * refunded by them; one with a refund already under way is not paid back
     * twice, which is what a payment notice delivered twice would otherwise
     * do.
     */
    private function reserveUnfulfilled(Order $order, ?string $reason): Refund
    {
        return DB::transaction(function () use ($order, $reason) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if (! in_array($locked->status, ['pending', 'cancelled'], true)) {
                throw RefundRefused::because(
                    'Only an order that was never fulfilled can be refunded whole. This one is '.$locked->status.'.'
                );
            }

            if ($locked->total_amount <= 0 || $locked->gateway === null) {
                throw RefundRefused::because(
                    'Nothing was charged through a payment processor for this order, so there is nothing to send back.'
                );
            }

            if ($locked->tickets()->exists()) {
                throw RefundRefused::because('This order has tickets. Refund those instead.');
            }

            if ($locked->refunds()->whereIn('status', ['pending', 'succeeded'])->exists()) {
                throw RefundRefused::because('The money for this order is already on its way back.');
            }

            return Refund::create([
                'order_id' => $locked->id,
                'event_id' => $locked->event_id,
                'organization_id' => $locked->organization_id,
                'issued_by' => null,
                'currency' => $locked->currency,
                'amount' => $locked->total_amount,
                'tax_amount' => $locked->tax_amount,
                'service_charge_amount' => $locked->service_charge_amount,
                'service_charge_tax_amount' => $locked->service_charge_tax_amount,
                'gateway' => $locked->gateway,
                'status' => 'pending',
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Record what the processor said about a refund with no tickets on it.
     *
     * A whole-order refund of a payment that never became tickets, or a
     * refund made in the dashboard for one.
     *
     * Nothing enters the organizer's ledger either way. Fulfilment never
     * wrote a sale for this order, so there is no sale to reverse, and a
     * reversal written anyway would take money off a balance it never
     * reached. The integrations are not told either: they never heard of
     * the order as paid, and "refunded" about an order nobody announced
     * would leave them a row to explain.
     */
    private function settleUnfulfilled(Order $order, Refund $refund, AttemptOutcome $outcome): Refund
    {
        return DB::transaction(function () use ($order, $refund, $outcome) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            $refund = $this->stillPending($refund);

            if ($refund->status !== 'pending') {
                return $refund;
            }

            if ($outcome->unknown) {
                return $this->markUnanswered($refund, $outcome);
            }

            if (! $outcome->succeeded) {
                $refund->update([
                    'status' => 'failed',
                    'failure_reason' => $outcome->failureReason,
                ]);

                return $refund->refresh();
            }

            $refund->update([
                'status' => 'succeeded',
                'gateway_reference' => $outcome->reference !== '' ? $outcome->reference : $refund->gateway_reference,
                'failure_reason' => null,
            ]);

            $refunded = (int) Refund::query()
                ->where('order_id', $locked->id)
                ->where('status', 'succeeded')
                ->sum('amount');

            if ($refunded >= $locked->total_amount && $locked->mayBecome('refunded')) {
                $locked->update([
                    'status' => 'refunded',
                    'refunded_at' => $locked->refunded_at ?? now(),
                ]);
            }

            return $refund->refresh();
        });
    }

    /**
     * Ask the gateway for the money back.
     *
     * The Refund row's id goes as the idempotency key: this refund, and only
     * this one, however many times it is sent.
     *
     * A thrown exception is an answer nobody heard, not a no. The request may
     * have reached the processor and paid the money back before whatever broke
     * broke, so the refund waits to be asked about (followUp) rather than being
     * written off as failed and sent again under a new row.
     */
    private function attempt(Order $order, Refund $refund): AttemptOutcome
    {
        $gateway = $this->gatewayFor($order);

        if (! $gateway) {
            return AttemptOutcome::failed(
                'No payment processor is configured for '.($order->gateway ?? 'this order').'.'
            );
        }

        try {
            $result = $gateway->refund($order, $refund->amount, $refund->reason, $refund->id);
        } catch (Throwable $e) {
            // The detail goes to the log; the organizer gets a sentence. A
            // processor's exception text is not something to put in a console.
            Log::error('Refund threw', [
                'refund_id' => $refund->id,
                'order_id' => $order->id,
                'gateway' => $order->gateway,
                'exception' => $e->getMessage(),
            ]);

            return AttemptOutcome::unknown('The payment processor could not be reached.');
        }

        if ($result->unknown) {
            return AttemptOutcome::unknown(
                $result->failureReason ?: 'The payment processor did not answer.'
            );
        }

        if (! $result->succeeded) {
            Log::warning('Refund refused by gateway', [
                'refund_id' => $refund->id,
                'order_id' => $order->id,
                'reason' => $result->failureReason,
            ]);

            return AttemptOutcome::failed(
                $result->failureReason ?: 'The payment processor refused the refund.'
            );
        }

        return AttemptOutcome::succeeded($result->reference);
    }

    /**
     * Record what happened, and act on it.
     *
     * Tickets are voided and the ledger written only on success. A failed
     * refund must leave the tickets valid — the buyer still holds something
     * they paid for. So must one nobody has heard back about: it stays
     * pending, holding its tickets and its share of the order, until the
     * processor says one way or the other.
     *
     * Settled once. The processor's notice, the answer to our request and a
     * follow-up can all arrive for the same refund, and whichever is second
     * finds it no longer pending and leaves it alone — so tickets are voided
     * and the ledger written exactly once.
     *
     * $announce is false only for a refund recorded quietly at the cutover
     * (recordMadeElsewhere): history, not news, for integrations as for the
     * organizer's inbox.
     */
    private function settle(Order $order, Refund $refund, AttemptOutcome $outcome, bool $announce = true): Refund
    {
        return DB::transaction(function () use ($order, $refund, $outcome, $announce) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            $refund = $this->stillPending($refund);

            if ($refund->status !== 'pending') {
                return $refund;
            }

            if ($outcome->unknown) {
                return $this->markUnanswered($refund, $outcome);
            }

            if (! $outcome->succeeded) {
                $refund->update([
                    'status' => 'failed',
                    'failure_reason' => $outcome->failureReason,
                ]);

                return $refund->refresh();
            }

            $refund->update([
                'status' => 'succeeded',
                'gateway_reference' => $outcome->reference !== '' ? $outcome->reference : $refund->gateway_reference,
                'failure_reason' => null,
            ]);

            Ticket::whereIn('id', $refund->tickets()->pluck('tickets.id'))
                ->update(['status' => 'refunded']);

            $this->writeLedger($locked, $refund);

            $refunded = (int) Refund::query()
                ->where('order_id', $locked->id)
                ->where('status', 'succeeded')
                ->sum('amount');

            if ($refunded > $locked->total_amount) {
                // The cap is meant to make this impossible. If it happens, the
                // buyer has been paid back more than they paid, and somebody
                // needs to know today.
                Log::alert('More has been refunded on an order than it took.', [
                    'order' => $locked->reference,
                    'total' => $locked->total_amount,
                    'refunded' => $refunded,
                ]);
            }

            $locked->update([
                'status' => $refunded >= $locked->total_amount ? 'refunded' : 'partially_refunded',
                // Set on the first refund and left alone. This is when the
                // order started being refunded, which is the date support is
                // asked about; each refund carries its own timestamp.
                'refunded_at' => $locked->refunded_at ?? now(),
            ]);

            // A fully refunded order gives its code's use back.
            Code::recount($locked->code_id);
            Code::recount($locked->access_code_id);

            // Inside the transaction, so a refund that rolls back never
            // announces itself; the delivery itself goes after commit.
            if ($announce) {
                app(Webhooks::class)->emit($locked->organization_id, 'order.refunded', [
                    ...app(Payloads::class)->order($locked->fresh()),
                    'refund' => [
                        'amount' => ['amount' => (int) $refund->amount, 'currency' => $locked->currency],
                        'reason' => $refund->reason,
                        // How many tickets this refund stopped working, which
                        // is what an attendee list elsewhere has to take off.
                        'tickets' => $refund->tickets()->count(),
                    ],
                ]);
            }

            return $refund->refresh();
        });
    }

    /**
     * Settle a refund if it is still waiting, and say whether this call did.
     *
     * Our request's answer, the processor's notice and a follow-up can all
     * turn up for one refund. Only the first to find it pending acts on it;
     * the others see it settled and leave the telling to whoever settled it,
     * so nobody is emailed twice and nothing is audited as done twice.
     *
     * The order is locked before the refund, the same order settle() takes
     * them in, so two of these can only ever queue behind each other.
     *
     * @return array{0: Refund, 1: bool} the refund, and whether this call settled it
     */
    private function settleOnce(Order $order, Refund $refund, AttemptOutcome $outcome, bool $withTickets): array
    {
        return DB::transaction(function () use ($order, $refund, $outcome, $withTickets) {
            Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if ($this->stillPending($refund)->status !== 'pending') {
                return [$refund->refresh(), false];
            }

            $settled = $withTickets
                ? $this->settle($order, $refund, $outcome)
                : $this->settleUnfulfilled($order, $refund, $outcome);

            return [$settled, $settled->status !== 'pending'];
        });
    }

    /** The refund as it is now, locked, so two settlements cannot both act on it. */
    private function stillPending(Refund $refund): Refund
    {
        /** @var Refund $current */
        $current = Refund::query()->whereKey($refund->id)->lockForUpdate()->first();

        return $current;
    }

    /**
     * Sent, and no answer. Left pending, holding its tickets and its money.
     *
     * The processor's detail goes in failure_reason while it waits, where
     * support will look for why a refund has not finished; it is cleared if
     * the refund goes through.
     */
    private function markUnanswered(Refund $refund, AttemptOutcome $outcome): Refund
    {
        $refund->update([
            'unanswered_at' => now(),
            'failure_reason' => $outcome->failureReason,
        ]);

        return $refund->refresh();
    }

    private function auditAction(Refund $refund): string
    {
        return match ($refund->status) {
            'succeeded' => 'refund.processed',
            'failed' => 'refund.failed',
            default => 'refund.unanswered',
        };
    }

    /**
     * "Your money is on its way back", once the processor has said it is.
     *
     * Only for a payment that never became tickets. Anybody refunded for
     * tickets was refunded by an organizer, who tells them.
     */
    private function tellTheBuyerIfDone(Order $order, Refund $refund): void
    {
        if (! $refund->succeeded()
            || $refund->source !== Refund::FROM_PLATFORM
            || $refund->tickets()->exists()
            || blank($order->buyer_email)) {
            return;
        }

        Mail::to($order->buyer_email)->send(new SoldOutWhilePaying($order->fresh(), $refund));
    }

    /**
     * Which tickets this refund is for.
     *
     * A checked-in ticket is refundable. Somebody who came in and was refunded
     * anyway is a decision an organizer is allowed to make — goodwill, a
     * cancelled headliner, a complaint — and refusing it here would only send
     * them to the database.
     *
     * A ticket in a refund still waiting for the processor is not: that
     * refund may already have paid for it.
     */
    private function refundableTickets(Order $order, ?array $ticketIds): Collection
    {
        $query = $order->tickets()
            ->whereNotIn('status', ['refunded', 'void'])
            ->whereNotIn('id', $this->ticketsInUnfinishedRefunds($order));

        if ($ticketIds !== null) {
            $query->whereIn('id', $ticketIds);
        }

        return $query->orderBy('created_at')->orderBy('id')->get();
    }

    /** @param  list<string>|null  $ticketIds  null asks about every ticket on the order */
    private function inAnUnfinishedRefund(Order $order, ?array $ticketIds): bool
    {
        return $this->ticketsInUnfinishedRefunds($order)
            ->when($ticketIds !== null, fn (QueryBuilder $q) => $q->whereIn('refund_tickets.ticket_id', $ticketIds))
            ->exists();
    }

    /** The tickets on this order held by a refund that has not finished. */
    private function ticketsInUnfinishedRefunds(Order $order): QueryBuilder
    {
        return DB::table('refund_tickets')
            ->join('refunds', 'refunds.id', '=', 'refund_tickets.refund_id')
            ->where('refunds.order_id', $order->id)
            ->where('refunds.status', 'pending')
            ->select('refund_tickets.ticket_id');
    }

    /**
     * What those tickets are worth, out of what was actually charged.
     *
     * Not the sum of their prices. An order carries a discount and a tax, and a
     * ticket's share of the money is its share of the whole — so the split is
     * done across every ticket on the order at once and this refund takes the
     * parts belonging to its own. Splitting only the refunded tickets would
     * lose the rounding to whoever is refunded last.
     *
     * @return array{amount: int, tax: int, service_charge: int, service_charge_tax: int}
     */
    private function shareFor(Order $order, Collection $tickets): array
    {
        // Ordered by id as well as time, and it matters. Tickets on one order
        // are written in a single transaction and share a created_at to the
        // microsecond, so time alone leaves the sequence up to the planner.
        // The allocation hands its leftover units out by position — an order
        // that shuffles between two partial refunds would give the same spare
        // penny to both, or to neither.
        $all = $order->tickets()->orderBy('created_at')->orderBy('id')->get();

        // A ticket's weight is what it actually cost: its type's price,
        // snapshotted on the order line, less its share of that line's
        // discount. Comps issued against the same order weigh nothing and so
        // refund nothing, which is correct.
        //
        // The discount has to come off per line. A code can discount General
        // and leave VIP alone, and weighting by list price alone refunded the
        // VIP ticket with some of General's discount taken out of it.
        $lines = $order->lines->keyBy('ticket_type_id');
        $perTicket = [];

        $weights = $all
            ->map(function (Ticket $t) use ($lines, &$perTicket) {
                $line = $lines[$t->ticket_type_id] ?? null;

                if ($line === null) {
                    return 0;
                }

                $perTicket[$line->id] ??= Allocation::split((int) $line->discount_amount, array_fill(0, (int) $line->quantity, 1));
                $share = array_shift($perTicket[$line->id]) ?? 0;

                return max(0, (int) $line->unit_price_amount - $share);
            })
            ->all();

        $wanted = $tickets->pluck('id')->all();
        $positions = $all
            ->keys()
            ->filter(fn (int $i) => in_array($all[$i]->id, $wanted, true))
            ->all();

        $take = function (int $total) use ($weights, $positions): int {
            $parts = Allocation::split($total, $weights);

            return array_sum(array_map(fn (int $i) => $parts[$i], $positions));
        };

        // The service charge in its two parts, the tax inside it and the rest,
        // each split on its own. Splitting the whole and the tax separately
        // could hand one ticket more of the tax than of the charge it sits in
        // — largest remainder is not monotone — and this way each part still
        // comes back to exactly the order's over a full refund. With no tax
        // on the charge it is the same split as the whole.
        $chargeTax = $take((int) $order->service_charge_tax_amount);

        return [
            'amount' => $take($order->total_amount),
            'tax' => $take($order->tax_amount),
            'service_charge' => $take($order->service_charge_amount - (int) $order->service_charge_tax_amount) + $chargeTax,
            'service_charge_tax' => $chargeTax,
        ];
    }

    /**
     * The reversal, written the way the sale was written.
     *
     * Two entries, because two things leave the organizer's balance. The refund
     * entry is their own money going back. The tax entry returns what was being
     * held for a tax authority on a sale that has now partly unhappened.
     *
     * The service charge is in neither, and that is the point. The buyer gets
     * it back — it is part of `$refund->amount`, which is what the gateway
     * sends — but it was the platform's revenue, never the organizer's, so it
     * cannot leave a balance it never entered. Subtracting it here would charge
     * the organizer for refunding a fee somebody else collected.
     *
     * Written this way, a fully refunded order nets the organizer to exactly
     * zero. The platform is out the gateway fee on both legs, which no
     * processor returns and which is therefore a real cost of a refund rather
     * than an accounting entry.
     */
    private function writeLedger(Order $order, Refund $refund): void
    {
        $common = [
            'organization_id' => $order->organization_id,
            'event_id' => $order->event_id,
            'order_id' => $order->id,
            'currency' => $order->currency,
            'occurred_at' => now(),
        ];

        $note = $refund->source === Refund::FROM_PROCESSOR
            ? "Refund for order {$order->reference}, made in the {$this->processorName($order)} dashboard"
            : "Refund for order {$order->reference}";

        // The gross ticket side going back, mirroring the sale entry exactly:
        // what the buyer paid less the service charge, which is the platform's
        // and never entered this balance.
        //
        // The tax is left in and credited back below rather than netted off
        // here. Both are needed, and doing only one of them returns the tax
        // twice — which is what a fully refunded order looked like until it
        // was made to sum to zero.
        LedgerEntry::create($common + [
            'type' => 'refund',
            'amount' => -($refund->amount - $refund->service_charge_amount),
            'reason' => $note,
        ]);

        if ($refund->tax_amount > 0) {
            LedgerEntry::create($common + [
                'type' => 'tax',
                'amount' => $refund->tax_amount,
                'reason' => $note,
            ]);
        }

    }

    /**
     * The same processor that took the money, never a re-route by currency.
     *
     * A refund goes back down the path the payment came up. Choosing by
     * currency would send a Stripe charge's refund to Paystack the day Paystack
     * starts supporting CAD.
     */
    private function gatewayFor(Order $order): ?PaymentGateway
    {
        try {
            return $this->gateways->named((string) $order->gateway);
        } catch (Throwable) {
            return null;
        }
    }
}
