<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Append-only. Corrections are written, never applied in place.
 *
 * The database refuses UPDATE and DELETE via trigger; these guards exist so the
 * failure arrives as a clear exception in application code rather than a
 * Postgres error from three layers down.
 */
class LedgerEntry extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Ledger entries are immutable. Write a reversing entry instead.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Ledger entries cannot be deleted. Write a reversing entry instead.');
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    protected function money(): Attribute
    {
        return Attribute::get(fn () => new Money($this->amount, $this->currency));
    }

    /**
     * What an organization is owed, per currency.
     *
     * Returns one Money per currency, never a single total: an organization
     * running events in Toronto and Lagos has two balances, and adding them
     * would be meaningless.
     *
     * @return array<string, Money>
     */
    public static function balancesFor(Organization|string $organization): array
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;

        return static::query()
            ->where('organization_id', $id)
            ->selectRaw('currency, SUM(amount) AS total')
            ->groupBy('currency')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->currency => new Money((int) $row->total, $row->currency)])
            ->all();
    }
}
