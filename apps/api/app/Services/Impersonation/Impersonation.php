<?php

namespace App\Services\Impersonation;

use App\Enums\PlatformRole;
use App\Enums\TokenAbility;
use App\Models\ImpersonationSession;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * myFiesta staff opening an organization's console as the organization.
 *
 * Started in the admin panel with a reason, which mints a handoff code — not a
 * token. The code goes to the console in the fragment of a URL, which browsers
 * do not send to servers or put in Referer headers, and the console exchanges
 * it here for the token itself, once, within a minute. So the token is never
 * in a URL, a browser history or a log line.
 *
 * The token belongs to the member of staff: Sanctum needs an owner, and the
 * staff member is who is acting — every audit entry written with it names
 * them, not the organization's owner. Its abilities are `organizer` and
 * `impersonation`, and User::organizations() answers with this session's one
 * organization while it is presented, less what WhileImpersonating withholds.
 * It expires with the session, an hour after it was started, and nothing
 * extends it.
 *
 * Nothing here touches anybody's own sign-in: not the staff member's admin
 * session, not their own console tokens, not the organization's members'.
 */
class Impersonation
{
    public const ABILITY = 'impersonation';

    /** How long a session lasts, from the moment it is started. */
    public const MINUTES = 60;

    /** How long the console has to exchange the handoff code. */
    public const HANDOFF_SECONDS = 60;

    /**
     * Who may start one.
     *
     * Administrators and support: helping an organizer with their console is
     * support's job. Finance is left out deliberately — its work (settlements,
     * verifying payout details) is done in the admin panel, it already holds
     * the one platform role that reads decrypted bank details, and adding "can
     * act as the organization" to that concentrates more than any finance task
     * needs.
     *
     * @var list<PlatformRole>
     */
    public const ROLES = [PlatformRole::Admin, PlatformRole::Support];

    public function __construct(private readonly Auditor $auditor) {}

    public function mayImpersonate(?User $staff): bool
    {
        return $staff !== null
            && $staff->hasPlatformRole(...self::ROLES)
            && $staff->email_verified_at !== null
            && ! $staff->trashed();
    }

    /**
     * @return array{session: ImpersonationSession, code: string, url: string}
     *
     * @throws ImpersonationRefused
     */
    public function start(Organization $organization, User $staff, string $reason, ?string $ip = null): array
    {
        if (! $this->mayImpersonate($staff)) {
            throw ImpersonationRefused::because('Only administrators and support staff can open an organization’s console.');
        }

        if ($organization->trashed()) {
            throw ImpersonationRefused::because('This organization has been removed.', 422);
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw ImpersonationRefused::because('Say why you are opening this organization’s console — a sentence, not a word.', 422);
        }

        if (mb_strlen($reason) > 500) {
            throw ImpersonationRefused::because('Keep the reason under 500 characters.', 422);
        }

        // One at a time. Whatever this person still holds open — here or at
        // another organization — ends first, so an old tab left open does not
        // stay a way in.
        ImpersonationSession::query()
            ->where('staff_user_id', $staff->id)
            ->whereNull('ended_at')
            ->get()
            ->each(fn (ImpersonationSession $open) => ($lapsed = $this->lapsed($open))
                ? $this->end($open, $lapsed)
                : $this->end($open, 'superseded', $staff));

        $code = Str::random(48);

        $session = ImpersonationSession::create([
            'organization_id' => $organization->id,
            'staff_user_id' => $staff->id,
            'staff_label' => $staff->name,
            'role' => 'owner',
            'reason' => $reason,
            'handoff_hash' => ImpersonationSession::hashCode($code),
            'handoff_expires_at' => now()->addSeconds(self::HANDOFF_SECONDS),
            'expires_at' => now()->addMinutes(self::MINUTES),
            'started_ip' => $ip,
        ]);

        $this->auditor->record('impersonation.started', $organization, $staff, $organization->id, [
            'session' => $session->id,
            'reason' => $reason,
            'staff_role' => $staff->platform_role?->value,
            'expires_at' => $session->expires_at->toIso8601String(),
        ]);

        return ['session' => $session, 'code' => $code, 'url' => $this->consoleUrl($code)];
    }

