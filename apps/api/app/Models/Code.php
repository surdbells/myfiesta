<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

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

    /** Orders that count as a use of this code. A fully refunded one gives its use back. */
    public const PAID_STATUSES = ['paid', 'partially_refunded'];

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

    /**
     * The ticket types this code discounts. None means every type.
     *
     * Only an event's own code can name them: an organization-wide code runs
     * across events whose ticket types it cannot know about.
     */
    public function ticketTypes(): BelongsToMany
    {
        return $this->belongsToMany(TicketType::class);
    }

    /** The tiers this code opens to whoever holds it. */
    public function unlocks(): BelongsToMany
    {
        return $this->belongsToMany(TicketType::class, 'code_unlocks');
    }

    /**
     * Uses that count against a limit right now: paid ones, and checkouts
     * still inside their hold.
     *
     * Counted from orders rather than kept as a running total. A total that
     * went up when checkout started and never came down spent a use on every
     * abandoned basket; one that went up only on payment would let a hundred
     * people start checkout on the last use of a code at once.
     *
     * Call with the code row locked, or two checkouts can both see room.
     */
    public function usesInFlight(int $holdMinutes, ?string $buyerEmail = null): int
    {
        return DB::table('orders')
            // As a discount or as the key to a locked tier; an order that
            // used it as both is still one use.
            ->where(fn ($q) => $q->where('code_id', $this->id)->orWhere('access_code_id', $this->id))
            ->where(fn ($q) => $q->whereIn('status', self::PAID_STATUSES)
                ->orWhere(fn ($q) => $q->where('status', 'pending')->where('created_at', '>', now()->subMinutes($holdMinutes))))
            ->when($buyerEmail !== null, fn ($q) => $q->where('buyer_email', strtolower(trim($buyerEmail))))
            ->count();
    }

    /**
     * Set redemption_count to the paid uses, which is what an organizer reads
     * it as. Recomputed on payment and on refund rather than incremented, so it
     * cannot drift from the orders it describes.
     */
    public static function recount(?string $codeId): void
    {
        if ($codeId === null) {
            return;
        }

        DB::table('codes')->where('id', $codeId)->update([
            'redemption_count' => DB::table('orders')
                ->where(fn ($q) => $q->where('code_id', $codeId)->orWhere('access_code_id', $codeId))
                ->whereIn('status', self::PAID_STATUSES)
                ->count(),
        ]);
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
