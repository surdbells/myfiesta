<?php

namespace App\Services\Surveys;

use App\Enums\EventStatus;
use App\Mail\SurveyInvitationMail;
use App\Models\EmailPreference;
use App\Models\Event;
use App\Models\EventSurvey;
use App\Models\Organization;
use App\Models\SurveyInvitation;
use App\Models\User;
use App\Services\Checkout\TurnedAway;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Asking the people who came, once, the morning after.
 *
 * A night is asked about once its door has been written down
 * (event_completions, which waits for the last offline phones to send their
 * scans) and its delay has passed. Who is asked is the door's answer: every
 * address holding a ticket somebody was let in on. A night whose door scanned
 * nobody — run from a printed list, or not at all — asks every live holder
 * instead, since "nobody came" is not what happened.
 *
 * Sent in two steps, each safe to repeat:
 *
 *   start    under a lock on the night's survey row: fix the questions, mark
 *            it sent, and write one invitation per address. The unique
 *            (survey, address) index is what stops a second run, or a run
 *            that died halfway, from asking anybody twice.
 *   deliver  claim each invitation (sent_at, set only where it is still
 *            empty) and only then queue its email. A run that finds it
 *            claimed leaves it alone.
 *
 * A survey is mail somebody did not ask for, so an address that has said no
 * to that (EmailPreference marketing) is never invited, and every email
 * carries the way out.
 */
class SurveySender
{
    /**
     * How long after its time a night can still be asked about. Past this the
     * night is forgotten, and a deploy must not survey every night in the
     * last year and a half the first time it runs.
     */
    public const WINDOW_DAYS = 7;

    /** How long a run keeps finishing a night another run started. */
    private const RESUME_HOURS = 24;

    public function __construct(private readonly EventSurveys $surveys) {}

    /** Every night that is due, and any left half-sent. Returns emails queued. */
    public function sendDue(): int
    {
        $queued = 0;

        foreach ($this->dueEventIds() as $eventId) {
            $event = Event::query()->with('organization')->find($eventId);

            if ($event === null) {
                continue;
            }

            $survey = $this->start($event);

            if ($survey !== null) {
                $queued += $this->deliver($survey);
            }
        }

        // A run that died after writing its invitations and before emailing
        // them all: the rest go now, each still claimed one at a time.
        EventSurvey::query()
            ->where('sent_at', '>=', now()->subHours(self::RESUME_HOURS))
            ->whereHas('invitations', fn ($query) => $query->whereNull('sent_at'))
            ->get()
            ->each(function (EventSurvey $survey) use (&$queued) {
                $queued += $this->deliver($survey);
            });

        return $queued;
    }

    /**
     * Send one night's survey now, rather than when the hourly run gets to
     * it. Returns how many people were asked, or null when it had already
     * gone (or cannot go).
     */
    public function sendNow(Event $event, User $by): ?int
    {
        $survey = $this->start($event, $by);

        if ($survey === null) {
            return null;
        }

        $this->deliver($survey);

        return $survey->invitations()->count();
    }

    /**
     * Fix the questions and write the invitations, once. Null when the night
     * was already sent, or has nothing to send.
     */
    public function start(Event $event, ?User $by = null): ?EventSurvey
    {
        $row = $this->surveys->ensure($event);

        return DB::transaction(function () use ($event, $row, $by) {
            $survey = EventSurvey::query()->whereKey($row->id)->lockForUpdate()->first();

            if ($survey === null || $survey->sent_at !== null) {
                return null;
            }

            $template = $this->surveys->template($survey);

            if ($template === null) {
                return null;
            }

            // The questions as they are sent, kept with the night: an edit to
            // the template tomorrow changes neither what was asked nor how
            // the answers are read.
            $survey->forceFill([
                'template_id' => $template->id,
                'questions' => $template->questions,
                'sent_at' => now(),
                'sent_by' => $by?->id,
            ])->save();

            foreach (array_chunk($this->recipients($event), 500) as $batch) {
                SurveyInvitation::query()->insertOrIgnore(array_map(fn (string $email) => [
                    'id' => (string) Str::uuid7(),
                    'event_survey_id' => $survey->id,
                    'token' => self::token(),
                    'email' => $email,
                    'created_at' => now(),
                ], $batch));
            }

            return $survey;
        });
    }

