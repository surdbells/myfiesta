<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Everyone: attendees, organization members, and staff.
 *
 * A person is often both — organizers buy tickets to other people's events —
 * so one table avoids two accounts for one human. What makes someone an
 * organizer is membership of an organization, not a different table.
 *
 * `password` is nullable. Historical buyers migrate as unclaimed records keyed
 * on the email their tickets were issued to, so when they later register their
 * orders are already waiting.
 */
#[Fillable(['name', 'email', 'password', 'phone', 'locale', 'timezone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->using(OrganizationUser::class)
            ->withPivot(['id', 'role', 'invited_at', 'accepted_at'])
            ->withTimestamps();
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'owner_user_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** True when the account exists only because someone bought a ticket. */
    public function isUnclaimed(): bool
    {
        return $this->password === null;
    }

    public function roleIn(Organization|string $organization): ?Role
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;

        $membership = $this->organizations->firstWhere('id', $id);

        return $membership ? Role::from($membership->pivot->role) : null;
    }

    public function hasRoleIn(Organization|string $organization, Role ...$roles): bool
    {
        $held = $this->roleIn($organization);

        return $held !== null && in_array($held, $roles, true);
    }

    /** Any staff role. Excludes door, which is not general access. */
    public function isStaffOf(Organization|string $organization): bool
    {
        return $this->roleIn($organization)?->isStaff() ?? false;
    }
}