    /**
     * Trade a handoff code for the session's token. Once.
     *
     * @return array{session: ImpersonationSession, token: string}
     *
     * @throws ImpersonationRefused
     */
    public function exchange(string $code, ?string $ip = null): array
    {
        return DB::transaction(function () use ($code, $ip) {
            $session = ImpersonationSession::query()
                ->where('handoff_hash', ImpersonationSession::hashCode($code))
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                throw ImpersonationRefused::because('This staff link is not valid.', 404);
            }

            if ($session->exchanged_at !== null) {
                throw ImpersonationRefused::because('This staff link has already been used. Start a new session from the admin panel.', 410);
            }

            if ($session->ended_at !== null || $session->expires_at->isPast()) {
                throw ImpersonationRefused::because('This staff session has ended. Start a new one from the admin panel.', 410);
            }

            if ($session->handoff_expires_at->isPast()) {
                throw ImpersonationRefused::because('This staff link has expired — it works for a minute. Start a new session from the admin panel.', 410);
            }

            $staff = $session->staff;

            if (! $this->mayImpersonate($staff)) {
                throw ImpersonationRefused::because('Your staff access has changed since this link was made.', 403);
            }

            // Trashed organizations do not resolve through the relation.
            $organization = $session->organization;

            if ($organization === null) {
                throw ImpersonationRefused::because('This organization has been removed.', 410);
            }

            $token = $staff->createToken(
                'impersonation:'.$session->id,
                [TokenAbility::Organizer->value, self::ABILITY],
                $session->expires_at,
            );

            $session->update([
                'exchanged_at' => now(),
                'token_id' => $token->accessToken->getKey(),
                'opened_ip' => $ip,
            ]);

            $this->auditor->record('impersonation.opened', $organization, $staff, $organization->id, [
                'session' => $session->id,
            ]);

            return ['session' => $session, 'token' => $token->plainTextToken];
        });
    }

    /**
     * Stop a session: its token is deleted, so the next request with it is a
     * 401, and the row is kept as the record.
     *
     * $how is ended (by the staff member), signed_out, superseded, expired,
     * unused, staff_role_removed or revoked. False when it had already
     * stopped.
     */
    public function end(ImpersonationSession $session, string $how, ?User $by = null): bool
    {
        $ended = DB::transaction(function () use ($session, $how, $by) {
            $locked = ImpersonationSession::query()->whereKey($session->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->ended_at !== null) {
                return false;
            }

            if ($locked->token_id !== null) {
                PersonalAccessToken::query()->whereKey($locked->token_id)->delete();
            }

            $locked->update([
                'ended_at' => now(),
                'ended_by' => $by?->id,
                'ended_how' => $how,
            ]);

            $session->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($ended) {
            // The staff member remains the actor even when the hour simply ran
            // out: the start and the end of one session then share an actor,
            // and `how` says whether anybody chose it.
            $this->auditor->record('impersonation.ended', $session->organization()->withTrashed()->first(), $by ?? $session->staff, $session->organization_id, [
                'session' => $session->id,
                'how' => $how,
                'opened' => $session->exchanged_at !== null,
                'minutes' => (int) round($session->created_at->diffInSeconds(now(), true) / 60),
            ]);
        }

        return $ended;
    }

    /**
     * End everything this member of staff still holds open, because their
     * sign-ins were just revoked: signed out everywhere, deactivated, a new
     * password.
     *
     * Deleting their tokens stops an opened session's token already, but not
     * a handoff code still waiting to be traded — that would mint a fresh
     * token after the revocation — and it leaves the row reading as a session
     * that ran its full hour. Called from wherever tokens are revoked, so the
     * trail says "revoked", by whom, and when.
     *
     * @return int how many were ended
     */
    public function revokeFor(User $staff, ?User $by = null): int
    {
        return ImpersonationSession::query()
            ->where('staff_user_id', $staff->id)
            ->whereNull('ended_at')
            ->get()
            ->filter(fn (ImpersonationSession $open) => ($lapsed = $this->lapsed($open))
                ? $this->end($open, $lapsed)
                : $this->end($open, 'revoked', $by))
            ->count();
    }

    /** The open session behind this token, if it is a staff session's token. */
    public function forToken(mixed $token): ?ImpersonationSession
    {
        if (! $token instanceof PersonalAccessToken || ! in_array(self::ABILITY, $token->abilities ?? [], true)) {
            return null;
        }

        return ImpersonationSession::query()->where('token_id', $token->getKey())->open()->first();
    }

    /**
     * Close what ran out on its own: the hour passed, or the link was never
     * opened. Nothing depends on this for safety — the token expires with the
     * session and the membership read refuses a lapsed row — but a session
     * with a start and no end reads, to anybody going through the trail, like
     * one somebody is still holding.
     */
    public function closeLapsed(): int
    {
        $lapsed = ImpersonationSession::query()
            ->whereNull('ended_at')
            ->where(fn ($query) => $query
                ->where('expires_at', '<=', now())
                ->orWhere(fn ($unopened) => $unopened
                    ->whereNull('exchanged_at')
                    ->where('handoff_expires_at', '<=', now())))
            ->get();

        return $lapsed
            ->filter(fn (ImpersonationSession $session) => $this->end($session, $this->lapsed($session) ?? 'expired'))
            ->count();
    }

    /** 'expired' or 'unused' when the session ran out on its own; null while it is still usable. */
    private function lapsed(ImpersonationSession $session): ?string
    {
        return match (true) {
            $session->expires_at->isPast() => $session->exchanged_at === null ? 'unused' : 'expired',
            $session->exchanged_at === null && $session->handoff_expires_at->isPast() => 'unused',
            default => null,
        };
    }

    /**
     * Where the console picks the session up.
     *
     * The code rides in the fragment: never sent to a server, never in a
     * Referer, and the console strips it from the address bar on arrival.
     */
    public function consoleUrl(string $code): string
    {
        return rtrim((string) config('app.console_url'), '/').'/impersonate#code='.$code;
    }
}
