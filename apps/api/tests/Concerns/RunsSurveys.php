<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\EventSurvey;
use App\Models\Organization;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Disputes\EventCompletions;
use App\Services\Surveys\SurveySender;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * A night that is over, the people who held tickets to it, and somebody at
 * the organization to look at what they said.
 */
trait RunsSurveys
{
    protected Organization $org;

    protected Event $event;

    protected TicketType $type;

    protected function aNight(array $overrides = [], ?Organization $organization = null): Event
    {
        $this->org ??= Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights-'.Str::lower(Str::random(6))]);

        $startsAt = now()->addDay()->setTime(22, 0);

        $event = Event::create(array_merge([
            'organization_id' => ($organization ?? $this->org)->id,
            'slug' => 'afro-fest-'.Str::lower(Str::random(6)),
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(5),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ], $overrides));

        $this->type = TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);

        return $event;
    }

    protected function holder(string $email, int $admitted = 1, string $status = 'valid', ?Event $event = null): Ticket
    {
        $event ??= $this->event;

        return Ticket::create([
            'event_id' => $event->id,
            'ticket_type_id' => TicketType::query()->where('event_id', $event->id)->value('id'),
            'code' => strtoupper(Str::random(4)).'-'.strtoupper(Str::random(8)),
            'owner_email' => $email,
            'holder_name' => 'Someone',
            'status' => $admitted > 0 && $status === 'valid' ? 'checked_in' : $status,
            'admits' => max(1, $admitted),
            'admitted_count' => $admitted,
        ]);
    }

    /** The door's record of the night, which the hourly run waits for. */
    protected function writtenDown(?Event $event = null): void
    {
        app(EventCompletions::class)->record($event ?? $this->event);
    }

    /** Hours after the night ended. */
    protected function hoursAfter(float $hours, ?Event $event = null): void
    {
        $this->travelTo(($event ?? $this->event)->ends_at->copy()->addMinutes((int) round($hours * 60)));
    }

    protected function asMember(Role $role, ?Organization $organization = null): User
    {
        $user = User::factory()->create();

        ($organization ?? $this->org)->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($user->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        return $user;
    }

    /** The night's survey sent, to whoever is holding tickets now. */
    protected function sent(?Event $event = null): EventSurvey
    {
        $survey = app(SurveySender::class)->start($event ?? $this->event);
        $this->assertNotNull($survey);

        return $survey;
    }

    /**
     * Somebody answering, straight into the table: for the results, where
     * how the answer arrived is not what is being tested.
     *
     * @param  array<string, mixed>  $answers
     */
    protected function answered(EventSurvey $survey, string $email, array $answers): SurveyResponse
    {
        $invitation = SurveyInvitation::query()->firstOrCreate(
            ['event_survey_id' => $survey->id, 'email' => strtolower($email)],
            ['token' => SurveySender::token(), 'sent_at' => now()],
        );

        $invitation->forceFill(['responded_at' => now()])->save();

        return SurveyResponse::create([
            'invitation_id' => $invitation->id,
            'event_survey_id' => $survey->id,
            'answers' => $answers,
            'created_at' => now(),
        ]);
    }
}
