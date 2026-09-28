<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    /**
     * The organizer's request this paid, when it paid one. An overdraft's
     * advance, its reason and who approved it are kept there.
     *
     * @return HasOne<PayoutRequest, $this>
     */
    public function payoutRequest(): HasOne
    {
        return $this->hasOne(PayoutRequest::class);
    }

    /** @return Attribute<Money, never> */
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
