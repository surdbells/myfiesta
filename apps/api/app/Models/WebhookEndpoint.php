<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somewhere an organizer wants to hear about what happens.
 *
 * The secret is encrypted rather than hashed: we sign every delivery with it,
 * so we have to be able to read it back. It is shown to the organizer once,
 * when the endpoint is made, and never returned by the API after that.
 */
class WebhookEndpoint extends Model
{
    use HasUuids;

    /** What can be subscribed to. Each is emitted from exactly one place. */
    public const EVENTS = ['order.paid', 'order.refunded', 'ticket.checked_in', 'order.disputed'];

    /**
     * How many failures in a row before we stop knocking.
     *
     * Each delivery already retries on its own for most of a day, so this is
     * many deliveries' worth of silence — a receiver that has been down that
     * long is one to turn off and tell somebody about, not one to keep
     * sending personal data at.
     */
    public const DISABLE_AFTER = 25;

    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'disabled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function isActive(): bool
    {
        return $this->disabled_at === null;
    }

    public function wants(string $event): bool
    {
        return $this->isActive() && in_array($event, $this->events ?? [], true);
    }
}
