<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A guest's answer.
 *
 * Superseded rather than overwritten. Hosts cater from these numbers, and a
 * changed mind is information — "they said yes last week" needs to survive them
 * saying no today.
 */
class Rsvp extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(RsvpAnswer::class);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    public function scopeAttending(Builder $query): Builder
    {
        return $query->live()->where('status', 'attending');
    }

    public function isAttending(): bool
    {
        return $this->status === 'attending';
    }
}
