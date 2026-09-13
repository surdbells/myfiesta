<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\PersonalAccessToken;

/** A one-phone, one-event scanning grant, handed over as a link. */
class DoorPass extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'token_id');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(TicketScan::class);
    }

    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function findBySecret(string $secret): ?self
    {
        return static::query()->where('secret_hash', static::hashSecret($secret))->first();
    }

    /**
     * 'waiting' — sent, not yet opened
     * 'active'  — opened on a phone, still working
     * 'expired' — past its time
     * 'revoked' — taken back by the organizer
     * 'ended'   — stopped without anybody choosing to: the issuer left the
     *             team, or changed their password, which signs out everything
     */
    public function state(): string
    {
        return match (true) {
            $this->revoked_at !== null => $this->revoked_by === null ? 'ended' : 'revoked',
            $this->expires_at->isPast() => 'expired',
            $this->claimed_at === null => 'waiting',
            $this->token === null => 'ended',
            default => 'active',
        };
    }
}
