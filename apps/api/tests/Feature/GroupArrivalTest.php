<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\EventQuestion;
use App\Models\Order;
use App\Models\OrderAnswer;
use App\Models\OrderLine;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketScan;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A ticket for more than one, at the door.
 *
 * A scan that did not say how many used to let in everyone still outstanding.
 * The first of a table holds the ticket up, the door scans it, and the whole
 * table is counted in — so the other three walk in later unscanned, past a
 * ticket that already says they came. Now the door is asked: all of them, or
 * how many. These pin that nobody goes in on the question, that the answer
 * admits exactly that many, that the rest can follow the same way, and that
 * a phone which acted with no signal before doors asked is still believed.
 */
class GroupArrivalTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->event = Event::create([
            'organization_id' => Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights'])->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addHour(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);

        Sanctum::actingAs(User::factory()->create(), [TokenAbility::doorFor($this->event->id)]);
    }

    private function ticket(string $code, int $admits, int $in = 0, string $status = 'valid'): Ticket
    {
        $type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => $admits === 1 ? 'General' : "Table of {$admits}",
            'price_amount' => 20000,
            'admits' => $admits,
            'status' => 'on_sale',
        ]);

        return Ticket::create([
            'event_id' => $this->event->id,
            'ticket_type_id' => $type->id,
            'code' => $code,
            'owner_email' => 'host@example.com',
            'holder_name' => 'Chidi Nwosu',
            'status' => $status,
            'admits' => $admits,
            'admitted_count' => $in,
        ]);
    }

    private function scan(string $code, ?int $party = null, ?string $id = null): TestResponse
    {
        return $this->postJson("/api/events/{$this->event->id}/scan", array_filter([
            'code' => $code,
            'party' => $party,
            'client_id' => $id ?? (string) Str::uuid(),
        ], fn ($value) => $value !== null))->assertOk();
    }

    public function test_a_table_scanned_without_a_number_lets_nobody_in_and_asks(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 4);

        $asked = $this->scan($table->code)->json();

        $this->assertSame('choose_party', $asked['result']);
        $this->assertFalse($asked['accepted']);
        $this->assertSame(0, $asked['admitted']);
        // Everything the door needs to put the question: what it is, whose,
        // how many it admits, how many are in and how many it can offer.
        $this->assertSame(4, $asked['remaining']);
        $this->assertSame('Table of 4', $asked['ticket']['type']);
        $this->assertSame('Chidi Nwosu', $asked['ticket']['holder_name']);
        $this->assertSame(4, $asked['ticket']['admits']);
        $this->assertSame(0, $asked['ticket']['admitted_count']);
        // Said so that a door which cannot show the question can still answer it.
        $this->assertStringContainsString('Put how many are going in now in How many', $asked['message']);

        // Nobody in, and nothing on the record: a question is not a refusal.
        $this->assertSame(0, $table->refresh()->admitted_count);
        $this->assertSame('valid', $table->status);
        $this->assertSame(0, TicketScan::count());
    }

    public function test_one_of_the_table_goes_in_and_the_other_three_stay_outstanding(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 4);

        $this->scan($table->code)->assertJsonPath('result', 'choose_party');

        $one = $this->scan($table->code, party: 1)->json();

        $this->assertSame('accepted', $one['result']);
        $this->assertTrue($one['accepted']);
        $this->assertSame(1, $one['admitted']);
        $this->assertSame(3, $one['remaining']);
        $this->assertSame(1, $one['ticket']['admitted_count']);

        // Still open, so the rest of the table can get in.
        $this->assertSame(1, $table->refresh()->admitted_count);
        $this->assertSame('valid', $table->status);
    }

    public function test_the_rest_of_the_table_is_asked_about_again_and_goes_in_together(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 4);

        $this->scan($table->code, party: 1);

        // The other three arrive. Asked again, not waved through — with how
        // many are already in, so the door can say so.
        $asked = $this->scan($table->code)->json();

        $this->assertSame('choose_party', $asked['result']);
        $this->assertSame(3, $asked['remaining']);
        $this->assertSame(1, $asked['ticket']['admitted_count']);
        $this->assertStringContainsString('1 already in', $asked['message']);
        $this->assertSame(1, $table->refresh()->admitted_count);

        $rest = $this->scan($table->code, party: 3)->json();

        $this->assertTrue($rest['accepted']);
        $this->assertSame(3, $rest['admitted']);
        $this->assertSame(0, $rest['remaining']);
        $this->assertSame('checked_in', $table->refresh()->status);
        $this->assertSame(4, $table->admitted_count);

        // Two scans let people in, and they say how many each.
        $this->assertSame([1, 3], TicketScan::orderBy('scanned_at')->orderBy('id')->pluck('admitted')->map(fn ($n) => (int) $n)->all());
    }

    public function test_the_last_place_on_a_table_needs_no_question(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 4, in: 3);

        $last = $this->scan($table->code)->json();

        $this->assertSame('accepted', $last['result']);
        $this->assertSame(1, $last['admitted']);
        $this->assertSame(0, $last['remaining']);
        $this->assertSame('checked_in', $table->refresh()->status);
    }

    public function test_an_ordinary_ticket_is_let_in_without_a_question(): void
    {
        $ticket = $this->ticket('WFY7-F77K4EJW', admits: 1);

        $this->scan($ticket->code)
            ->assertJsonPath('result', 'accepted')
            ->assertJsonPath('admitted', 1)
            ->assertJsonPath('message', 'Admitted.');

        $this->assertSame('checked_in', $ticket->refresh()->status);
    }

    public function test_a_spent_or_cancelled_table_is_refused_rather_than_asked_about(): void
    {
        $full = $this->ticket('TBLE-ACDEFHJK', admits: 4, in: 4, status: 'checked_in');
        $void = $this->ticket('TBLV-ACDEFHJK', admits: 4, status: 'void');

        $this->scan($full->code)->assertJsonPath('result', 'duplicate');
        $this->assertSame('void', $this->scan($void->code)->json('result'));
    }

    public function test_the_same_question_sent_again_under_its_id_is_asked_again(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 4);
        $id = (string) Str::uuid();

        // A slow wifi: the question sent twice under one id. Nothing was
        // recorded the first time, so nothing is replayed — it is asked again.
        $this->scan($table->code, id: $id)->assertJsonPath('result', 'choose_party');
        $this->scan($table->code, id: $id)->assertJsonPath('result', 'choose_party');

        $this->assertSame(0, TicketScan::count());

        // The answer goes under the same id, which the question left free,
        // and is the one scan on the record.
        $this->scan($table->code, party: 4, id: $id)->assertJsonPath('admitted', 4);
        $this->assertSame(4, $table->refresh()->admitted_count);
        $this->assertSame([$id], TicketScan::pluck('client_id')->all());
    }

    public function test_the_question_carries_no_checkout_answers_and_the_scan_that_answers_it_does(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 4);
        $this->answered($table, 'Table number', '12');

        // Nothing is recorded for the question, so what the guest wrote at
        // checkout would be readable here as often as anybody liked, with no
        // scan on the organizer's record. Neither door shows it while asking.
        $asked = $this->scan($table->code)->json();

        $this->assertSame('choose_party', $asked['result']);
        $this->assertSame('Chidi Nwosu', $asked['ticket']['holder_name']);
        $this->assertSame([], $asked['ticket']['answers']);

        $in = $this->scan($table->code, party: 2)->json();

        $this->assertSame([['label' => 'Table number', 'value' => '12']], $in['ticket']['answers']);
        $this->assertSame(1, TicketScan::count());
    }

    /** What the guest on `$ticket` answered at checkout about themselves. */
    private function answered(Ticket $ticket, string $label, string $value): void
    {
        $order = Order::create([
            'organization_id' => $this->event->organization_id,
            'event_id' => $this->event->id,
            'reference' => strtoupper(Str::random(8)),
            'buyer_email' => 'host@example.com',
            'buyer_name' => 'Chidi Nwosu',
            'currency' => 'CAD',
            'subtotal_amount' => 20000,
            'net_revenue_amount' => 20000,
            'total_amount' => 20000,
            'status' => 'paid',
        ]);

        $line = OrderLine::create([
            'order_id' => $order->id,
            'ticket_type_id' => $ticket->ticket_type_id,
            'name' => 'Table',
            'unit_price_amount' => 20000,
            'quantity' => 1,
            'line_total_amount' => 20000,
        ]);

        $question = EventQuestion::create([
            'event_id' => $this->event->id,
            'label' => $label,
            'type' => 'text',
            'required' => false,
            'per_attendee' => true,
        ]);

        OrderAnswer::create([
            'order_id' => $order->id,
            'event_question_id' => $question->id,
            'order_line_id' => $line->id,
            'attendee_index' => 0,
            'ticket_id' => $ticket->id,
            'value' => [$value],
        ]);
    }

    // --- a question put from the phone's own list -------------------------------
    //
    // The scan times out, the phone decides from a list older than the last
    // admission and asks how many are here — about a scan the server may
    // already have let in on the one place left. The answer goes under the
    // same id, so it is that scan, not a second person on a spent ticket.

    public function test_an_answer_under_the_id_of_a_scan_already_let_in_is_that_scan(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 4, in: 3);
        $id = (string) Str::uuid();

        // Arrived, admitted the last place, and the answer never got back.
        $this->scan($table->code, id: $id)->assertJsonPath('admitted', 1);

        $answer = $this->scan($table->code, party: 1, id: $id)->json();

        // Not "all 4 already came in" to the guest this server just let in.
        $this->assertSame('accepted', $answer['result']);
        $this->assertTrue($answer['accepted']);
        $this->assertSame(1, $answer['admitted']);
        $this->assertSame('Already recorded — they are in.', $answer['message']);
        $this->assertSame(4, $table->refresh()->admitted_count);
        $this->assertSame(1, TicketScan::count());
    }

    public function test_an_answer_for_more_than_the_scan_let_in_says_the_rest_have_no_place(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 4, in: 3);
        $id = (string) Str::uuid();

        $this->scan($table->code, id: $id);

        // Two standing there, by a list that still had four left.
        $answer = $this->scan($table->code, party: 2, id: $id)->json();

        $this->assertTrue($answer['accepted']);
        $this->assertSame(1, $answer['admitted']);
        $this->assertSame(0, $answer['remaining']);
        $this->assertSame('Already recorded for 1 of them, who can come in. The ticket has no places left for the other 1.', $answer['message']);
        $this->assertSame(4, $table->refresh()->admitted_count);
    }

    public function test_an_answer_queued_with_no_signal_under_that_id_syncs_with_no_conflict(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 4, in: 3);
        $id = (string) Str::uuid();

        $this->scan($table->code, id: $id)->assertJsonPath('admitted', 1);

        // Still no signal when the door answered: let one in, and queued it.
        $result = $this->sync(['client_id' => $id, 'code' => $table->code, 'party' => 1])
            ->assertJsonCount(0, 'conflicts')
            ->json('data.0');

        $this->assertSame('accepted', $result['result']);
        $this->assertSame(1, $result['admitted']);
        $this->assertNull($result['conflict']);
        $this->assertSame(4, $table->refresh()->admitted_count);

        $row = TicketScan::sole();
        $this->assertSame($id, $row->client_id);
        $this->assertSame('accepted', $row->offline_result);
        $this->assertSame(1, (int) $row->admitted);
    }

    public function test_an_answer_queued_for_more_than_the_ticket_had_left_is_a_conflict(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 4, in: 3);
        $id = (string) Str::uuid();

        $this->scan($table->code, id: $id);

        // Two went in on a list that had four left; the ticket had one.
        $result = $this->sync(['client_id' => $id, 'code' => $table->code, 'party' => 2])->json('data.0');

        $this->assertSame('admitted_invalid', $result['conflict']);
        $this->assertSame(1, $result['admitted']);
        $this->assertSame(4, $table->refresh()->admitted_count);
    }

    // --- scans a door made with no signal --------------------------------------

    private function sync(array $scan): TestResponse
    {
        return $this->postJson("/api/events/{$this->event->id}/scans/sync", ['scans' => [array_merge([
            'client_id' => (string) Str::uuid(),
            'offline_result' => 'accepted',
            'scanned_at' => now()->subMinutes(10)->toIso8601String(),
        ], $scan)]])->assertOk();
    }

    public function test_a_table_let_in_with_no_number_by_a_phone_from_before_doors_asked_still_counts_everyone(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 4);

        // An older phone, with no signal, waved the whole table through on one
        // scan and queued it with no number. They are inside; asking now
        // would leave four places open on a ticket four people came in on.
        $result = $this->sync(['code' => $table->code, 'party' => null])
            ->assertJsonCount(0, 'conflicts')
            ->json('data.0');

        $this->assertSame('accepted', $result['result']);
        $this->assertSame(4, $result['admitted']);
        $this->assertNull($result['conflict']);
        $this->assertSame(4, $table->refresh()->admitted_count);
        $this->assertSame('checked_in', $table->status);
    }

    public function test_with_part_of_the_table_already_in_it_counts_the_rest(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 4);

        // One in online at another door; the older phone's list, fetched
        // after, had three left, and it let the three through.
        $this->scan($table->code, party: 1);

        $result = $this->sync(['code' => $table->code])->json('data.0');

        $this->assertSame(3, $result['admitted']);
        $this->assertNull($result['conflict']);
        $this->assertSame(4, $table->refresh()->admitted_count);
    }

    public function test_a_table_turned_away_with_no_number_is_still_a_guest_owed_entry(): void
    {
        // Bought after the phone fetched its list, so it said "not recognised".
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 4);

        $result = $this->sync(['code' => $table->code, 'offline_result' => 'not_found'])->json('data.0');

        // Judged as it always was, not turned into a question nobody can
        // answer: the ticket was good and they were sent away.
        $this->assertSame('refused_valid', $result['conflict']);
        $this->assertSame(0, $result['admitted']);
        $this->assertSame(0, $table->refresh()->admitted_count);
    }

    public function test_a_door_that_asks_sends_how_many_and_that_is_what_counts(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 4);

        $this->sync(['code' => $table->code, 'party' => 2])->assertJsonPath('data.0.admitted', 2);

        $this->assertSame(2, $table->refresh()->admitted_count);
        $this->assertSame('valid', $table->status);
    }
}
