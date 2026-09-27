<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketScan;
use App\Models\TicketType;
use App\Services\Checkout\TicketIssuer;
use App\Services\Door\CheckInService;
use App\Services\Door\ScanOutcome;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tickets that admit more than one person.
 *
 * A Couple admits two and a Table of 5 admits five, and those parties do not
 * reliably arrive together. Three at eleven and two at midnight is an ordinary
 * evening — and under a binary checked-in flag the first scan consumes the
 * ticket and the rest of the table are turned away holding something the system
 * says is spent.
 */
class PartialAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private CheckInService $door;

    protected function setUp(): void
    {
        parent::setUp();

        $this->door = app(CheckInService::class);

        $this->event = Event::create([
            'organization_id' => Organization::create(['name' => 'N', 'slug' => 'n-'.uniqid()])->id,
            'slug' => 'e-'.uniqid(),
            'title' => 'Tonight',
            'currency' => 'CAD',
            'starts_at' => now()->addHours(3),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);
    }

    private function ticketAdmitting(int $admits, string $name = 'Table of 5'): Ticket
    {
        $type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => $name,
            'price_amount' => 50000,
            'admits' => $admits,
            'status' => 'on_sale',
        ]);

        return app(TicketIssuer::class)
            ->issueComp($this->event->id, $type->id, 'host-'.uniqid().'@example.com', 'Chidi Nwosu');
    }

    public function test_a_ticket_carries_how_many_it_admits(): void
    {
        $ticket = $this->ticketAdmitting(5);

        // Snapshotted at issue, not read through the type at the door.
        $this->assertSame(5, $ticket->admits);
        $this->assertSame(0, $ticket->admitted_count);
    }

    public function test_a_single_ticket_still_behaves_exactly_as_before(): void
    {
        $ticket = $this->ticketAdmitting(1, 'General');

        $outcome = $this->door->scan($ticket->code, $this->event->id);

        $this->assertTrue($outcome->admittedAnyone());
        $this->assertSame('Admitted.', $outcome->message);
        $this->assertSame('checked_in', $ticket->refresh()->status);

        // The common case must not have acquired any new ceremony.
        $this->assertFalse($outcome->partiallyAdmitted());
    }

    public function test_a_table_can_arrive_in_two_groups(): void
    {
        $ticket = $this->ticketAdmitting(5);

        $first = $this->door->scan($ticket->code, $this->event->id, party: 3);

        $this->assertTrue($first->admittedAnyone());
        $this->assertSame(3, $first->admitted);
        $this->assertSame(2, $first->remaining);
        $this->assertStringContainsString('2 still to come', $first->message);

        // Still valid, because the rest of the table has to get in.
        $this->assertSame('valid', $ticket->refresh()->status);
        $this->assertSame(3, $ticket->admitted_count);

        $second = $this->door->scan($ticket->code, $this->event->id, party: 2);

        $this->assertTrue($second->admittedAnyone());
        $this->assertSame(0, $second->remaining);
        $this->assertSame('checked_in', $ticket->refresh()->status);
        $this->assertSame(5, $ticket->admitted_count);
    }

    public function test_the_whole_party_arriving_together_is_one_scan_once_the_door_says_so(): void
    {
        $ticket = $this->ticketAdmitting(2, 'Couple');

        // No party given: the door is asked rather than both being let in on
        // the word of whoever is holding the ticket.
        $asked = $this->door->scan($ticket->code, $this->event->id);

        $this->assertSame(ScanOutcome::CHOOSE_PARTY, $asked->result);
        $this->assertSame(0, $ticket->refresh()->admitted_count);

        // "Both here" is one scan.
        $outcome = $this->door->scan($ticket->code, $this->event->id, party: 2);

        $this->assertSame(2, $outcome->admitted);
        $this->assertSame('Admitted all 2.', $outcome->message);
        $this->assertSame('checked_in', $ticket->refresh()->status);
    }

    public function test_a_third_scan_after_the_table_is_full_is_refused(): void
    {
        $ticket = $this->ticketAdmitting(5);

        $this->door->scan($ticket->code, $this->event->id, party: 5);
        $outcome = $this->door->scan($ticket->code, $this->event->id);

        $this->assertFalse($outcome->admittedAnyone());
        $this->assertSame(ScanOutcome::DUPLICATE, $outcome->result);
        // Says how many, so the door can tell a returning guest from a
        // duplicated code.
        $this->assertStringContainsString('All 5 already came in', $outcome->message);
    }

    public function test_asking_for_more_than_is_left_is_refused_not_rounded_down(): void
    {
        $ticket = $this->ticketAdmitting(5);

        $this->door->scan($ticket->code, $this->event->id, party: 3);
        $outcome = $this->door->scan($ticket->code, $this->event->id, party: 4);

        // Somebody presenting four against two remaining places is a
        // conversation at the door, not a number to quietly round down.
        $this->assertSame(ScanOutcome::OVER_CAPACITY, $outcome->result);
        $this->assertStringContainsString('Only 2 places left', $outcome->message);

        // And nobody went in on a refusal.
        $this->assertSame(3, $ticket->refresh()->admitted_count);
    }

    public function test_the_database_refuses_to_over_admit_even_if_the_service_were_wrong(): void
    {
        $ticket = $this->ticketAdmitting(5);

        // Two scanners on the same table at the same moment is what the row
        // lock is for; this asserts the constraint behind it, so a race cannot
        // put six through a table of five.
        $this->expectException(QueryException::class);

        DB::table('tickets')
            ->where('id', $ticket->id)
            ->update(['admitted_count' => 6]);
    }

    public function test_every_scan_records_how_many_it_let_in(): void
    {
        $ticket = $this->ticketAdmitting(5);

        $this->door->scan($ticket->code, $this->event->id, party: 3);
        $this->door->scan($ticket->code, $this->event->id, party: 2);
        $this->door->scan($ticket->code, $this->event->id);

        $scans = TicketScan::orderBy('scanned_at')->get();

        $this->assertCount(3, $scans);
        // A partial admission has to be legible afterwards rather than being
        // one accepted row among several with no numbers on it.
        $this->assertSame([3, 2, 0], $scans->pluck('admitted')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(['accepted', 'accepted', 'duplicate'], $scans->pluck('result')->all());
    }

    public function test_a_voided_table_admits_nobody(): void
    {
        $ticket = $this->ticketAdmitting(5);
        $ticket->update(['status' => 'void']);

        $outcome = $this->door->scan($ticket->code, $this->event->id, party: 2);

        $this->assertSame(ScanOutcome::VOID, $outcome->result);
        $this->assertSame(0, $ticket->refresh()->admitted_count);
    }

    public function test_admitting_zero_is_refused(): void
    {
        $ticket = $this->ticketAdmitting(5);

        $outcome = $this->door->scan($ticket->code, $this->event->id, party: 0);

        $this->assertSame(ScanOutcome::OVER_CAPACITY, $outcome->result);
        $this->assertSame(0, $ticket->refresh()->admitted_count);
    }

    public function test_a_shrunk_ticket_type_does_not_shrink_a_table_already_sold(): void
    {
        $ticket = $this->ticketAdmitting(5);

        // The organizer reworks the tier for next month.
        TicketType::whereKey($ticket->ticket_type_id)->update(['admits' => 2]);

        $outcome = $this->door->scan($ticket->code, $this->event->id, party: 5);

        // The table somebody bought is still a table of five.
        $this->assertTrue($outcome->admittedAnyone());
        $this->assertSame(5, $outcome->admitted);
    }
}
