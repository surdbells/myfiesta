<?php

namespace App\Models;

use App\Enums\PlatformRole;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
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
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'platform_role' => PlatformRole::class,
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

    /**
     * Who may open the admin panel.
     *
     * One users table holds attendees as well as staff, so this gate is the
     * only thing between a ticket buyer and /admin — they already have a valid
     * account, and Filament would otherwise be satisfied by that alone.
     *
     * platform_role is null for almost every row and is granted deliberately.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->platform_role !== null
            && $this->email_verified_at !== null
            && $this->deleted_at === null;
    }

    public function isPlatformStaff(): bool
    {
        return $this->platform_role !== null;
    }

    public function hasPlatformRole(PlatformRole ...$roles): bool
    {
        return $this->platform_role !== null
            && in_array($this->platform_role, $roles, true);
    }

    public function roleIn(Organization|string $organization): ?Role
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;

        $membership = $this->organizations->firstWhere('id', $id);

        if ($membership === null) {
            return null;
        }

        // The custom pivot casts this to a Role, but a membership loaded
        // without it hands back the raw string. Accept both rather than
        // depending on how the relation happened to be loaded.
        $role = $membership->pivot->role;

        return $role instanceof Role ? $role : Role::from($role);
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
