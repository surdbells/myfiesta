<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Somebody who has asked for an account and not yet shown the address is
 * theirs.
 *
 * Nothing exists for them until the link sent to that address is opened —
 * see SignUps::complete(), which turns one of these into an account.
 */
class PendingRegistration extends Model
{
    use HasUuids;

    /**
     * How long the link works.
     *
     * A day rather than the hour a password reset gets: somebody signing up
     * on a phone at a venue may not open their email until the morning, and
     * a link that died overnight is a sign-up lost for no gain — the
     * password in here is already hashed, and nothing is made until it is
     * opened.
     */
    public const EXPIRES_HOURS = 24;

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Somebody going out, as opposed to somebody opening an events page. */
    public function isAttendee(): bool
    {
        return blank($this->organization);
    }
}
