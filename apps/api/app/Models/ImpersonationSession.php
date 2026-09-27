<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One time a member of myFiesta staff opened an organization's console as the
 * organization. See the migration for why it doubles as a membership.
 */
class ImpersonationSession extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['handoff_hash'];

    protected function casts(): array
    {
        return [
            'handoff_expires_at' => 'datetime',
            'exchanged_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public static function hashCode(string $code): string
    {
        return hash('sha256', $code);
    }

    /** Still open: not ended, and inside its hour. Opened or not. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->where('expires_at', '>', now());
    }

    /**
     * 'waiting' — started in the admin panel, code not yet exchanged
     * 'active'  — the console holds the token, and it still works
     * 'unused'  — the code was never exchanged in time
     * 'expired' — the hour ran out
     * 'ended'   — stopped by somebody, or superseded by a newer session
     */
    public function state(): string
    {
        return match (true) {
            $this->ended_at !== null => $this->ended_how === 'expired' ? 'expired' : ($this->ended_how === 'unused' ? 'unused' : 'ended'),
            $this->expires_at->isPast() => 'expired',
            $this->exchanged_at === null => $this->handoff_expires_at->isPast() ? 'unused' : 'waiting',
            default => 'active',
        };
    }

    /**
     * The organization a staff member holds through this token, read as a
     * membership.
     *
     * Constrained on the row's own state rather than trusting that ending a
     * session deleted its token: an ended or lapsed session answers "no
     * organization", which every organizer endpoint already refuses.
     */
    public static function membershipFor(User $staff, int $tokenId): BelongsToMany
    {
        return $staff->belongsToMany(
            Organization::class,
            'impersonation_sessions',
            'staff_user_id',
            'organization_id',
            relation: 'organizations',
        )
            ->withPivot(['id', 'role'])
            ->wherePivot('token_id', $tokenId)
            ->wherePivotNull('ended_at')
            ->wherePivot('expires_at', '>', now());
    }
}
