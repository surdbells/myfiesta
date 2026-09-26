<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A new address somebody has asked to move their account to, waiting for them
 * to show they can read it.
 *
 * Nothing about the account changes while one of these exists. The old address
 * still signs in, and still gets the password resets, until the link sent to
 * the new one is opened.
 */
class EmailChange extends Model
{
    use HasUuids;

    /**
     * How long the link works.
     *
     * An hour, like a password reset. Somebody changing their address has the
     * new inbox open in the next tab; a link that still works tomorrow is one
     * that is still sitting in a mailbox tomorrow.
     */
    public const EXPIRES_MINUTES = 60;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
