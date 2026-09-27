<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCompletion;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Door\CheckInService;
use App\Services\Door\DoorPasses;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SellsTicketsForDisputes;
use Tests\TestCase;

/**
 * That a night took place, written down once from its door.
 *
 * "The event never happened" is answered by the door — when it opened and
 * closed, how many were let in — and by a copy of the night as it was listed,
 * which its organizer cannot edit the next morning. Written once the phones
 * that lost signal have had time to send their scans, and never again.
 */
class EventCompletionTest extends TestCase
{
    use RefreshDatabase, SellsTicketsForDisputes;

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcessors();

        [$this->event, $this->type] = $this->night('CAD', [
            'starts_at' => now()->addDays(10)->setTime(22, 0),
            'ends_at' => now()->addDays(11)->setTime(3, 0),
        ]);
    }

    /** Two tickets sold and paid for, and a night at the door. */
    private function aNightOut(): void
    {
        $order = $this->buy($this->event, $this->type);
        $this->stripePaid($order, 'pi_'.Str::random(24))->assertOk();

        [$first, $second] = Ticket::where('order_id', $order->id)->get()->all();
        $door = app(CheckInService::class);

        $this->travelTo($this->event->starts_at->copy()->addMinutes(10));
        $door->scan($first->code, $this->event->id);

        $this->travel(30)->minutes();
        $door->scan($first->code, $this->event->id);          // already in
        $door->scan('NOT-A-REAL-CODE', $this->event->id);     // matches nothing

        $this->travelTo($this->event->ends_at->copy()->subMinutes(20));
        $door->scan($second->code, $this->event->id);
    }

    private function recordable(): void
    {
        $this->travelTo($this->event->ends_at->copy()
            ->addHours(DoorPasses::GRACE_HOURS + config('disputes.completion.after_door_closes_hours'))
            ->addMinute());
    }

    public function test_a_night_is_written_down_once_its_door_has_closed_and_the_phones_have_caught_up(): void
    {
        $this->aNightOut();

        // The door has only just closed: an offline phone may still be on
        // its way home with the last hour of scans.
        $this->travelTo($this->event->ends_at->copy()->addHours(DoorPasses::GRACE_HOURS + 1));
        $this->artisan('disputes:record-completions')->assertSuccessful();
        $this->assertSame(0, EventCompletion::count());

        $this->recordable();
        $this->artisan('disputes:record-completions')->assertSuccessful();

        $night = EventCompletion::sole();

        $this->assertSame($this->event->id, $night->event_id);
        $this->assertSame('Afro Fest', $night->title);
        $this->assertSame('published', $night->status);
        $this->assertTrue($night->starts_at->equalTo($this->event->starts_at));
        $this->assertTrue($night->ends_at->equalTo($this->event->ends_at));
        $this->assertSame('America/Toronto', $night->timezone);
        $this->assertSame('Toronto', $night->city);

        $this->assertTrue($night->door_opened_at->equalTo($this->event->starts_at->copy()->addMinutes(10)));
        $this->assertTrue($night->door_closed_at->equalTo($this->event->ends_at->copy()->subMinutes(20)));
        $this->assertSame(2, $night->tickets_issued);
        $this->assertSame(2, $night->tickets_live);
        $this->assertSame(2, $night->people_admitted);
        $this->assertSame(2, $night->turned_away);
        $this->assertSame(4, $night->scans);
    }

    public function test_it_is_written_once_and_editing_the_event_afterwards_changes_nothing(): void
    {
        $this->aNightOut();
        $this->recordable();

        $this->artisan('disputes:record-completions')->assertSuccessful();
        $this->artisan('disputes:record-completions')->assertSuccessful();

        $this->assertSame(1, EventCompletion::count());

        $this->event->update(['title' => 'A Night That Never Was', 'starts_at' => now()->addYear(), 'ends_at' => now()->addYear()->addHours(5)]);
        $this->artisan('disputes:record-completions')->assertSuccessful();

        $night = EventCompletion::sole();
        $this->assertSame('Afro Fest', $night->title);
        $this->assertTrue($night->starts_at->lessThan(now()));
    }

    public function test_the_record_cannot_be_changed_or_deleted(): void
    {
        $this->aNightOut();
        $this->recordable();
        $this->artisan('disputes:record-completions')->assertSuccessful();

        foreach ([
            fn () => DB::table('event_completions')->update(['people_admitted' => 0]),
            fn () => DB::table('event_completions')->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('The database let the record of a night be rewritten.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('event_completions', $e->getMessage());
            }
        }

        $this->assertSame(2, EventCompletion::sole()->people_admitted);
    }

    public function test_a_night_nobody_scanned_is_still_written_down_with_its_tickets(): void
    {
        $order = $this->buy($this->event, $this->type);
        $this->stripePaid($order, 'pi_'.Str::random(24))->assertOk();

        $this->recordable();
        $this->artisan('disputes:record-completions')->assertSuccessful();

        $night = EventCompletion::sole();
        $this->assertNull($night->door_opened_at);
        $this->assertNull($night->door_closed_at);
        $this->assertSame(2, $night->tickets_issued);
        $this->assertSame(0, $night->people_admitted);
    }

    public function test_a_cancelled_night_or_one_that_sold_nothing_is_not_written_down(): void
    {
        $this->aNightOut();
        $this->event->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        [$quiet] = $this->night('CAD', [
            'starts_at' => $this->event->starts_at,
            'ends_at' => $this->event->ends_at,
        ]);

        $this->recordable();
        $this->artisan('disputes:record-completions')->assertSuccessful();

        $this->assertSame(0, EventCompletion::count());
        $this->assertNotNull($quiet->id);
    }

    public function test_a_night_older_than_the_evidence_is_kept_for_is_left_alone(): void
    {
        $this->aNightOut();

        $this->travelTo($this->event->ends_at->copy()->addMonths(config('disputes.retention_months'))->addDay());
        $this->artisan('disputes:record-completions')->assertSuccessful();

        $this->assertSame(0, EventCompletion::count());
    }
}
