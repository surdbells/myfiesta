<?php

namespace App\Services\Door;

use App\Enums\TokenAbility;
use App\Models\DoorPass;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\Auditor;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issuing, opening and taking back door passes.
 *
 * The token a pass mints belongs to the member who issued it — Sanctum needs
 * an owner, and the issuer is who vouched for the phone. Its only ability is
 * `door:{event_id}`, which the middleware refuses everywhere except the three
 * door endpoints for that one event. So the phone scans; it cannot read the
 * guest list, the orders, or anything else the issuer can.
 */
class DoorPasses
{
    /** No pass outlives this, whatever the event's times say. */
    public const MAX_HOURS = 48;

    /** Doors stay open a while after the listed end: late re-entry, stragglers. */
    public const GRACE_HOURS = 3;

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * When a pass for this event stops working.
     *
     * The end of the night plus a margin, or half a day after the start when
     * no end was given — and never more than two days away, so a pass made a
     * week early for a festival does not sit live all week.
     */
    public function expiryFor(Event $event): CarbonInterface
    {
        $close = ($event->ends_at ?? $event->starts_at->copy()->addHours(12))->copy()->addHours(self::GRACE_HOURS);

        return $close->min(now()->addHours(self::MAX_HOURS));
    }

    /**
     * @return array{pass: DoorPass, secret: string}
     *
     * @throws DoorPassRefused
     */
    public function issue(Event $event, User $by, string $label): array
    {
        if ($event->status === 'cancelled') {
            throw DoorPassRefused::because('This event is cancelled. There is no door to open.');
        }

        $expires = $this->expiryFor($event);

        if ($expires->isPast()) {
            throw DoorPassRefused::because('This event is over. Door passes stop working a few hours after it ends.');
        }

        $secret = Str::random(40);

        $pass = DoorPass::create([
            'event_id' => $event->id,
            'label' => trim($label),
            'secret_hash' => DoorPass::hashSecret($secret),
            'issued_by' => $by->id,
            'expires_at' => $expires,
        ]);

        $this->auditor->record('door_pass.issued', $pass, $by, $event->organization_id, [
            'event' => $event->id,
            'label' => $pass->label,
        ]);

        return ['pass' => $pass, 'secret' => $secret];
    }

    /**
     * Open a pass on this phone.
     *
     * Once. A link pasted into a group chat is a link anybody in the chat can
     * open, and the second person to try should be told to ask for their own
     * rather than quietly handed a working scanner.
     *
     * @return array{pass: DoorPass, token: string}
     *
     * @throws DoorPassRefused
     */
    public function claim(string $secret): array
    {
        return DB::transaction(function () use ($secret) {
            $pass = DoorPass::query()
                ->where('secret_hash', DoorPass::hashSecret($secret))
                ->lockForUpdate()
                ->first();

            if ($pass === null) {
                throw DoorPassRefused::because('This door link is not valid.', 404);
            }

            $refusal = match ($pass->state()) {
                'revoked', 'ended' => 'This door pass was taken back. Ask whoever runs the event for a new link.',
                'expired' => 'This door pass has expired. Ask whoever runs the event for a new link.',
                'waiting' => null,
                default => 'This link has already been opened on another phone. Each phone needs its own link — ask for a new one.',
            };

            if ($refusal !== null) {
                throw DoorPassRefused::because($refusal, 410);
            }

            $issuer = $pass->issuer;
            $event = $pass->event;

            // The issuer left the team, or lost the right to work the door,
            // after making the link. What they could hand out went with them.
            if ($issuer === null || ! $issuer->can('scan', $event)) {
                throw DoorPassRefused::because('Whoever made this link can no longer open this door. Ask for a new one.', 410);
            }

            $token = $issuer->createToken(
                'door-pass:'.$pass->id,
                [TokenAbility::doorFor($event->id)],
                $pass->expires_at,
            );

            $pass->update([
                'claimed_at' => now(),
                'token_id' => $token->accessToken->id,
            ]);

            $this->auditor->record('door_pass.opened', $pass, null, $event->organization_id, [
                'event' => $event->id,
                'label' => $pass->label,
            ]);

            return ['pass' => $pass, 'token' => $token->plainTextToken];
        });
    }

    public function revoke(DoorPass $pass, User $by): void
    {
        DB::transaction(function () use ($pass, $by) {
            if ($pass->token_id !== null) {
                PersonalAccessToken::query()->whereKey($pass->token_id)->delete();
            }

            $pass->update(['revoked_at' => now(), 'revoked_by' => $by->id]);
        });

        $this->auditor->record('door_pass.revoked', $pass, $by, $pass->event->organization_id, [
            'event' => $pass->event_id,
            'label' => $pass->label,
        ]);
    }

    /**
     * Everything a member handed out, for one organization's doors or all.
     *
     * Called when they leave a team, and when their password changes — which
     * is what somebody does when they think their account is in the wrong
     * hands, and a link that account made but nobody has opened yet is still
     * a way in. Phones at the door stop at their next scan; the passes stay
     * listed, ended, so the organizer can see why.
     */
    public function endIssuedBy(User $member, ?Organization $organization = null): int
    {
        $passes = DoorPass::query()
            ->where('issued_by', $member->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->when($organization, fn ($query) => $query->whereHas('event', fn ($events) => $events->where('organization_id', $organization->id)))
            ->get();

        foreach ($passes as $pass) {
            if ($pass->token_id !== null) {
                PersonalAccessToken::query()->whereKey($pass->token_id)->delete();
            }

            $pass->update(['revoked_at' => now(), 'revoked_by' => null]);
        }

        return $passes->count();
    }

    /** The pass behind the token making this request, if it is one. */
    public function forToken(mixed $token): ?DoorPass
    {
        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        return DoorPass::query()->where('token_id', $token->id)->first();
    }
}
