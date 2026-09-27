<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Services\Audit\Auditor;
use App\Services\Impersonation\Impersonation;
use App\Services\Impersonation\WhileImpersonating;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
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
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

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
class User extends Authenticatable implements FilamentUser, HasEmailAuthentication
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
        /*
         * Staff acting as an organization hold that one membership and no
         * other for as long as the session lasts — read from the session row,
         * not organization_user, so nothing is added to the organization's
         * team and the staff member's own memberships (if any) are out of
         * reach through this token. See ImpersonationSession.
         */
        if (($tokenId = $this->impersonationTokenId()) !== null) {
            return ImpersonationSession::membershipFor($this, $tokenId);
        }

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

    /**
     * Every staff sign-in asks for a code emailed to the account's address.
     *
     * Always, not a setting somebody can switch off: the admin can refund,
     * settle and read bank details, and a password alone is the thing most
     * likely to have leaked from somewhere else. Nobody reaches the code step
     * without a staff role and a verified address (canAccessPanel is checked
     * first), so the address a code goes to is one they have proven they read.
     *
     * And it stays the address the role was granted on. Moving an account
     * needs only its password — the thing the code is there to back up — so
     * a staff account cannot be moved from its own screens, and one moved any
     * other way loses the role (booted() below) until an administrator grants
     * it again, to the new address, knowing it is new.
     */
    public function hasEmailAuthentication(): bool
    {
        return true;
    }

    /** There is nothing to switch: codes are always required. */
    public function toggleEmailAuthentication(bool $condition): void
    {
        if (! $condition) {
            throw new \LogicException('Sign-in codes cannot be turned off for staff.');
        }
    }

    /**
     * The staff role a save in progress is taking away because the address
     * moved, kept until the save has gone through so it can be recorded.
     * A property, not a column.
     */
    private ?PlatformRole $staffRoleLostWithAddress = null;

    /**
     * A new password signs out every "keep me signed in" browser.
     *
     * Staff stay signed in until they sign out, so a changed password — very
     * often changed because the old one got out — has to be what ends the
     * sessions somebody else might be holding. The admin's session check
     * compares password hashes too; cycling the token here means a copied
     * remember cookie is dead whichever way the password was changed.
     *
     * And a new address ends a staff role. The admin's sign-in code goes to
     * the address, so whoever moves the account chooses where the codes go,
     * and moving it takes only the password. The account's own screens refuse
     * to move a staff account (AccountController::requestEmailChange); this
     * is what holds if anything else ever does. The role goes, every
     * remembered browser with it (AuthenticateStaff signs out the sessions on
     * their next request), and it is recorded. The same inbox written with
     * different capitals is not a move.
     */
    protected static function booted(): void
    {
        static::updating(function (User $user): void {
            $user->staffRoleLostWithAddress = null;

            if ($user->isDirty('password')) {
                $user->setRememberToken(Str::random(60));
            }

            if ($user->isDirty('email')
                && $user->platform_role !== null
                && Str::lower((string) $user->getOriginal('email')) !== Str::lower((string) $user->email)) {
                $user->staffRoleLostWithAddress = $user->getOriginal('platform_role') ?? $user->platform_role;
                $user->platform_role = null;
                $user->setRememberToken(Str::random(60));
            }
        });

        static::updated(function (User $user): void {
            if ($user->staffRoleLostWithAddress === null) {
                return;
            }

            $held = $user->staffRoleLostWithAddress;
            $user->staffRoleLostWithAddress = null;

            // No actor: whoever moved the address, nobody chose to revoke.
            app(Auditor::class)->record('staff.revoked', $user, metadata: [
                'role' => $held->value,
                'reason' => 'address_changed',
                'via' => 'address_change',
            ]);
        });
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

    /**
     * May this person do this thing, here.
     *
     * The check policies should be making. hasRoleIn() asks who somebody is and
     * leaves each caller to decide what that implies — which is how the role
     * list for a capability ended up written out at a dozen call sites and then
     * a thirteenth time, differently, in the console.
     *
     * Several permissions may be passed; holding any one of them is enough.
     */
    public function hasPermissionIn(Organization|string $organization, Permission ...$permissions): bool
    {
        $role = $this->roleIn($organization);

        if ($role === null) {
            return false;
        }

        $held = $this->permissionsFor($role);

        foreach ($permissions as $permission) {
            if (in_array($permission, $held, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything this person may do in one organization.
     *
     * Sent to the client so it can hide what somebody cannot do without
     * reimplementing the rules. The client mirrors this list; it never derives
     * it.
     *
     * @return list<string>
     */
    public function permissionsIn(Organization|string $organization): array
    {
        $role = $this->roleIn($organization);

        return $role === null ? [] : array_map(fn (Permission $p) => $p->value, $this->permissionsFor($role));
    }

    /**
     * A role's permissions, less what staff acting as an organization are
     * never given (WhileImpersonating::WITHHELD). The one place that happens,
     * so every policy and the list the console is sent agree.
     *
     * @return list<Permission>
     */
    private function permissionsFor(Role $role): array
    {
        $held = Permission::forRole($role);

        return $this->impersonationTokenId() === null ? $held : WhileImpersonating::withhold($held);
    }

    /**
     * The Sanctum token id, when this request is myFiesta staff acting as an
     * organization; null for everybody else, and for anything not presenting
     * a token at all.
     *
     * Read from the token itself rather than from the request, so it follows
     * this User instance — the one the guard resolved — and never another
     * account loaded along the way.
     */
    public function impersonationTokenId(): ?int
    {
        $token = $this->currentAccessToken();

        return $token instanceof PersonalAccessToken
            && in_array(Impersonation::ABILITY, $token->abilities ?? [], true)
            ? (int) $token->getKey()
            : null;
    }

    /**
     * What a token issued to this account may do.
     *
     * Decided from what the account actually is, never from what a client
     * asked for. Everybody is an attendee — an organizer buys tickets to other
     * people's nights like anyone else — and organizer comes from being staff
     * somewhere, so an account with no organization behind it cannot be handed
     * the organizer screens by signing up through a different door.
     *
     * @return list<string>
     */
    public function tokenAbilities(): array
    {
        $abilities = [TokenAbility::Attendee->value];

        if ($this->organizations()->exists()) {
            $abilities[] = TokenAbility::Organizer->value;
        }

        return $abilities;
    }

    /** Any staff role. Excludes door, which is not general access. */
    public function isStaffOf(Organization|string $organization): bool
    {
        return $this->roleIn($organization)?->isStaff() ?? false;
    }
}
