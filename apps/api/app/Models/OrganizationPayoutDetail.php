<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * Where an organization gets paid. Encrypted at rest.
 *
 * Adopting Stripe Connect and Paystack split payments would have deleted this
 * table by moving the liability to the processor. Settlement stays manual, so
 * the data stays, and encryption stops being optional.
 *
 * Encryption defends against a leaked dump. It does nothing about an authorised
 * person browsing, which is what SensitiveDataAccess is for.
 */
class OrganizationPayoutDetail extends Model
{
    use HasUuids;

    /**
     * Everything that decides where the money lands.
     *
     * A change to any of these clears a verification, and they are what the
     * fingerprint covers. bank_name is included although it routes nothing:
     * a verification is of what the organizer told us, and a destination that
     * reads differently from the one that was checked has not been checked.
     */
    public const DESTINATION_FIELDS = [
        'rail', 'currency', 'interac_email', 'bank_name', 'account_name',
        'account_number', 'transit_number', 'institution_number', 'bank_code',
    ];

    /** How staff may confirm a destination. Mirrors the check constraint. */
    public const VERIFICATION_METHODS = [
        'test_deposit' => 'A small test deposit, confirmed by the organizer',
        'interac_test_transfer' => 'An Interac test transfer, accepted by the organizer',
        'confirmed_by_phone' => 'Confirmed by phone, on a number already on file',
        'bank_document' => 'A void cheque or bank letter matching these details',
    ];

    protected $guarded = ['id'];

    protected $hidden = [
        'interac_email', 'bank_name', 'account_name', 'account_number',
        'transit_number', 'institution_number', 'bank_code',
    ];

    protected function casts(): array
    {
        return [
            'interac_email' => 'encrypted',
            'bank_name' => 'encrypted',
            'account_name' => 'encrypted',
            'account_number' => 'encrypted',
            'transit_number' => 'encrypted',
            'institution_number' => 'encrypted',
            'bank_code' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Keep a plain last-four alongside the ciphertext.
     *
     * Lets an admin confirm which account they are looking at without
     * decrypting the record, which keeps routine work off the audit log.
     *
     * Encrypted as a string, the way the column's cast reads it. This used
     * encrypt(), which serializes first, so every number read back as
     * `s:10:"0123456789";` — see the migration that repaired them.
     */
    public function setAccountNumberAttribute(?string $value): void
    {
        $this->attributes['account_number'] = $value === null ? null : Crypt::encryptString($value);

        $digits = $value === null ? null : preg_replace('/[^0-9]/', '', $value);

        $this->attributes['account_last_four'] = $digits === null ? null : substr($digits, -4);
    }

    /**
     * A fingerprint of the destination as it stands.
     *
     * Taken when staff open the details and checked again when they verify, so
     * a verification cannot land on details the organizer changed in between —
     * the check was of the old account, and the mark would go on the new one.
     * Keyed with the app key: it travels through a browser form, and a bare
     * hash of an account number is a guessable one.
     */
    public function fingerprint(): string
    {
        $values = array_map(fn (string $field) => (string) $this->getAttribute($field), self::DESTINATION_FIELDS);

        return hash_hmac('sha256', json_encode($values), (string) config('app.key'));
    }

    /**
     * The destination, for a list or a confirmation line, without decrypting
     * anything — so showing it is not an access worth logging.
     */
    public function maskedDestination(): string
    {
        return $this->rail === 'interac'
            ? 'Interac e-Transfer'
            : 'Bank account ending '.($this->account_last_four ?? '????');
    }
}
