<?php

namespace App\Services\Payouts;

use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\PayoutRequest;
use App\Models\Repayment;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Money myFiesta is owed back by an organizer, and how it comes back.
 *
 * An overdraft is given in one place — staff paying a payout request for more
 * than is owed (PayoutRequests::pay) — and from then on it is only the ledger
 * balance below zero. Nothing here keeps a separate running total that could
 * disagree with it:
 *
 * - The next sales pay it back on their own. A sale is a credit like any
 *   other, and it goes to the balance, which is below zero until the advance
 *   is covered. Nothing can be paid out in the meantime: asking to be paid
 *   needs a balance above zero.
 * - Money sent to us outside the platform is recorded as a repayment: its own
 *   record and a ledger credit of its own type, up to what is outstanding.
 * - The story — how much was advanced and when, how much sales have paid back
 *   since, what was repaid, what is left — is worked out from the advance on
 *   the payout request (or, for one paid before requests kept it, from its
 *   payout) and the balance now, every time it is asked.
 *
 * Per currency, always. An organization that owes in naira is owed its
 * dollars as usual; the two are never netted against each other.
 */
class Overdrafts
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * Where this organization stands in this currency, or null when there is
     * nothing to say: the balance is not below zero and no advance is still
     * being told.
     */
    public function position(Organization|string $organization, string $currency): ?OverdraftPosition
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;
        $currency = strtoupper($currency);

        $balance = (int) LedgerEntry::query()
            ->where('organization_id', $id)
            ->where('currency', $currency)
            ->sum('amount');

        $advance = $this->currentAdvance($id, $currency);

        // The latest payout, when no request tells of an advance: where a
        // shortfall is counted from, or itself an advance whose figures were
        // never written down (see unrecordedAdvance).
        $last = $advance === null ? $this->lastPayout($id, $currency) : null;
        $unrecorded = $last !== null ? $this->unrecordedAdvance($last) : 0;
        $payout = $unrecorded > 0 ? $last : null;

        if ($balance >= 0 && $advance === null && $payout === null) {
            return null;
        }

        $zero = Money::zero($currency);
        $outstanding = max(0, -$balance);

        if ($advance !== null || $payout !== null) {
            $since = $advance !== null
                ? CarbonImmutable::instance($advance->approved_at)
                : CarbonImmutable::instance($payout->settled_at ?? $payout->created_at);
            $advanced = $advance !== null ? (int) $advance->overdraft_amount : $unrecorded;
            $repaid = $this->repaidSince($id, $currency, $since);

            /*
             * What has come in since, other than repayments.
             *
             * Straight after the advance the balance was minus the advance;
             * everything since is either a repayment or ordinary trade — sales
             * less refunds. That difference is what sales have paid back, up
             * to what was left to pay, or what refunds have added when it is
             * below nothing.
             */
            $earned = $balance + $advanced - $repaid;

            return new OverdraftPosition(
                organizationId: $id,
                currency: $currency,
                balance: new Money($balance, $currency),
                outstanding: new Money($outstanding, $currency),
                advance: $advance,
                advanced: new Money($advanced, $currency),
                recovered: new Money(max(0, min($earned, max(0, $advanced - $repaid))), $currency),
                repaid: new Money($repaid, $currency),
                added: new Money(max(0, -$earned), $currency),
                since: $since,
                payout: $payout,
            );
        }

        /*
         * Below zero with no advance behind it.
         *
         * Refunds and chargebacks after the money had already been paid out.
         * Recovered from the next sales the same way; counted from the last
         * payout, which is the moment the balance was last made to add up.
         */
        $since = $last ? CarbonImmutable::instance($last->settled_at ?? $last->created_at) : null;
        $repaid = $this->repaidSince($id, $currency, $since);

        return new OverdraftPosition(
            organizationId: $id,
            currency: $currency,
            balance: new Money($balance, $currency),
            outstanding: new Money($outstanding, $currency),
            advance: null,
            advanced: $zero,
            recovered: $zero,
            repaid: new Money($repaid, $currency),
            added: new Money($outstanding + $repaid, $currency),
            since: $since,
        );
    }

    /**
     * Every currency this organization has something to say about.
     *
     * @return array<string, OverdraftPosition>
     */
    public function positionsFor(Organization|string $organization): array
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;

        $currencies = LedgerEntry::query()
            ->where('organization_id', $id)
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency');

        $positions = [];

        foreach ($currencies as $currency) {
            if ($position = $this->position($id, (string) $currency)) {
                $positions[(string) $currency] = $position;
            }
        }

        return $positions;
    }

    /**
     * Every organization and currency that owes myFiesta money, the longest
     * owed first.
     *
     * Found from the balances, so a shortfall nobody advanced — refunds after
     * a payout — is on the list too: it is recovered the same way and is
     * money owed all the same.
     *
     * @return list<OverdraftPosition>
     */
    public function outstanding(): array
    {
        $rows = DB::table('ledger_entries')
            ->groupBy('organization_id', 'currency')
            ->havingRaw('sum(amount) < 0')
            ->select('organization_id', 'currency')
            ->get();

        $positions = [];

        foreach ($rows as $row) {
            $position = $this->position((string) $row->organization_id, (string) $row->currency);

            if ($position?->isOutstanding()) {
                $positions[] = $position;
            }
        }

        usort($positions, fn (OverdraftPosition $a, OverdraftPosition $b) => ($a->since?->getTimestamp() ?? 0) <=> ($b->since?->getTimestamp() ?? 0));

        return $positions;
    }

    /** How many organization and currency pairs are below zero: the admin's badge. */
    public function outstandingCount(): int
    {
        return DB::query()
            ->fromSub(
                DB::table('ledger_entries')
                    ->groupBy('organization_id', 'currency')
                    ->havingRaw('sum(amount) < 0')
                    ->select('organization_id', 'currency'),
                'owing',
            )
            ->count();
    }

    /**
     * Why this organization cannot be closed, if it cannot.
     *
     * Closing it while it owes money back would leave the advance recorded
     * against nobody who can repay it, and its sales — the way most advances
     * come back — would stop.
     */
    public function refusalToClose(Organization $organization): ?string
    {
        $owed = array_filter(
            $this->positionsFor($organization),
            fn (OverdraftPosition $position) => $position->isOutstanding(),
        );

        if ($owed === []) {
            return null;
        }

        $zone = self::zoneOf($organization->id);

        return $organization->name.' owes myFiesta money, so it cannot be closed. '
            .implode(' ', array_map(fn (OverdraftPosition $p) => $p->currency.': '.$p->summary($zone), $owed))
            .' Close it once that has been recovered from its sales or repaid.';
    }

    /**
     * Record money the organization paid back to myFiesta outside the platform.
     *
     * Asserts something about the real world — that the money is in our
     * account — so it is for the people who record payouts, needs the
     * reference it arrived with and a reason, and cannot be more than is
     * owed: a repayment beyond that would turn into a balance we then owe
     * back, which is a payout nobody decided to make.
     *
     * @throws RepaymentRefused
     */
    public function recordRepayment(Organization $organization, User $by, Money $amount, string $reference, string $reason): Repayment
    {
        if (! $by->platform_role?->canSettle()) {
            throw RepaymentRefused::because('Only platform administrators and finance can record a repayment.');
        }

        if ($amount->amount <= 0) {
            throw RepaymentRefused::because('A repayment has to be more than zero.');
        }

        if (trim($reference) === '') {
            throw RepaymentRefused::because('Give the reference the money arrived with, so it can be matched to the bank statement.');
        }

        if (trim($reason) === '') {
            throw RepaymentRefused::because('Say why this is being recorded: who paid it, and how.');
        }

        return DB::transaction(function () use ($organization, $by, $amount, $reference, $reason) {
            // Serialised with payouts for this organization, so the amount
            // outstanding read here is the one the repayment is checked against.
            Organization::withTrashed()->whereKey($organization->id)->lockForUpdate()->first();

            /*
             * One transfer, one record.
             *
             * Two people recording the same transfer would credit money that
             * arrived once, and the sales that should have paid the rest back
             * would be paid out instead. The reference is what tells two
             * transfers apart, so it is not taken twice; the database holds
             * to it too (repayments_one_per_reference). Asked before the
             * amount: the second try at a transfer that cleared the debt would
             * otherwise only hear that nothing is outstanding.
             */
            $earlier = $this->repaymentWithReference($organization->id, $amount->currency, $reference);

            if ($earlier !== null) {
                throw RepaymentRefused::because(self::alreadyRecorded($earlier));
            }

            $balance = (int) LedgerEntry::query()
                ->where('organization_id', $organization->id)
                ->where('currency', $amount->currency)
                ->sum('amount');

            $outstanding = new Money(max(0, -$balance), $amount->currency);

            if ($outstanding->isZero()) {
                throw RepaymentRefused::because('Nothing is outstanding in '.$amount->currency.', so there is nothing to repay.');
            }

            if ($amount->amount > $outstanding->amount) {
                throw RepaymentRefused::because('That is more than is outstanding ('.$outstanding->format().'). Record up to that amount.');
            }

            $entry = LedgerEntry::create([
                'organization_id' => $organization->id,
                'type' => 'repayment',
                'amount' => $amount->amount,
                'currency' => $amount->currency,
                'reason' => 'Repayment, reference '.trim($reference),
                'occurred_at' => now(),
            ]);

            try {
                $repayment = Repayment::create([
                    'organization_id' => $organization->id,
                    'currency' => $amount->currency,
                    'amount' => $amount->amount,
                    'reference' => trim($reference),
                    'reason' => trim($reason),
                    'recorded_by' => $by->id,
                    'ledger_entry_id' => $entry->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Not reachable past the check above while the organization
                // is held; this answers a record written some other way. The
                // ledger credit is rolled back with it.
                throw RepaymentRefused::because('That reference has just been recorded as a repayment. A transfer is recorded once.');
            }

            $this->auditor->record('overdraft.repaid', $repayment, $by, $organization->id, [
                'amount' => $amount->amount,
                'currency' => $amount->currency,
                'reference' => trim($reference),
                'reason' => trim($reason),
                'outstanding_amount' => $outstanding->amount,
                'remaining_amount' => $outstanding->amount - $amount->amount,
            ]);

            return $repayment;
        });
    }

    /**
     * The repayment already recorded under this reference, if one is.
     *
     * Compared as the bank prints it, give or take case and the spaces
     * around it: two people typing one reference do not type it the same.
     */
    private function repaymentWithReference(string $organizationId, string $currency, string $reference): ?Repayment
    {
        return Repayment::query()
            ->where('organization_id', $organizationId)
            ->where('currency', $currency)
            ->whereRaw('lower(trim(reference)) = lower(?)', [trim($reference)])
            ->with('recorder:id,name')
            ->first();
    }

    /** Which record already has it, so the person can check it against the statement. */
    private static function alreadyRecorded(Repayment $earlier): string
    {
        return $earlier->reference.' is already recorded: a repayment of '.$earlier->money->format()
            .' on '.$earlier->created_at?->copy()->utc()->format('j M Y').' (UTC), by '.($earlier->recorder->name ?? 'a former member of staff').'. '
            .'A transfer is recorded once. If this is a second transfer that arrived with the same reference, record it as '.$earlier->reference.'/2.';
    }

    /**
     * The zone most of the organization's nights are in, for dating a summary.
     *
     * An organization has no zone of its own; the one most of its events
     * share is where its people read the date.
     */
    public static function zoneOf(string $organizationId): string
    {
        return (string) (DB::table('events')
            ->where('organization_id', $organizationId)
            ->select('timezone', DB::raw('count(*) as n'))
            ->groupBy('timezone')
            ->orderByDesc('n')
            ->value('timezone') ?? config('app.timezone'));
    }

    /**
     * The latest advance in this currency, while it is still the story.
     *
     * Only one can be running: nobody may ask to be paid until the balance is
     * above zero again. And once a payout from the balance has followed it,
     * that advance was covered — money is only paid from a balance that has
     * it — so whatever is owed after that is a new shortfall, not the advance.
     */
    private function currentAdvance(string $organizationId, string $currency): ?PayoutRequest
    {
        $advance = PayoutRequest::query()
            ->where('organization_id', $organizationId)
            ->where('currency', $currency)
            ->where('status', 'paid')
            ->whereNotNull('overdraft_amount')
            ->with('approver:id,name')
            ->latest('approved_at')
            ->first();

        if ($advance === null || $advance->approved_at === null) {
            return null;
        }

        $paidSince = Settlement::query()
            ->where('organization_id', $organizationId)
            ->where('currency', $currency)
            ->where('status', 'success')
            ->where('type', '!=', 'overdraft')
            ->where('created_at', '>', $advance->approved_at)
            ->exists();

        return $paidSince ? null : $advance;
    }

    private function lastPayout(string $organizationId, string $currency): ?Settlement
    {
        return Settlement::query()
            ->where('organization_id', $organizationId)
            ->where('currency', $currency)
            ->where('status', 'success')
            ->with('settledBy:id,name')
            ->latest('created_at')
            ->first();
    }

    /**
     * How much more than was owed a payout paid, when nothing else says.
     *
     * Before requests kept how much of a payment was advanced, an overdraft
     * lived only in its settlement: the type and a note, paid from the
     * admin's settlement form or on a request. When the latest payout is one
     * of those it is the story, and telling it as refunds would give the
     * organizer a cause that is not true. So the advance is worked out the
     * way it was decided — the payout less the balance just before it —
     * reading the ledger in the order it was written: by the second, and
     * within one by id, which is ordered by time as well.
     *
     * Zero for any other payout, or when nothing on the ledger says what it
     * was measured against.
     */
    private function unrecordedAdvance(Settlement $payout): int
    {
        if ($payout->type !== 'overdraft') {
            return 0;
        }

        $entry = LedgerEntry::query()
            ->where('organization_id', $payout->organization_id)
            ->where('currency', $payout->currency)
            ->where('type', 'settlement')
            ->where('reason', 'Settlement '.$payout->id)
            ->value('id');

        if ($entry === null) {
            return 0;
        }

        $before = (int) LedgerEntry::query()
            ->where('organization_id', $payout->organization_id)
            ->where('currency', $payout->currency)
            ->whereRaw('(created_at, id) < (SELECT created_at, id FROM ledger_entries WHERE id = ?)', [$entry])
            ->sum('amount');

        return max(0, (int) $payout->amount - $before);
    }

    private function repaidSince(string $organizationId, string $currency, ?CarbonImmutable $since): int
    {
        return (int) Repayment::query()
            ->where('organization_id', $organizationId)
            ->where('currency', $currency)
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
            ->sum('amount');
    }
}
