<?php

namespace App\Services\Payouts;

use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\OrganizationPayoutDetail;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Organizations\Suspension;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Recording that money left the building.
 *
 * This is the narrowest operation on the platform. Everything else moves
 * numbers between rows; this one is the record of a real transfer to a real
 * bank, made by a person, and it is the hardest thing here to walk back.
 *
 * It lived inside a closure in a Filament table definition, which meant the
 * rules below — how an amount is classified against the balance, that an
 * overdraft must carry a reason, that the settlement and its ledger entry are
 * one fact — could only be exercised by driving an admin table through
 * Livewire. They are the rules most worth testing on this platform and they
 * were the least reachable.
 *
 * Out here they are callable from anywhere: the admin panel today, and
 * whatever records a settlement next.
 */
class SettlementRecorder
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  bool  $overdraftApproved  Set only by PayoutRequests, when an administrator
     *                                   pays a request for more than is owed. Nothing else
     *                                   may record an overdraft.
     *
     * @throws SettlementRefused when the payout cannot be recorded as asked
     */
    public function record(
        Organization $organization,
        Money $amount,
        string $rail,
        ?string $note = null,
        ?User $by = null,
        bool $overdraftApproved = false,
    ): Settlement {
        if ($amount->amount <= 0) {
            // Returning money is a refund against an order, not a negative
            // settlement. Allowing one here would put an entry in the ledger
            // that increases a balance and calls itself a payout.
            throw SettlementRefused::because('A settlement has to be a positive amount.');
        }

        /*
         * A suspended organization's payouts are frozen.
         *
         * Refused rather than recorded with a reason, unlike unverified
         * details below: a suspension exists to stop money reaching the
         * organization, and the screens that send it say so before anybody
         * does. The rare payment that went out anyway is recorded by lifting
         * the suspension first, which puts that decision in the trail too.
         */
        if (Suspension::inForce($organization)) {
            throw SettlementRefused::because(
                'This organization is suspended, so its payouts are frozen. Do not send money while it is suspended; '
                .'if a payment has already gone out, lift the suspension to record it.'
            );
        }

        $balance = LedgerEntry::balancesFor($organization)[$amount->currency]
            ?? Money::zero($amount->currency);

        $type = Settlement::classify($amount, $balance);

        /*
         * Paying more than is owed is a decision about one organizer's request.
         *
         * An overdraft is money the platform advances before it has been
         * earned. It used to be possible from any settlement, by anybody who
         * could settle. It is now given only in answer to an organizer asking
         * to be paid, by an administrator, where the request, the balance and
         * the reason sit side by side.
         */
        if ($type === 'overdraft' && ! $overdraftApproved) {
            throw SettlementRefused::because(
                'That is more than this organization is owed ('.$balance->format().'). '
                .'Paying more than is owed is only possible when an administrator pays an organizer’s payout request.'
            );
        }

        /*
         * Paying more than is owed needs a reason on the record.
         *
         * It is a legitimate thing to do — an advance before a weekend, a
         * goodwill payment, a correction — and every one of those is a
         * decision somebody will be asked about later. The database enforces
         * this too; refusing here is what turns a constraint violation into a
         * sentence an operator can act on.
         */
        if ($type === 'overdraft' && trim((string) $note) === '') {
            throw SettlementRefused::because(
                'This pays more than is owed, so it needs a reason on the record.'
            );
        }

        /*
         * A manual payout to details nobody verified needs a reason too.
         *
         * Not refused: by the time this is recorded the money has moved, and
         * refusing the record of a real transfer only leaves the ledger
         * disagreeing with the bank. The place to stop it is before sending,
         * where the admin screens show whether the details are verified. This
         * makes skipping that a stated decision, flagged in the audit trail.
         * Stripe and Paystack pay to accounts the processor verified.
         */
        $destination = null;

        if (in_array($rail, ['interac', 'bank_transfer'], true)) {
            $destination = OrganizationPayoutDetail::query()
                ->where('organization_id', $organization->id)
                ->where('currency', $amount->currency)
                ->where('rail', $rail)
                ->first();

            if (! $destination?->isVerified() && trim((string) $note) === '') {
                throw SettlementRefused::because(
                    'The '.($rail === 'interac' ? 'Interac' : 'bank').' details for this organization are not verified. '
                    .'Verify them before sending money, or say on the record why this payout went ahead.'
                );
            }
        }

        // One transaction, because the settlement and its ledger entry are the
        // same fact. Either alone leaves the balance disagreeing with the
        // payout history, and the ledger is append-only — there is no tidying
        // it up afterwards.
        return DB::transaction(function () use ($organization, $amount, $type, $rail, $note, $by, $destination) {
            $settlement = Settlement::create([
                'organization_id' => $organization->id,
                'amount' => $amount->amount,
                'currency' => $amount->currency,
                'rail' => $rail,
                'type' => $type,
                'note' => $note ?: null,
                'status' => 'success',
                'settled_by' => $by?->id,
                'settled_at' => now(),
            ]);

            LedgerEntry::create([
                'organization_id' => $organization->id,
                'type' => 'settlement',
                'amount' => -$amount->amount,
                'currency' => $amount->currency,
                'reason' => 'Settlement '.$settlement->id,
                'occurred_at' => now(),
            ]);

            // Money leaving the platform is the single most important thing to
            // be able to attribute afterwards.
            $this->auditor->record('settlement.recorded', $settlement, $by, $organization->id, [
                'amount' => $amount->amount,
                'currency' => $amount->currency,
                'type' => $type,
                'rail' => $rail,
                // Which destination it was meant for, and whether anybody had
                // confirmed it — the first two questions when a payout goes astray.
                'payout_detail_id' => $destination?->id,
                'last_four' => $destination?->account_last_four,
                'destination_verified' => in_array($rail, ['interac', 'bank_transfer'], true)
                    ? ($destination?->isVerified() ?? false)
                    : null,
            ]);

            return $settlement;
        });
    }
}
