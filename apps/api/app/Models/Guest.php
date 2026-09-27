<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Someone invited to an invitation-kind event.
 *
 * Not a user. Wedding guests are imported from a spreadsheet by somebody else,
 * and most will never hold an account.
 */
class Guest extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['invite_token'];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'opened_at' => 'datetime',
        ];
    }

    /**
     * The guest's link authenticates them, so it has to be unguessable.
     *
     * 32 bytes from a CSPRNG. Anything derived from a name or an email would
     * let one guest read — or answer for — another.
     */
    public static function freshToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Looked up by token, so the route model binding cannot leak ids. */
    public function getRouteKeyName(): string
    {
        return 'invite_token';
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<Rsvp, $this> */
    public function rsvps(): HasMany
    {
        return $this->hasMany(Rsvp::class);
    }

    /**
     * The current answer. Superseded ones are kept but never the live one.
     *
     * @return HasOne<Rsvp, $this>
     */
    public function rsvp(): HasOne
    {
        return $this->hasOne(Rsvp::class)->whereNull('superseded_at');
    }

    public function hasResponded(): bool
    {
        return $this->rsvp()->exists();
    }
}
