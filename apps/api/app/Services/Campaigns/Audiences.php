<?php

namespace App\Services\Campaigns;

use App\Models\EmailPreference;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrganizationFollow;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Who an organizer may write to, and who of them will actually be written to.
 *
 * Every list is one the person put themselves on, with this organizer, within
 * a time the law treats as still meaning something. CASL's implied consent is
 * the tightest of the markets this sells in, so it sets the windows for all of
 * them:
 *
 *   - followers asked to hear from the organizer, and can stop following;
 *   - past attendees bought from them, which CASL treats as consent for two
 *     years after the purchase;
 *   - an abandoned basket is an enquiry, good for six months under CASL —
 *     used here for thirty days, because a reminder about a basket from May is
 *     not a reminder, it is a surprise.
 *
 * Then the same three removals for all of them. Anybody who said no to mail
 * like this, from anyone. Anybody already holding a ticket to the night being
 * sold — being asked to buy what you have bought reads as the organizer not
 * knowing who you are. And anybody this organizer has written a campaign to
 * in the last week, whichever list it came from, so a busy month is not a
 * daily email.
 */
class Audiences
{
    public const PAST_ATTENDEES_YEARS = 2;

    public const ABANDONED_DAYS = 30;

    /** One campaign per organizer per address per this many days. */
    public const QUIET_DAYS = 7;

    public const LABELS = [
        'followers' => 'People who follow you',
        'past_attendees' => 'People who came to your events in the last two years',
        'abandoned' => 'People who started buying for this event and stopped',
    ];

    /**
     * Everybody on the list, and the ones who will be written to.
     *
     * @return array{all: list<string>, reachable: list<string>}
     */
    public function resolve(string $organizationId, string $audience, ?Event $event): array
    {
        $all = $this->normalised(match ($audience) {
            'followers' => $this->followers($organizationId),
            'past_attendees' => $this->pastAttendees($organizationId),
            'abandoned' => $event ? $this->abandoned($event) : [],
            default => throw new \InvalidArgumentException("Unknown audience {$audience}."),
        });

        $reachable = EmailPreference::marketable($all);

        if ($event !== null) {
            $reachable = array_values(array_diff($reachable, $this->holders($event)));
        }

        $reachable = array_values(array_diff($reachable, $this->recentlyWritten($organizationId, $reachable)));

        return ['all' => $all, 'reachable' => $reachable];
    }

    /** @return list<string> */
    private function followers(string $organizationId): array
    {
        return OrganizationFollow::query()
            ->where('organization_follows.organization_id', $organizationId)
            ->join('users', 'users.id', '=', 'organization_follows.user_id')
            ->whereNull('users.deleted_at')
            ->whereNotNull('users.email')
            ->pluck('users.email')
            ->all();
    }

    /**
     * Held a ticket that stayed valid to a night of theirs that has happened.
     *
     * A refunded ticket is not a purchase that stood; a ticket to a night
     * still to come is somebody who will hear from the event itself.
     *
     * @return list<string>
     */
    private function pastAttendees(string $organizationId): array
    {
        return Ticket::query()
            ->join('events', 'events.id', '=', 'tickets.event_id')
            ->where('events.organization_id', $organizationId)
            ->whereBetween('events.starts_at', [now()->subYears(self::PAST_ATTENDEES_YEARS), now()])
            ->whereIn('tickets.status', ['valid', 'checked_in'])
            ->whereNotNull('tickets.owner_email')
            ->distinct()
            ->pluck('tickets.owner_email')
            ->all();
    }

    /**
     * Gave an address for this night and never finished paying.
     *
     * @return list<string>
     */
    private function abandoned(Event $event): array
    {
        $bought = Order::query()
            ->where('event_id', $event->id)
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->whereNotNull('buyer_email')
            ->pluck('buyer_email')
            ->map(fn (string $email) => EmailPreference::normalise($email))
            ->flip();

        return Order::query()
            ->where('event_id', $event->id)
            ->whereIn('status', ['cancelled', 'failed'])
            ->where('created_at', '>=', now()->subDays(self::ABANDONED_DAYS))
            ->whereNotNull('buyer_email')
            ->pluck('buyer_email')
            // Somebody whose second try went through is not abandoned, and a
            // refund is not a basket they walked away from.
            ->reject(fn (string $email) => $bought->has(EmailPreference::normalise($email)))
            ->all();
    }

    /** @return list<string> everybody already holding a live ticket to this night */
    private function holders(Event $event): array
    {
        return $this->normalised($event->tickets()
            ->whereIn('status', ['valid', 'checked_in'])
            ->whereNotNull('owner_email')
            ->pluck('owner_email')
            ->all());
    }

    /**
     * Among these, the ones this organizer wrote a campaign to this week.
     *
     * @param  list<string>  $emails
     * @return list<string>
     */
    private function recentlyWritten(string $organizationId, array $emails): array
    {
        // In batches, for the same reason as EmailPreference::wanting — one
        // bound parameter per address runs out at 65,535.
        return collect(array_chunk($emails, 5000))
            ->flatMap(fn (array $batch) => DB::table('campaign_deliveries')
                ->where('organization_id', $organizationId)
                ->whereIn('email', $batch)
                ->where('created_at', '>=', now()->subDays(self::QUIET_DAYS))
                ->distinct()
                ->pluck('email'))
            ->all();
    }

    /**
     * @param  list<string>  $emails
     * @return list<string>
     */
    private function normalised(array $emails): array
    {
        return array_values(array_unique(array_map(EmailPreference::normalise(...), array_filter($emails))));
    }
}
