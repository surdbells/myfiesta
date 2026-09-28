<?php

namespace App\Services\Payouts;

use App\Models\PayoutRequest;
use App\Models\Settlement;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Where an organization stands with myFiesta in one currency, when it owes
 * money back or has just finished paying it.
 *
 * Every figure is read from the ledger and the records beside it, never
 * stored, so there is nothing here to fall out of step with the balance. The
 * parts always add up:
 *
 *     advanced − repaid − recovered + added = outstanding
 *
 * where recovered is what sales since have paid back, and added is what
 * refunds since have put on top.
 */
final readonly class OverdraftPosition
{
    public function __construct(
        public string $organizationId,
        public string $currency,
        /** The ledger balance now, signed. */
        public Money $balance,
        /** What is owed to myFiesta: the balance below zero, or nothing. */
        public Money $outstanding,
        /** The payout that advanced money, while it is still the story. */
        public ?PayoutRequest $advance,
        /** How much it advanced. Zero when refunds, not an advance, took the balance below zero. */
        public Money $advanced,
        /** Paid back by sales since. */
        public Money $recovered,
        /** Paid back by transfers to us since (see Repayment). */
        public Money $repaid,
        /** Put on top by refunds and chargebacks since. */
        public Money $added,
        /** When this started: the advance, or the last payout before refunds overtook sales. */
        public ?CarbonImmutable $since,
        /**
         * An advance paid before requests kept their own figures: the payout
         * itself, the only record of it. Null for every other position.
         */
        public ?Settlement $payout = null,
    ) {}

    public function isOutstanding(): bool
    {
        return $this->outstanding->amount > 0;
    }

    public function isAdvance(): bool
    {
        return $this->advance !== null || $this->payout !== null;
    }

    /**
     * Who decided it, as staff read it; null when nothing was advanced.
     *
     * An advance paid with no approval written down — from the settlement
     * form, before advances were given only on requests — names who recorded
     * the payment and says so, rather than inventing an approval.
     */
    public function decidedBy(): ?string
    {
        // Approved when the request says when; the name may have gone since.
        $request = $this->advance ?? $this->payout?->payoutRequest;

        return match (true) {
            $request?->approved_at !== null => 'Approved by '.($request->approver->name ?? 'a former member of staff'),
            $this->payout !== null => 'Paid by '.($this->payout->settledBy->name ?? 'a former member of staff').'; no approval on record',
            default => null,
        };
    }

    /** Why it was advanced, as written at the time. */
    public function reason(): ?string
    {
        return $this->advance->overdraft_reason ?? $this->payout?->note;
    }

    /**
     * The whole position in one sentence, for a statement or a refusal.
     *
     * Neutral about who reads it — the organizer's statement, the admin's
     * organization page and the email all say the same thing — and dated in
     * the zone given, which for an organizer is where their nights are.
     */
    public function summary(string $zone = 'UTC'): string
    {
        $day = $this->since?->setTimezone($zone)->format('j M Y');

        if ($this->isAdvance()) {
            $parts = ['myFiesta advanced '.$this->advanced->format().' on '.$day];

            if ($this->recovered->amount > 0) {
                $parts[] = $this->recovered->format().' recovered from sales since';
            } elseif ($this->isOutstanding()) {
                $parts[] = 'nothing recovered from sales yet';
            }
        } else {
            // A shortfall with no advance behind it: refunds and chargebacks
            // after money had already been paid out. With no payout on record
            // either — balances carried over from the old platform — nothing
            // here can say what caused it, so it does not guess.
            $shortfall = $this->outstanding->plus($this->repaid);

            $parts = [$day !== null
                ? 'Refunds and chargebacks since the last payout on '.$day.' came to '.$shortfall->format().' more than sales'
                : 'The balance went '.$shortfall->format().' below zero, with no advance on record'];
        }

        if ($this->repaid->amount > 0) {
            $parts[] = $this->repaid->format().' repaid';
        }

        if ($this->isAdvance() && $this->added->amount > 0) {
            $parts[] = 'refunds since added '.$this->added->format();
        }

        $parts[] = $this->isOutstanding() ? $this->outstanding->format().' outstanding' : 'nothing outstanding';

        return implode('; ', $parts).'.';
    }
}
