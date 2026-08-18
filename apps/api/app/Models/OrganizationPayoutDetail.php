<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /**
     * Keep a plain last-four alongside the ciphertext.
     *
     * Lets an admin confirm which account they are looking at without
     * decrypting the record, which keeps routine work off the audit log.
     */
    public function setAccountNumberAttribute(?string $value): void
    {
        $this->attributes['account_number'] = $value === null ? null : encrypt($value);

        $digits = $value === null ? null : preg_replace('/[^0-9]/', '', $value);

        $this->attributes['account_last_four'] = $digits === null ? null : substr($digits, -4);
    }
}
