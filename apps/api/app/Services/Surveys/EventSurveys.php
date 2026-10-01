<?php

namespace App\Services\Surveys;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventCompletion;
use App\Models\EventSurvey;
use App\Models\Organization;
use App\Models\SurveyTemplate;
use App\Services\Checkout\TurnedAway;
use App\Services\Door\DoorPasses;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One night's survey as it stands: what it will ask, whether it will go, and
 * when.
 *
 * A night with no row is surveyed with myFiesta's own questions, 18 hours
 * after it ends, unless its organization turned surveys off. A row is made
 * only when an organizer changes one of those, or when it is sent.
 *
 * Every time said here is the time the hourly run really sends: never before
 * the door's final count is written down (EventCompletions), on the hour, and
 * not at all once the night is more than a week behind it.
 */
class EventSurveys
{
    /** The row for a night, if it has one. */
    public function find(Event $event): ?EventSurvey
    {
        return EventSurvey::query()->where('event_id', $event->id)->first();
    }

    /**
     * The template a night will be sent: the one chosen for it, or
     * myFiesta's own when none was, or the chosen one has been archived.
     */
    public function template(?EventSurvey $survey): ?SurveyTemplate
    {
        $chosen = $survey?->template_id !== null ? $survey->template : null;

        if ($chosen !== null && $chosen->archived_at === null) {
            return $chosen;
        }

        return SurveyTemplate::platformDefault();
    }

    /**
     * The questions it asks: as sent, once it has been; until then, its
     * template's as they are now, so an edit to a template reaches every
     * night still to be surveyed with it.
     *
     * @return list<array{id: string, type: string, label: string, options: list<string>, required: bool}>
     */
    public function questions(?EventSurvey $survey): array
    {
        if ($survey?->sent_at !== null) {
            return $survey->questions ?? [];
        }

        return $this->template($survey)->questions ?? [];
    }

    /**
     * When the night ended, as listed: its end, or twelve hours after it
     * starts when it gives none (the same rule the door and the dispute
     * record use).
     */
    public function endsAt(Event $event): CarbonImmutable
    {
        return CarbonImmutable::parse($event->ends_at ?? $event->starts_at->addHours(TurnedAway::HOURS_WITHOUT_AN_END));
    }

    /** The delay the organizer chose, or the default. */
    public function delayHours(?EventSurvey $survey): int
    {
        return $survey->send_delay_hours ?? EventSurvey::DEFAULT_DELAY_HOURS;
    }

    /**
     * How long after the listed end the door's final count is written down
     * (EventCompletions): the door's grace, then the hours an offline phone
     * is given to send its last scans.
     */
    public function countedAfterHours(): int
    {
        return DoorPasses::GRACE_HOURS + (int) config('disputes.completion.after_door_closes_hours', 12);
    }

    /**
     * The shortest delay there is: an hour past the door's final count, so
     * the hourly run that writes it down always comes before the one that
     * reads it. Asked any sooner, the people let in on an offline phone would
     * not be on the list yet.
     */
    public function shortestDelayHours(): int
    {
        return $this->countedAfterHours() + 1;
    }

    /** When the door's final count is in, and the survey can first be sent. */
    public function finalCountAt(Event $event): CarbonImmutable
    {
        return $this->endsAt($event)->addHours($this->countedAfterHours())->ceilHour();
    }

    /** Whether the door's final count has been written down. */
    public function counted(Event $event): bool
    {
        return EventCompletion::query()->where('event_id', $event->id)->exists();
    }

    /**
     * When it is due: its delay after the end, and never before the door's
     * final count, whatever an older row says. SurveySender::dueEventIds is
     * the same rule in SQL.
     */
    public function dueAt(Event $event, ?EventSurvey $survey): CarbonImmutable
    {
        return $this->endsAt($event)->addHours(max($this->delayHours($survey), $this->shortestDelayHours()));
    }

    /** When the hourly run will send it, if nothing stops it: the first run once it is due. */
    public function sendsAt(Event $event, ?EventSurvey $survey): CarbonImmutable
    {
        return $this->dueAt($event, $survey)->ceilHour();
    }

    /**
     * Past the week in which a night is asked about. The hourly run leaves
     * it alone, and so does sending it now: "thanks for coming" a month on
     * reads as a mistake, and nobody remembers the sound by then.
     */
    public function tooLate(Event $event, ?EventSurvey $survey): bool
    {
        return $this->dueAt($event, $survey)->addDays(SurveySender::WINDOW_DAYS)->lte(now());
    }

    /**
     * Why it will not go, in the organizer's words, or null when it will (or
     * already has).
     */
    public function stoppedBecause(Event $event, ?EventSurvey $survey, ?Organization $organization = null): ?string
    {
        $organization ??= $event->organization;

        return match (true) {
            $survey?->sent_at !== null => null,
            $event->status === EventStatus::Cancelled->value => 'The event was cancelled, so nobody is asked about it.',
            $event->taken_down_at !== null => 'The event was taken down, so nobody is asked about it.',
            $this->tooLate($event, $survey) => 'This event ended too long ago to ask about. People are asked within a week, while they still remember the night.',
            ! ($organization->surveys_enabled ?? true) => 'Surveys are switched off for your organization.',
            $survey !== null && ! $survey->enabled => 'The survey is switched off for this event.',
            $this->template($survey) === null => 'There is no survey to send.',
            $this->dueAt($event, $survey)->lte(now()) && ! $this->counted($event) => $this->soldNothing($event)
                ? 'Nobody held a ticket to this event, so there is nobody to ask.'
                : 'Waiting for the door’s final count. It goes on the hourly run after that.',
            default => null,
        };
    }

    /**
     * Whether the next hourly run would send it with both switches on: over,
     * counted at the door, due, and still within the week. What switching a
     * survey back on does straight away, so the console can ask first.
     */
    public function dueNow(Event $event, ?EventSurvey $survey): bool
    {
        return $survey?->sent_at === null
            && $event->status !== EventStatus::Cancelled->value
            && $event->taken_down_at === null
            && ! $this->tooLate($event, $survey)
            && $this->template($survey) !== null
            && $this->dueAt($event, $survey)->lte(now())
            && $this->counted($event);
    }

    /** Whether the night is over, so it can be asked about. */
    public function isOver(Event $event): bool
    {
        return $this->endsAt($event)->lte(now());
    }

    private function soldNothing(Event $event): bool
    {
        return ! DB::table('tickets')->where('event_id', $event->id)->exists();
    }

    /**
     * The row for a night, made when it has none, with the defaults it was
     * already using. Safe to call twice at once: the unique event_id decides.
     */
    public function ensure(Event $event): EventSurvey
    {
        $existing = $this->find($event);

        if ($existing !== null) {
            return $existing;
        }

        EventSurvey::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'event_id' => $event->id,
            'template_id' => null,
            'questions' => json_encode($this->template(null)->questions ?? []),
            'enabled' => true,
            'send_delay_hours' => EventSurvey::DEFAULT_DELAY_HOURS,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return EventSurvey::query()->where('event_id', $event->id)->firstOrFail();
    }
}
