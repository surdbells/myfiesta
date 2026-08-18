<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payout recorded against the ledger.
 *
 * Settlement is manual for now, so this records money that moved elsewhere
 * rather than moving it. The rail matters because manual settlement spans both
 * gateways and the balance alone does not say how it was paid.
 */
class Settlement extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settled_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    protected function money(): Attribute
    {
        return Attribute::get(fn () => new Money($this->amount, $this->currency));
    }

    /**
     * Classify a settlement against the outstanding balance.
     *
     * Carried forward from the previous platform, which got this part right.
     * Overdraft demands a note, enforced by check constraint.
     */
    public static function classify(Money $amount, Money $balance): string
    {
        return match (true) {
            $amount->amount > $balance->amount => 'overdraft',
            $amount->amount < $balance->amount => 'partial',
            default => 'full',
        };
    }
}
