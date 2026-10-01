<?php

namespace App\Services\Events;

use App\Enums\EventStatus;
use App\Enums\Permission;
use App\Mail\EventScheduledSale;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Nights that go on sale at a time the organizer set (events:go-live).
 *
 * At that time the night is sent the way the organizer would send it
 * (EventReviews::submit), as the member who set the time — or, for a date a
 * repeating night scheduled, as whoever turned that on for the series. So it
 * goes on sale only where pressing the button then would put it on sale: an
 * approval stands for it as it is, or it is the approved night of its series
 * on another date. Changed since it was approved, it goes to myFiesta for
 * review instead and on sale once approved. The organizers are emailed what
 * became of it either way (EventScheduledSale) — the dates of one series
 * that went in the same run in one email.
 *
 * Three things are asked again at that moment rather than trusted from when
 * the time was set, because each can change in between: whether the
 * organization is suspended (a suspended night waits until it is lifted,
 * time kept), whether staff took it down (it stays down), and whether the
 * member who set the time may still put events on sale (if not, nothing is
 * sent, the time is dropped, and the organizers are told why). A series whose
 * member can no longer do it stops putting its dates on sale altogether, so
 * the organizers hear it once rather than once a date.
 *
 * Running twice sends nothing twice. Two runs that pick the same night meet
 * at submit's lock, and the second finds it already sent; a night that cannot
 * be sent is claimed by clearing its time before anybody is told. Under that
 * lock the time is read again (stillDue), so a time the organizer cleared or
 * moved after the run picked the night is honoured.
 */
final class ScheduledGoLive
{
    /** How many nights one run takes. The rest are due again a minute later. */
    public const BATCH = 200;

    public const ON_SALE = EventScheduledSale::ON_SALE;

    public const IN_REVIEW = EventScheduledSale::IN_REVIEW;

    public const NOT_SENT = EventScheduledSale::NOT_SENT;

    /** Left for a later run, or already done by another. Nobody is told. */
    public const LEFT = 'left';

    public function __construct(
        private readonly EventReviews $reviews,
        private readonly Auditor $auditor,
    ) {}

