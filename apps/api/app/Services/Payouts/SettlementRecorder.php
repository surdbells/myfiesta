<?php

namespace App\Services\Payouts;

use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Audit\Auditor;
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
     * @throws SettlementRefused when the payout cannot be recorded as asked
     */
    public function record(
        Organization $organization,
        Money $amount,
        string $rail,
        ?string $note = null,
        ?User $by = null,
    ): Settlement {
        if ($amount->amount <= 0) {
            // Returning money is a refund against an order, not a negative
            // settlement. Allowing one here would put an entry in the ledger
            // that increases a balance and calls itself a payout.
            throw SettlementRefused::because('A settlement has to be a positive amount.');
        }

        $balance = LedgerEntry::balancesFor($organization)[$amount->currency]
            ?? Money::zero($amount->currency);

        $type = Settlement::classify($amount, $balance);

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

        // One transaction, because the settlement and its ledger entry are the
        // same fact. Either alone leaves the balance disagreeing with the
        // payout history, and the ledger is append-only — there is no tidying
        // it up afterwards.
        return DB::transaction(function () use ($organization, $amount, $type, $rail, $note, $by) {
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
            ]);

            return $settlement;
        });
    }
}
