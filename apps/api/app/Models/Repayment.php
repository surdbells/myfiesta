<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Money an organization paid back to myFiesta outside the platform.
 *
 * The other way an advance is recovered: most are paid back by the next
 * sales, which the ledger does on its own; some are paid by a transfer to us.
 * This is the record of one of those — the reference that matches it to our
 * bank statement, why it was taken, who recorded it — and the ledger entry
 * that credits it is written in the same transaction (see Overdrafts).
 *
 * Append-only, like the ledger it writes to. The database refuses UPDATE and
 * DELETE; these guards make that a sentence rather than a Postgres error.
 */
class Repayment extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Repayments are never edited. Record a correcting entry instead.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Repayments are never deleted. Record a correcting entry instead.');
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<LedgerEntry, $this> */
    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    /** @return Attribute<Money, never> */
    protected function money(): Attribute
    {
        return Attribute::get(fn () => new Money($this->amount, $this->currency));
    }
}
