<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A discount, an attribution, or both.
 *
 * These are one object rather than two features: in nightlife the discount code
 * is how a promoter proves they drove the sale. Keeping them separate would
 * mean reconciling two systems later.
 */
class Code extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Null scopes the code to every event the organization runs. */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = strtoupper(trim($value));
    }

    public function scopeUsable(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->where(fn (Builder $q) => $q->whereNull('max_redemptions')
                ->orWhereColumn('redemption_count', '<', 'max_redemptions'));
    }

    public function discounts(): bool
    {
        return $this->discount_type !== null;
    }

    public function attributesSale(): bool
    {
        return $this->ref_slug !== null;
    }

    /**
     * Whether this code can legally apply to an event.
     *
     * A fixed-amount code carries a currency and cannot cross into an event
     * priced in another. Percentage codes travel freely.
     */
    public function appliesTo(Event $event): bool
    {
        if ($this->event_id !== null && $this->event_id !== $event->id) {
            return false;
        }

        if ($this->organization_id !== $event->organization_id) {
            return false;
        }

        if ($this->discount_type === 'fixed' && $this->discount_currency !== $event->currency) {
            return false;
        }

        return true;
    }
}