    /** Email every invitation nobody has claimed yet. Returns how many. */
    public function deliver(EventSurvey $survey): int
    {
        $queued = 0;

        SurveyInvitation::query()
            ->where('event_survey_id', $survey->id)
            ->whereNull('sent_at')
            ->chunkById(200, function ($invitations) use (&$queued) {
                foreach ($invitations as $invitation) {
                    // Claimed before the email is queued, never after: two
                    // runs at once both reach this line, and only one of them
                    // changes the row.
                    $claimed = SurveyInvitation::query()
                        ->whereKey($invitation->id)
                        ->whereNull('sent_at')
                        ->update(['sent_at' => now()]);

                    if ($claimed !== 1) {
                        continue;
                    }

                    $preference = EmailPreference::forEmail($invitation->email);

                    // Said no since the list was made. Claimed all the same,
                    // so nothing tries again.
                    if (! $preference->wantsMarketing()) {
                        continue;
                    }

                    Mail::to($invitation->email)->queue(new SurveyInvitationMail($invitation, $preference));
                    $queued++;
                }
            });

        return $queued;
    }

    /**
     * Who is asked: the addresses holding a ticket somebody was let in on,
     * or every live holder when the door scanned nobody. Lowercased, once
     * each, and only those that still take mail they did not ask for.
     *
     * @return list<string>
     */
    public function recipients(Event $event): array
    {
        $holders = fn () => DB::table('tickets')
            ->where('event_id', $event->id)
            ->whereNotNull('owner_email')
            ->where('owner_email', '!=', '');

        $emails = $holders()
            ->where('admitted_count', '>', 0)
            // Let in and then refunded or voided: asking them how the night
            // went reads as asking for the money back again.
            ->whereNotIn('status', ['refunded', 'void'])
            ->distinct()
            ->pluck(DB::raw('lower(owner_email) as email'))
            ->all();

        if ($emails === []) {
            $emails = $holders()
                ->whereIn('status', ['valid', 'checked_in', 'listed'])
                ->distinct()
                ->pluck(DB::raw('lower(owner_email) as email'))
                ->all();
        }

        return array_values(EmailPreference::marketable($emails));
    }

    /**
     * The nights the hourly run sends: written down by the door, still on,
     * due and not yet a week past it.
     *
     * @return list<string>
     */
    private function dueEventIds(): array
    {
        return $this->due()
            ->where('organizations.surveys_enabled', true)
            ->orderBy('events.id')
            ->pluck('events.id')
            ->all();
    }

    /**
     * How many of an organization's nights the next hourly run would ask
     * about with its surveys switched on: what switching them back on does
     * within the hour, so the console can say so first.
     */
    public function dueCountFor(Organization $organization): int
    {
        return $this->due()->where('events.organization_id', $organization->id)->count();
    }

    /**
     * Nights due, the organization's switch aside. EventSurveys::dueAt and
     * tooLate are the same rule for one night.
     *
     * @return Builder<Event>
     */
    private function due(): Builder
    {
        $listedEnd = "coalesce(events.ends_at, events.starts_at + interval '".TurnedAway::HOURS_WITHOUT_AN_END." hours')";
        $delay = 'greatest(coalesce(event_surveys.send_delay_hours, '.EventSurvey::DEFAULT_DELAY_HOURS.'), '.$this->surveys->shortestDelayHours().')';
        $dueAt = "{$listedEnd} + ({$delay} * interval '1 hour')";

        return Event::query()
            ->join('organizations', 'organizations.id', '=', 'events.organization_id')
            ->leftJoin('event_surveys', 'event_surveys.event_id', '=', 'events.id')
            ->whereNull('events.taken_down_at')
            ->where('events.status', '!=', EventStatus::Cancelled->value)
            ->whereNull('event_surveys.sent_at')
            ->where(fn ($query) => $query->whereNull('event_surveys.id')->orWhere('event_surveys.enabled', true))
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('event_completions')
                ->whereColumn('event_completions.event_id', 'events.id'))
            ->whereRaw("{$dueAt} <= ?", [now()])
            ->whereRaw("{$dueAt} > ?", [now()->subDays(self::WINDOW_DAYS)]);
    }

    /** The link's whole credential: long enough that guessing one is hopeless. */
    public static function token(): string
    {
        return Str::random(48);
    }
}
