<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        return [
            'verified_at' => 'datetime',
            'suspended_at' => 'datetime',
            'suspension_reason_shared' => 'boolean',
        ];
    }

    /**
     * Whether the platform has stopped selling for this organization.
     *
     * As this instance last read it. Anything that sells or pays out asks
     * Suspension::inForce() instead, which reads the row again: a checkout
     * holding an organization loaded a minute ago must not sell on it.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * The member of staff who suspended it, while it is suspended.
     *
     * @return BelongsTo<User, $this>
     */
    public function suspender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
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

    /** @return BelongsToMany<User, $this, OrganizationUser> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(OrganizationUser::class)
            ->withPivot(['id', 'role', 'invited_at', 'accepted_at'])
            ->withTimestamps();
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** @return HasMany<Venue, $this> */
    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class);
    }

    /** @return HasMany<Code, $this> */
    public function codes(): HasMany
    {
        return $this->hasMany(Code::class);
    }

    /** @return HasMany<Settlement, $this> */
    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** @return HasMany<OrganizationPayoutDetail, $this> */
    public function payoutDetails(): HasMany
    {
        return $this->hasMany(OrganizationPayoutDetail::class);
    }

    /** @return HasMany<OrganizationIdentityDocument, $this> */
    public function identityDocuments(): HasMany
    {
        return $this->hasMany(OrganizationIdentityDocument::class);
    }

    /** @return HasMany<PayoutRequest, $this> */
    public function payoutRequests(): HasMany
    {
        return $this->hasMany(PayoutRequest::class);
    }
}
