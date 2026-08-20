<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketScan;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\TicketIssuer;
use App\Services\Door\CheckInService;
use App\Services\Door\ScanOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The door.
 *
 * Check-in failures are public, time-boxed, and cannot be retried later: the
 * queue is outside and the event has started. These are the cases that decide
 * whether that goes well.
 */
class CheckInTest extends TestCase
{
    use RefreshDatabase;

    private CheckInService $door;

    protected function setUp(): void
    {
        parent::setUp();
        $this->door = app(CheckInService::class);
    }

    private function event(string $title = 'Tonight'): Event
    {
        return Event::create([
            'organization_id' => Organization::create(['name' => 'N', 'slug' => 'n-'.uniqid()])->id,
            'slug' => 'e-'.uniqid(),
            'title' => $title,
            'currency' => 'CAD',
            'starts_at' => now()->addHours(2),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);
    }

    private function ticketFor(Event $event): Ticket
    {
        $type = TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);

        return app(TicketIssuer::class)
            ->issueComp($event->id, $type->id, 'guest@example.com', 'Ada Guest');
    }

    public function test_a_valid_ticket_is_admitted_once(): void
    {
        $event = $this->event();
        $ticket = $this->ticketFor($event);

        $outcome = $this->door->scan($ticket->code, $event->id);

        $this->assertTrue($outcome->admittedAnyone());
        $this->assertSame('checked_in', $ticket->refresh()->status);
        $this->assertNotNull($ticket->checked_in_at);
    }

    public function test_a_second_scan_is_refused_and_says_when(): void
    {
        $event = $this->event();
        $ticket = $this->ticketFor($event);

        $this->door->scan($ticket->code, $event->id);
        $outcome = $this->door->scan($ticket->code, $event->id);

        $this->assertFalse($outcome->admittedAnyone());
        $this->assertSame(ScanOutcome::DUPLICATE, $outcome->result);
        // "Already scanned" is an accusation. Saying when turns it into
        // something the person on the door can actually resolve.
        $this->assertStringContainsString('Already scanned', $outcome->message);
    }

    public function test_a_real_ticket_for_another_event_is_distinguished_from_a_forgery(): void
    {
        $tonight = $this->event('Tonight');
        $tomorrow = $this->event('Tomorrow');
        $ticket = $this->ticketFor($tomorrow);

        $outcome = $this->door->scan($ticket->code, $tonight->id);

        // A guest who turned up on the wrong night is a different conversation
        // from someone presenting a forgery, and the door needs to know which.
        $this->assertSame(ScanOutcome::WRONG_EVENT, $outcome->result);
        $this->assertSame('valid', $ticket->refresh()->status, 'Their real ticket must survive.');
    }

    public function test_an_unknown_code_is_refused(): void
    {
        $event = $this->event();

        $outcome = $this->door->scan('XXXX-XXXXXXXX', $event->id);

        $this->assertSame(ScanOutcome::NOT_FOUND, $outcome->result);
    }

    public function test_a_refunded_ticket_does_not_admit(): void
    {
        $event = $this->event();
        $ticket = $this->ticketFor($event);
        $ticket->update(['status' => 'refunded']);

        $outcome = $this->door->scan($ticket->code, $event->id);

        $this->assertSame(ScanOutcome::VOID, $outcome->result);
    }

    public function test_every_attempt_is_logged_including_the_failures(): void
    {
        $event = $this->event();
        $ticket = $this->ticketFor($event);

        $this->door->scan($ticket->code, $event->id);
        $this->door->scan($ticket->code, $event->id);
        $this->door->scan('NOPE-NOPENOPE', $event->id);

        // A scanner that only records successes cannot answer what happened at
        // a contested door, which is precisely when someone asks.
        $this->assertSame(3, TicketScan::count());
        $this->assertSame(
            ['accepted', 'duplicate', 'not_found'],
            TicketScan::orderBy('scanned_at')->pluck('result')->all(),
        );

        // Unknown codes are recorded too: a door reporting them all night is
        // worth knowing about.
        $this->assertSame('NOPE-NOPENOPE', TicketScan::where('result', 'not_found')->first()->scanned_code);
    }

    public function test_the_scanner_is_recorded_against_the_admission(): void
    {
        $event = $this->event();
        $ticket = $this->ticketFor($event);
        $staff = User::factory()->create();

        $this->door->scan($ticket->code, $event->id, $staff);

        $this->assertSame($staff->id, $ticket->refresh()->checked_in_by);
        $this->assertSame($staff->id, TicketScan::first()->scanned_by);
    }

    public function test_codes_are_matched_case_insensitively(): void
    {
        $event = $this->event();
        $ticket = $this->ticketFor($event);

        // Codes get typed by hand when a camera will not read a cracked screen.
        $outcome = $this->door->scan(strtolower($ticket->code), $event->id);

        $this->assertTrue($outcome->admittedAnyone());
    }

    public function test_surrounding_whitespace_does_not_defeat_a_scan(): void
    {
        $event = $this->event();
        $ticket = $this->ticketFor($event);

        $outcome = $this->door->scan("  {$ticket->code}\n", $event->id);

        $this->assertTrue($outcome->admittedAnyone());
    }
}