    /**
     * Drafts whose time has come, of an organization that may sell.
     *
     * @return Builder<Event>
     */
    public function due(): Builder
    {
        return Event::query()
            ->where('status', EventStatus::Draft->value)
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', now())
            ->whereNull('taken_down_at')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('organizations')
                ->whereColumn('organizations.id', 'events.organization_id')
                ->whereNotNull('organizations.suspended_at'))
            ->orderBy('publish_at')
            ->orderBy('id');
    }

    /** @return array<string, int> how many nights came to each outcome */
    public function run(): array
    {
        $counts = [self::ON_SALE => 0, self::IN_REVIEW => 0, self::NOT_SENT => 0, self::LEFT => 0];
        $told = [];

        foreach ($this->due()->limit(self::BATCH)->get() as $event) {
            // A series' own dates together, every other night alone. Asked
            // first: sending it spends who set its time.
            $group = $this->bySeries($event) ? 'series:'.$event->series_id : 'event:'.$event->id;

            try {
                [$outcome, $reasons] = $this->send($event);
            } catch (Throwable $e) {
                // One night must not hold up every other organizer's. Its
                // time is kept, so the next run tries it again.
                report($e);
                [$outcome, $reasons] = [self::LEFT, []];
            }

            $counts[$outcome]++;

            if ($outcome !== self::LEFT) {
                $told[$group][] = [$event, $outcome, $reasons];
            }
        }

        foreach ($told as $group) {
            $this->tell($group);
        }

        return $counts;
    }

    /** Send one night whose time has come, and tell its organizers. */
    public function goLive(Event $event): string
    {
        [$outcome, $reasons] = $this->send($event);

        if ($outcome !== self::LEFT) {
            $this->tell([[$event, $outcome, $reasons]]);
        }

        return $outcome;
    }

    /**
     * Who the night goes on sale as: the member who set its time, or for a
     * date its series scheduled, whoever turned that on.
     */
    public function scheduler(Event $event): ?User
    {
        $id = $event->publish_scheduled_by;

        if ($id === null && $event->series_id !== null) {
            $series = EventSeries::query()->find($event->series_id);
            $id = $series?->auto_publish ? $series->auto_publish_by : null;
        }

        return $id === null ? null : User::query()->find($id);
    }

    /** @return array{0: string, 1: list<string>} the outcome, and why it was not sent */
    private function send(Event $event): array
    {
        $scheduler = $this->scheduler($event);
        $refusal = $this->whyNotAs($event, $scheduler);

        if ($refusal !== null || $scheduler === null) {
            return $this->notSent($event, [$refusal ?? 'Nobody who set the time can put events on sale now.'], $scheduler, stopSeries: true);
        }

        try {
            // Asked again under submit's lock: this night was read with the
            // rest of the run, and the organizer may have cleared or moved its
            // time since.
            $result = $this->reviews->submit($event, $scheduler, onSchedule: true, still: fn (Event $locked) => $this->stillDue($locked, $event));
        } catch (ReviewRefused $refused) {
            // Suspended between choosing it and sending it: it waits, as
            // every suspended night does, and goes when that is lifted.
            if ($refused->errorCode === 'organization_suspended') {
                return [self::LEFT, []];
            }

            return $this->notSent($event, $refused->reasons !== [] ? $refused->reasons : [$refused->getMessage()], $scheduler);
        }

        if ($result['outcome'] === 'left') {
            return [self::LEFT, []];
        }

        // Spent either way. Gone to review, it goes on sale once approved;
        // taken back from review later, it is an ordinary draft again.
        Event::query()->whereKey($event->id)->whereNotNull('publish_at')
            ->update(['publish_at' => null, 'publish_scheduled_by' => null]);

        return [match ($result['outcome']) {
            'published' => self::ON_SALE,
            'in_review' => self::IN_REVIEW,
            default => self::LEFT,
        }, []];
    }

    /**
     * Whether the night as it stands now is still the one this run picked: a
     * draft, its time come, set by the same member (or still by its series).
     */
    private function stillDue(Event $now, Event $picked): bool
    {
        return $now->status === EventStatus::Draft->value
            && $now->publish_at !== null
            && ! $now->publish_at->isFuture()
            && $now->publish_scheduled_by === $picked->publish_scheduled_by;
    }

    /** Why this member may not put this night on sale now, or null when they may. */
    private function whyNotAs(Event $event, ?User $scheduler): ?string
    {
        if ($scheduler === null) {
            return 'The member who set the time is no longer on your team.';
        }

        $name = trim((string) $scheduler->name) !== '' ? $scheduler->name : 'The member who set the time';

        if (! $scheduler->hasPermissionIn($event->organization_id, Permission::EventsPublish)) {
            return "{$name}, who set the time, can no longer put events on sale.";
        }

        // As the submit route asks (verified.email): putting a name in front
        // of the public waits for a proved address.
        if ($scheduler->email_verified_at === null) {
            return "{$name}, who set the time, has not confirmed their email address yet.";
        }

        return null;
    }

    /**
     * Nothing is sent. The time is dropped, so it is not tried again every
     * minute, and only the run that claimed it tells anybody.
     *
     * When it was the series' member who can no longer do it, the series
     * stops putting dates on sale: every other date would fail the same way,
     * each with an email of its own.
     *
     * @param  list<string>  $reasons
     * @return array{0: string, 1: list<string>}
     */
    private function notSent(Event $event, array $reasons, ?User $scheduler, bool $stopSeries = false): array
    {
        // Only the time this run read: one the organizer moved later in the
        // meantime is theirs, and is asked about when it comes.
        $claimed = Event::query()
            ->whereKey($event->id)
            ->where('status', EventStatus::Draft->value)
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', now())
            ->when(
                $event->publish_scheduled_by === null,
                fn (Builder $q) => $q->whereNull('publish_scheduled_by'),
                fn (Builder $q) => $q->where('publish_scheduled_by', $event->publish_scheduled_by),
            )
            ->update(['publish_at' => null, 'publish_scheduled_by' => null]);

        if ($claimed === 0) {
            return [self::LEFT, []];
        }

        if ($stopSeries && $this->bySeries($event)) {
            EventSeries::query()->whereKey($event->series_id)->update(['auto_publish' => false, 'auto_publish_by' => null]);

            // Its other dates waiting on the series, and none of their own.
            Event::query()
                ->where('series_id', $event->series_id)
                ->where('status', EventStatus::Draft->value)
                ->whereNull('publish_scheduled_by')
                ->whereNotNull('publish_at')
                ->update(['publish_at' => null]);

            $reasons[] = 'Its other dates will not go on sale by themselves either until somebody who can put events on sale turns that on again for the series.';
        }

        $this->auditor->record('event.scheduled_sale_not_sent', $event, $scheduler, metadata: ['reasons' => $reasons]);

        return [self::NOT_SENT, $reasons];
    }

    /**
     * A date its series put the time on, rather than one somebody set: told
     * about with its series' other dates.
     */
    private function bySeries(Event $event): bool
    {
        return $event->series_id !== null && $event->publish_scheduled_by === null;
    }

    /**
     * Tell the organizers what became of these nights, all of one series or
     * a single one: one email.
     *
     * @param  non-empty-list<array{0: Event, 1: string, 2: list<string>}>  $nights
     */
    private function tell(array $nights): void
    {
        [$first, $outcome, $reasons] = $nights[0];
        $public = rtrim((string) config('app.public_url'), '/');

        $dates = count($nights) === 1 ? [] : array_map(fn (array $night) => [
            'when' => $night[0]->starts_at->timezone($night[0]->timezone)->format('D j M Y, g:ia T'),
            'link' => $public.'/'.$night[0]->slug,
            'outcome' => $night[1],
            'reasons' => $night[2],
        ], $nights);

        foreach ($this->reviews->organizers($first) as $email) {
            Mail::to($email)->queue(new EventScheduledSale($first, $outcome, $reasons, $dates));
        }
    }
}
