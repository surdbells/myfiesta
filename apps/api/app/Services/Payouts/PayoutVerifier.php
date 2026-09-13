<?php

namespace App\Services\Payouts;

use App\Models\OrganizationPayoutDetail;
use App\Models\SensitiveDataAccess;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;

/**
 * Confirming that a payout destination is the organizer's own.
 *
 * The whole defence against the fraud manual settlement invites: somebody
 * gets into an organizer's account, changes the bank details the week before
 * a big payout, and the money goes where they said. Changing the details
 * clears a verification (PayoutController); this is the only thing that sets
 * one, and it records who did it and on what evidence.
 *
 * Out here rather than in an admin action for the same reason as
 * SettlementRecorder: the rules are the part worth testing, and inside a
 * Filament closure they could only be reached by driving the panel.
 */
class PayoutVerifier
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * The details in the clear, for somebody entitled to them, logged.
     *
     * @return array<string, string|null>
     */
    public function reveal(OrganizationPayoutDetail $detail, User $by, ?string $ip = null): array
    {
        $this->assertMayRead($by);

        SensitiveDataAccess::record($by, OrganizationPayoutDetail::class, $detail->id, 'viewed', $ip);

        $this->auditor->record('payout_details.revealed', $detail->organization, $by, $detail->organization_id, [
            'payout_detail_id' => $detail->id,
        ]);

        return collect(OrganizationPayoutDetail::DESTINATION_FIELDS)
            ->mapWithKeys(fn (string $field) => [$field => $detail->getAttribute($field)])
            ->all();
    }

    /**
     * @param  string  $seen  the fingerprint of the details as they were when opened
     *
     * @throws PayoutVerificationRefused
     */
    public function verify(
        OrganizationPayoutDetail $detail,
        User $by,
        string $method,
        ?string $note,
        string $seen,
    ): OrganizationPayoutDetail {
        $this->assertMayRead($by);

        if (! array_key_exists($method, OrganizationPayoutDetail::VERIFICATION_METHODS)) {
            throw PayoutVerificationRefused::because('Choose how these details were confirmed.');
        }

        if ($method === 'interac_test_transfer' && $detail->rail !== 'interac') {
            throw PayoutVerificationRefused::because('An Interac test transfer cannot confirm a bank account.');
        }

        /*
         * Not somebody from the organization being paid.
         *
         * Platform staff can also run events. Verifying the account your own
         * organization is paid into is marking your own homework, and it is
         * the one case where a second person is the whole control.
         */
        if ($by->organizations()->whereKey($detail->organization_id)->exists()) {
            throw PayoutVerificationRefused::because(
                'You are a member of this organization, so somebody else has to verify where it is paid.'
            );
        }

        return DB::transaction(function () use ($detail, $by, $method, $note, $seen) {
            // Locked and re-read, so the comparison is against what is stored
            // now, not what this request loaded a moment ago.
            $current = OrganizationPayoutDetail::query()->lockForUpdate()->findOrFail($detail->id);

            if (! hash_equals($current->fingerprint(), $seen)) {
                throw PayoutVerificationRefused::because(
                    'These details changed after you opened them. Look at them again before verifying.'
                );
            }

            if ($current->isVerified()) {
                throw PayoutVerificationRefused::because('These details are already verified.');
            }

            $current->forceFill([
                'verified_at' => now(),
                'verified_by' => $by->id,
                'verification_method' => $method,
                'verification_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ])->save();

            $this->auditor->record('payout_details.verified', $current->organization, $by, $current->organization_id, [
                'payout_detail_id' => $current->id,
                'rail' => $current->rail,
                'currency' => $current->currency,
                'last_four' => $current->account_last_four,
                'method' => $method,
            ]);

            return $current;
        });
    }

    /** @throws PayoutVerificationRefused */
    public function revoke(OrganizationPayoutDetail $detail, User $by, string $reason): OrganizationPayoutDetail
    {
        $this->assertMayRead($by);

        if (trim($reason) === '') {
            throw PayoutVerificationRefused::because('Say why the verification is being withdrawn.');
        }

        $detail->forceFill([
            'verified_at' => null,
            'verified_by' => null,
            'verification_method' => null,
            'verification_note' => null,
        ])->save();

        $this->auditor->record('payout_details.verification_revoked', $detail->organization, $by, $detail->organization_id, [
            'payout_detail_id' => $detail->id,
            'reason' => trim($reason),
        ]);

        return $detail;
    }

    private function assertMayRead(User $by): void
    {
        if (! ($by->platform_role?->canReadSensitiveData() ?? false)) {
            throw PayoutVerificationRefused::because('Only administrators and finance can see or verify payout details.');
        }
    }
}
