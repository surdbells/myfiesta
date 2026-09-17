<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The owner of every organizer-facing resource.
 *
 * Events, ticket types, codes, settlements, and payout details all hang off an
 * organization rather than a user, which is what lets a venue add a second
 * person without a data migration.
 */
class Organization extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    /**
     * Whether the tick is shown beside this organizer's name.
     *
     * Verified *and* still called what it was called when somebody checked.
     * The verification survives a rename; the public claim does not, because
     * nobody has checked the new name — and a verified account that can rename
     * itself to anything is the whole value of a tick, handed away.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null && $this->verified_name === $this->name;
    }

    /** Whether a rename is waiting on somebody to look at it. */
    public function awaitsRenameCheck(): bool
    {
        return $this->verified_at !== null && $this->verified_name !== $this->name;
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(OrganizationUser::class)
            ->withPivot(['id', 'role', 'invited_at', 'accepted_at'])
            ->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class);
    }

    public function codes(): HasMany
    {
        return $this->hasMany(Code::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function payoutDetails(): HasMany
    {
        return $this->hasMany(OrganizationPayoutDetail::class);
    }

    public function identityDocuments(): HasMany
    {
        return $this->hasMany(OrganizationIdentityDocument::class);
    }
}
