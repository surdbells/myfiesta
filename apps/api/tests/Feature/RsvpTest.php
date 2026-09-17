<?php

namespace Tests\Feature;

use App\Exceptions\CheckoutException;
use App\Models\Event;
use App\Models\EventQuestion;
use App\Models\Guest;
use App\Models\Organization;
use App\Models\Rsvp;
use App\Models\Ticket;
use App\Services\Door\CheckInService;
use App\Services\Door\ScanOutcome;
use App\Services\Invites\RsvpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invites+, as a module.
 *
 * The tests that matter most here are the ones showing reuse: an accepted RSVP
 * produces an ordinary ticket, and the existing door scanner admits it without
 * knowing a wedding is different from a club night.
 */
class RsvpTest extends TestCase
{
    use RefreshDatabase;

    private RsvpService $rsvp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rsvp = app(RsvpService::class);
    }

    private function wedding(): Event
    {
        return Event::create([
            'organization_id' => Organization::create(['name' => 'Ada & Sam', 'slug' => 'ada-sam-'.uniqid()])->id,
            'slug' => 'w-'.uniqid(),
            'title' => 'Ada & Sam',
            'kind' => 'invitation',
            'currency' => 'CAD',
            'starts_at' => now()->addMonths(3),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);
    }

    private function guest(Event $event, int $partySize = 2): Guest
    {
        return Guest::create([
            'event_id' => $event->id,
            'name' => 'Chidi Okafor',
            'email' => 'chidi-'.uniqid().'@example.com',
            'max_party_size' => $partySize,
            'invite_token' => Guest::freshToken(),
            'invited_at' => now(),
        ]);
    }

    public function test_accepting_issues_a_ticket_per_person(): void
    {
        $event = $this->wedding();
        $guest = $this->guest($event);

        $this->rsvp->respond($guest, 'attending', 2);

        // The whole argument for Invites+ being a module rather than a product.
        $this->assertSame(2, Ticket::where('event_id', $event->id)->where('status', 'valid')->count());
    }

    public function test_the_existing_door_scanner_admits_a_wedding_guest(): void
    {
        $event = $this->wedding();
        $guest = $this->guest($event, 1);

        $this->rsvp->respond($guest, 'attending', 1);

        $ticket = Ticket::where('event_id', $event->id)->first();
        $outcome = app(CheckInService::class)->scan($ticket->code, $event->id);

        // No new scanner, no new check-in path, no new duplicate protection.
        $this->assertTrue($outcome->admittedAnyone());
        $this->assertSame('checked_in', $ticket->refresh()->status);
    }

    public function test_a_wedding_guest_cannot_be_admitted_twice(): void
    {
        $event = $this->wedding();
        $guest = $this->guest($event, 1);
        $this->rsvp->respond($guest, 'attending', 1);

        $ticket = Ticket::where('event_id', $event->id)->first();
        $door = app(CheckInService::class);

        $door->scan($ticket->code, $event->id);
        $outcome = $door->scan($ticket->code, $event->id);

        $this->assertSame(ScanOutcome::DUPLICATE, $outcome->result);
    }

    public function test_declining_issues_nothing(): void
    {
        $event = $this->wedding();
        $guest = $this->guest($event);

        $rsvp = $this->rsvp->respond($guest, 'declined');

        $this->assertSame(0, $rsvp->party_size);
        $this->assertSame(0, Ticket::count());
    }

    public function test_a_changed_mind_supersedes_rather_than_overwrites(): void
    {
        $event = $this->wedding();
        $guest = $this->guest($event);

        $this->rsvp->respond($guest, 'attending', 2);
        $this->rsvp->respond($guest, 'declined');

        // Hosts cater from these numbers, so a change of mind is information.
        $this->assertSame(2, Rsvp::where('guest_id', $guest->id)->count());
        $this->assertSame(1, Rsvp::live()->where('guest_id', $guest->id)->count());
        $this->assertSame('declined', $guest->refresh()->rsvp->status);
    }

    public function test_a_shrinking_party_gives_tickets_back(): void
    {
        $event = $this->wedding();
        $guest = $this->guest($event, 4);

        $this->rsvp->respond($guest, 'attending', 4);
        $this->assertSame(4, Ticket::where('status', 'valid')->count());

        $this->rsvp->respond($guest, 'attending', 2);

        // Otherwise the host caters for phantoms.
        $this->assertSame(2, Ticket::where('status', 'valid')->count());
        $this->assertSame(2, Ticket::where('status', 'void')->count());
    }

    public function test_declining_after_accepting_voids_the_tickets(): void
    {
        $event = $this->wedding();
        $guest = $this->guest($event);

        $this->rsvp->respond($guest, 'attending', 2);
        $this->rsvp->respond($guest, 'declined');

        $this->assertSame(0, Ticket::where('status', 'valid')->count());
    }

    public function test_a_voided_ticket_does_not_open_the_door(): void
    {
        $event = $this->wedding();
        $guest = $this->guest($event, 1);

        $this->rsvp->respond($guest, 'attending', 1);
        $ticket = Ticket::first();
        $this->rsvp->respond($guest, 'declined');

        $outcome = app(CheckInService::class)->scan($ticket->code, $event->id);

        $this->assertSame(ScanOutcome::VOID, $outcome->result);
    }

    public function test_a_guest_cannot_bring_more_people_than_invited(): void
    {
        $event = $this->wedding();
        $guest = $this->guest($event, 2);

        // The host decided how many this admits. Needing more means asking.
        $this->expectException(CheckoutException::class);
        $this->rsvp->respond($guest, 'attending', 5);
    }

    public function test_required_questions_must_be_answered_to_accept(): void
    {
        $event = $this->wedding();
        $guest = $this->guest($event);

        EventQuestion::create([
            'event_id' => $event->id,
            'label' => 'Dietary requirements',
            'type' => 'text',
            'required' => true,
        ]);

        $this->expectException(CheckoutException::class);
        $this->rsvp->respond($guest, 'attending', 1);
    }

    public function test_a_decline_is_never_blocked_by_a_required_question(): void
    {
        $event = $this->wedding();
        $guest = $this->guest($event);

        EventQuestion::create([
            'event_id' => $event->id,
            'label' => 'Dietary requirements',
            'type' => 'text',
            'required' => true,
        ]);

        // An unanswered decline is worse for the host than an unanswered
        // question, so declining never demands one.
        $rsvp = $this->rsvp->respond($guest, 'declined');

        $this->assertSame('declined', $rsvp->status);
    }

    public function test_answers_are_stored_queryably(): void
    {
        $event = $this->wedding();
        $guest = $this->guest($event);

        $question = EventQuestion::create([
            'event_id' => $event->id,
            'label' => 'Meal',
            'type' => 'choice',
            'options' => ['Fish', 'Vegetarian'],
            'required' => true,
        ]);

        $rsvp = $this->rsvp->respond($guest, 'attending', 1, [$question->id => 'Vegetarian']);

        // "How many vegetarians" has to be answerable, so answers are jsonb
        // rather than a text blob.
        $this->assertSame(['Vegetarian'], $rsvp->answers->first()->value);
    }

    public function test_a_ticketed_event_does_not_take_rsvps(): void
    {
        $event = $this->wedding();
        $event->update(['kind' => 'ticketed']);
        $guest = $this->guest($event);

        $this->expectException(CheckoutException::class);
        $this->rsvp->respond($guest, 'attending', 1);
    }

    public function test_invite_tokens_are_unguessable_and_hidden(): void
    {
        $event = $this->wedding();
        $guest = $this->guest($event);

        // The token authenticates the guest, so it must not be derivable from
        // their name or address, and must not leak through serialisation.
        $this->assertSame(64, strlen($guest->invite_token));
        $this->assertArrayNotHasKey('invite_token', $guest->toArray());
        $this->assertNotSame($guest->invite_token, $this->guest($event)->invite_token);
    }
}
