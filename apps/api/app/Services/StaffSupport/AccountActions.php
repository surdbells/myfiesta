<?php

namespace App\Services\StaffSupport;

use App\Enums\PlatformRole;
use App\Models\EmailChange;
use App\Models\User;
use App\Services\Accounts\EmailVerification;
use App\Services\Audit\Auditor;
use App\Services\Door\DoorPasses;
use App\Services\Impersonation\Impersonation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * What support does to somebody's account, and the record that they did.
 *
 * Two rules hold for every one of these. Nobody does them to their own
 * account — a member of staff who wants a reset link asks for one like anyone
 * else, and one who deactivates themselves has locked the door from outside.
 * And a staff account is touched only by an administrator: support signing a
 * finance colleague out mid-settlement is a support ticket about support.
 */
class AccountActions
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly DoorPasses $doorPasses,
    ) {}

    /**
     * Email a link to set a new password.
     *
     * The same link the forgot-password screen sends, so the person gets the
     * email they would have got themselves — staff never see or choose the
     * password. An account made by a guest checkout has no password yet, and
     * this is how its owner claims it.
     */
    public function sendPasswordReset(User $user, User $staff): void
    {
        $this->guard(StaffAction::Resend, $user, $staff);
        $this->refuseIfClosed($user);

        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_THROTTLED) {
            throw StaffActionRefused::because('A reset link was sent to this address moments ago. Ask them to check their inbox and spam folder first.');
        }

        if ($status !== Password::RESET_LINK_SENT) {
            throw StaffActionRefused::because('The link could not be sent to this account.');
        }

        $this->auditor->record('user.password_reset_sent', $user, $staff);
    }

    /**
     * Email the link that proves the address, again.
     *
     * Through EmailVerification, so it shares that link's per-account limit
     * with the person's own button: support resending cannot be used to fill
     * somebody's inbox either.
     */
    public function resendVerification(User $user, User $staff): void
    {
        $this->guard(StaffAction::Resend, $user, $staff);
        $this->refuseIfClosed($user);

        if ($user->email_verified_at !== null) {
            throw StaffActionRefused::because('This address is already verified.');
        }

        $verification = app(EmailVerification::class);

        if (! $verification->send($user)) {
            throw StaffActionRefused::because('Enough links have gone to this address for now. Another can be sent in '
                .$verification->minutesUntilNext($user).' minutes.');
        }

        $this->auditor->record('user.verification_resent', $user, $staff);
    }

    /**
     * Sign the account out of every phone, browser and door it is signed in on.
     *
     * Tokens, web sessions, "remember me", and the door links it handed to
     * other phones — the same things a password reset ends, because this is
     * what somebody asks for when they think another person has their account.
     *
     * @return array{tokens: int, sessions: int, door_passes: int}
     */
    public function signOutEverywhere(User $user, User $staff): array
    {
        $this->guard(StaffAction::SignOut, $user, $staff);

        $ended = $this->endEverySession($user, $staff);

        $this->auditor->record('user.signed_out_everywhere', $user, $staff, metadata: $ended);

        return $ended;
    }

    /**
     * Close the account without erasing it.
     *
     * A soft delete: nothing it bought, sold or said goes anywhere, and an
     * administrator can bring it back. Signed out everywhere first, so a
     * deactivated account is not still holding a token that works.
     *
     * The address stays on the row. Everything that finds an account by
     * address for a ticket (checkout, comps, transfers, reissue) looks
     * withTrashed, so a ticket bought for it later lands on this account
     * rather than colliding with it on the unique email index.
     */
    public function deactivate(User $user, User $staff, string $reason): void
    {
        $this->guard(StaffAction::Deactivate, $user, $staff);

        $reason = trim($reason);

        if ($reason === '') {
            throw StaffActionRefused::because('Say why. The next person to look at this account will need to know.');
        }

        if ($user->trashed()) {
            throw StaffActionRefused::because('This account is already deactivated.');
        }

        $ended = DB::transaction(function () use ($user, $staff) {
            $ended = $this->endEverySession($user, $staff);

            // A move to a new address that is still waiting would otherwise
            // complete on a closed account.
            EmailChange::where('user_id', $user->id)->delete();

            $user->delete();

            return $ended;
        });

        $this->auditor->record('user.deactivated', $user, $staff, metadata: ['reason' => $reason] + $ended);
    }

    public function reactivate(User $user, User $staff, ?string $note = null): void
    {
        $this->guard(StaffAction::Deactivate, $user, $staff);

        if (! $user->trashed()) {
            throw StaffActionRefused::because('This account is not deactivated.');
        }

        // Closed at its owner's request. The row is kept only because orders
        // point at it, and the person behind it is gone on purpose.
        if ($this->wasErased($user)) {
            throw StaffActionRefused::because('This account was erased at its owner\'s request and cannot be brought back. They can sign up again.');
        }

        $user->restore();

        $this->auditor->record('user.reactivated', $user, $staff, metadata: array_filter(['note' => $note ? trim($note) : null]));
    }

    /** Whether this person may be acted on by this member of staff at all. */
    public function mayActOn(User $user, User $staff, StaffAction $action): bool
    {
        try {
            $this->guard($action, $user, $staff);

            return true;
        } catch (StaffActionRefused) {
            return false;
        }
    }

    public function wasErased(User $user): bool
    {
        return str_ends_with(Str::lower($user->email), '@erased.invalid');
    }

    /** @throws StaffActionRefused */
    private function guard(StaffAction $action, User $user, User $staff): void
    {
        $action->authorize($staff);

        if ($user->is($staff)) {
            throw StaffActionRefused::because('Not on your own account. Use the normal sign-in screens, or ask another administrator.');
        }

        if ($user->isPlatformStaff() && ! $staff->hasPlatformRole(PlatformRole::Admin)) {
            throw StaffActionRefused::because('This is a staff account. Only an administrator can do this.');
        }
    }

    private function refuseIfClosed(User $user): void
    {
        if ($user->trashed()) {
            throw StaffActionRefused::because('This account is deactivated. Reactivate it first.');
        }

        if ($this->wasErased($user)) {
            throw StaffActionRefused::because('This account was erased at its owner\'s request.');
        }
    }

    /** @return array{tokens: int, sessions: int, door_passes: int} */
    private function endEverySession(User $user, ?User $by = null): array
    {
        $tokens = $user->tokens()->delete();

        // A staff member's sessions acting as an organization, including a
        // console link not yet opened, which would otherwise still trade for
        // a token after this.
        app(Impersonation::class)->revokeFor($user, $by);

        $sessions = DB::table('sessions')->where('user_id', $user->id)->delete();

        // A "remember me" cookie is checked against this. A new value makes
        // every one already issued worthless.
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        $doorPasses = $this->doorPasses->endIssuedBy($user);

        return ['tokens' => (int) $tokens, 'sessions' => (int) $sessions, 'door_passes' => $doorPasses];
    }
}
