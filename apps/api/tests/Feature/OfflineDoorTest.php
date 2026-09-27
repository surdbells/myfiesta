<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketScan;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Door\DoorList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A door that keeps working when the venue's signal does not.
 *
 * The phone downloads the event's tickets while it can, decides from that list
 * when it cannot, and sends what it did once it can again. These tests pin the
 * parts that decide whether a night goes well: a stolen list cannot be turned
 * into tickets, a retried scan does not turn away a guest already let in, and
 * when two offline phones disagree with the truth, nobody is counted twice and
 * the organizer finds out.
 */
class OfflineDoorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addHour(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $this->type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);

        Sanctum::actingAs(User::factory()->create(), [TokenAbility::doorFor($this->event->id)]);
    }

    private function ticket(string $code, int $admits = 1, string $status = 'valid'): Ticket
    {
        return Ticket::create([
            'event_id' => $this->event->id,
            'ticket_type_id' => $this->type->id,
            'code' => $code,
            'owner_email' => 'guest@example.com',
            'holder_name' => 'Ada Okafor',
            'status' => $status,
            'admits' => $admits,
            'admitted_count' => 0,
        ]);
    }

    private function sync(array $scans): TestResponse
    {
        return $this->postJson("/api/events/{$this->event->id}/scans/sync", ['scans' => $scans]);
    }

    private function offline(string $code, string $result = 'accepted', array $extra = []): array
    {
        return array_merge([
            'client_id' => (string) Str::uuid(),
            'code' => $code,
            'offline_result' => $result,
            'scanned_at' => now()->subMinutes(20)->toIso8601String(),
        ], $extra);
    }

    /** A scan sent online under the id the phone gave it. */
    private function online(string $code, string $id, ?int $party = null): TestResponse
    {
        return $this->postJson("/api/events/{$this->event->id}/scan", array_filter([
            'code' => $code,
            'party' => $party,
            'client_id' => $id,
        ], fn ($value) => $value !== null));
    }

    // --- the list ------------------------------------------------------------

    public function test_the_list_carries_hashes_a_phone_can_check_and_never_the_codes(): void
    {
        $ticket = $this->ticket('WFY7-F77K4EJW');

        $list = $this->getJson("/api/events/{$this->event->id}/door-list")->assertOk()->json();

        // The code opens the door. A list of them on a phone is a book of
        // working tickets, so it must not appear anywhere in the payload.
        $this->assertStringNotContainsString($ticket->code, json_encode($list));

        $this->assertSame(DoorList::ITERATIONS, $list['iterations']);
        $this->assertCount(1, $list['tickets']);

        // What the phone does with a scanned code: hash it the same way and
        // look it up. Lower case and stray spaces, because that is how a code
        // arrives when it is typed.
        $expected = hash_pbkdf2('sha256', 'WFY7-F77K4EJW', $list['salt'], $list['iterations'], 64);
        $this->assertSame($expected, $list['tickets'][0]['hash']);
        $this->assertSame($expected, app(DoorList::class)->hash('  wfy7-f77k4ejw ', $list['salt']));
    }

    public function test_refused_tickets_travel_with_the_list_so_the_door_can_say_why(): void
    {
        $this->ticket('ACDE-FHJKLMNP', status: 'refunded');

        $row = $this->getJson("/api/events/{$this->event->id}/door-list")->json('tickets.0');

        // Absent, it would read as "not recognised" — the wrong conversation
        // to have with somebody holding a real, cancelled ticket.
        $this->assertSame('refunded', $row['status']);
    }

    public function test_another_events_door_token_cannot_download_this_list(): void
    {
        $other = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'other',
            'title' => 'Other',
            'currency' => 'CAD',
            'starts_at' => now()->addDay(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);

        Sanctum::actingAs(User::factory()->create(), [TokenAbility::doorFor($other->id)]);

        $this->getJson("/api/events/{$this->event->id}/door-list")->assertForbidden();
    }

    public function test_an_organizer_without_door_permission_cannot_download_it(): void
    {
        $user = User::factory()->create();
        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Marketing->value,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($user->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        $this->getJson("/api/events/{$this->event->id}/door-list")->assertForbidden();
    }

    // --- a retried scan ------------------------------------------------------

    public function test_a_scan_sent_twice_does_not_turn_away_the_guest_it_let_in(): void
    {
        $ticket = $this->ticket('WFY7-F77K4EJW');
        $id = (string) Str::uuid();

        // Online: the server admits them, and the response never reaches the
        // phone. The phone falls back to its list and queues the same scan.
        $this->postJson("/api/events/{$this->event->id}/scan", ['code' => $ticket->code, 'client_id' => $id])
            ->assertJsonPath('accepted', true);

        $replayed = $this->sync([$this->offline($ticket->code, 'accepted', ['client_id' => $id])])
            ->assertOk()
            ->json('data.0');

        // Without the id this would be "already scanned" and a conflict.
        $this->assertSame('accepted', $replayed['result']);
        $this->assertNull($replayed['conflict']);
        $this->assertSame(1, $ticket->refresh()->admitted_count);
        $this->assertSame(1, TicketScan::where('ticket_id', $ticket->id)->count());
    }

    public function test_a_sync_can_be_sent_again_after_its_response_was_lost(): void
    {
        $ticket = $this->ticket('WFY7-F77K4EJW');
        $batch = [$this->offline($ticket->code)];

        $this->sync($batch)->assertJsonPath('data.0.result', 'accepted');
        $this->sync($batch)->assertJsonPath('data.0.result', 'accepted')->assertJsonCount(0, 'conflicts');

        $this->assertSame(1, $ticket->refresh()->admitted_count);
    }

    // --- answered online, decided again offline -----------------------------
    //
    // The phone sends a scan, the answer is lost on the way back, and the phone
    // decides from its saved list and queues the scan under the same id. The
    // server has answered it once already; the door acted on its own answer.

    public function test_a_guest_let_in_offline_on_a_ticket_the_server_refused_online_is_a_conflict(): void
    {
        $ticket = $this->ticket('WFY7-F77K4EJW');
        $id = (string) Str::uuid();

        // Used at another door since this phone fetched its list.
        $this->postJson("/api/events/{$this->event->id}/scan", ['code' => $ticket->code])->assertJsonPath('accepted', true);

        // The server refuses it, and the answer never reaches the phone, whose
        // list still says unused. So the guest goes in.
        $this->online($ticket->code, $id)->assertJsonPath('result', 'duplicate');

        $response = $this->sync([$this->offline($ticket->code, 'accepted', ['client_id' => $id])])->assertOk();

        // Told to the door and to the organizer the same way as any offline
        // conflict, rather than replayed as "refused, nobody in".
        $conflicts = $response->json('conflicts');
        $this->assertCount(1, $conflicts);
        $this->assertSame($id, $conflicts[0]['client_id']);
        $this->assertSame('admitted_invalid', $conflicts[0]['conflict']);
        $this->assertSame('duplicate', $conflicts[0]['result']);
        $this->assertSame('accepted', $conflicts[0]['offline_result']);

        // Still one person on the ticket, and one row for the scan, now
        // carrying both answers — so the report that asks who got in on a
        // spent ticket finds it.
        $this->assertSame(1, $ticket->refresh()->admitted_count);

        $row = TicketScan::where('client_id', $id)->sole();
        $this->assertSame('duplicate', $row->result);
        $this->assertSame('accepted', $row->offline_result);
        $this->assertSame(0, $row->admitted);
        $this->assertSame(1, TicketScan::where('offline_result', 'accepted')->where('result', '!=', 'accepted')->count());
    }

    public function test_a_table_refused_online_and_waved_in_offline_fills_only_the_places_left(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 5);
        $id = (string) Str::uuid();

        // Three in at another door, then four asked for here: too many, and
        // the refusal never arrives. The phone's list says five places.
        $this->postJson("/api/events/{$this->event->id}/scan", ['code' => $table->code, 'party' => 3]);
        $this->online($table->code, $id, party: 4)->assertJsonPath('result', 'over_capacity');

        $result = $this->sync([$this->offline($table->code, 'accepted', ['client_id' => $id, 'party' => 4])])
            ->json('data.0');

        // As for any table waved in offline: the two places left are counted,
        // because all four are inside, and the disagreement is kept.
        $this->assertSame(5, $table->refresh()->admitted_count);
        $this->assertSame(2, $result['admitted']);
        $this->assertSame('over_capacity', $result['result']);
        $this->assertSame('admitted_invalid', $result['conflict']);
    }

    public function test_the_same_admission_queued_again_is_not_a_conflict_or_a_second_count(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 5);
        $id = (string) Str::uuid();

        $this->online($table->code, $id, party: 2)->assertJsonPath('admitted', 2);

        $copy = [$this->offline($table->code, 'accepted', ['client_id' => $id, 'party' => 2])];

        foreach ([1, 2] as $attempt) {
            $result = $this->sync($copy)->assertOk()->assertJsonCount(0, 'conflicts')->json('data.0');

            $this->assertSame('accepted', $result['result']);
            $this->assertSame(2, $result['admitted']);
            $this->assertNull($result['conflict']);
        }

        $this->assertSame(2, $table->refresh()->admitted_count);
        $this->assertSame(1, TicketScan::where('ticket_id', $table->id)->count());
        // What the door did is kept beside what the server did.
        $this->assertSame('accepted', TicketScan::where('client_id', $id)->value('offline_result'));
    }

    public function test_the_same_refusal_queued_again_is_not_a_conflict(): void
    {
        $ticket = $this->ticket('WFY7-F77K4EJW');
        $id = (string) Str::uuid();

        $this->postJson("/api/events/{$this->event->id}/scan", ['code' => $ticket->code]);
        $this->online($ticket->code, $id)->assertJsonPath('result', 'duplicate');

        // The phone's list knew too: both said no, and nobody went in.
        $this->sync([$this->offline($ticket->code, 'duplicate', ['client_id' => $id])])
            ->assertOk()
            ->assertJsonPath('data.0.result', 'duplicate')
            ->assertJsonCount(0, 'conflicts');

        $this->assertSame(1, $ticket->refresh()->admitted_count);
    }

    public function test_more_of_a_table_let_in_offline_than_the_server_counted_is_a_conflict_when_there_was_no_room(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 5);
        $id = (string) Str::uuid();

        // Three in at another door. Here, the whole of what was left: two.
        $this->postJson("/api/events/{$this->event->id}/scan", ['code' => $table->code, 'party' => 3]);
        $this->online($table->code, $id, party: 2)->assertJsonPath('admitted', 2);

        // The door, with no answer, says four went in on that scan.
        $result = $this->sync([$this->offline($table->code, 'accepted', ['client_id' => $id, 'party' => 4])])
            ->json('data.0');

        // Two more than the ticket had room for. Nobody counted twice, and the
        // two it could not count are the organizer's to look into.
        $this->assertSame(5, $table->refresh()->admitted_count);
        $this->assertSame(2, $result['admitted']);
        $this->assertSame('over_capacity', $result['result']);
        $this->assertSame('admitted_invalid', $result['conflict']);
        $this->assertSame(1, TicketScan::where('client_id', $id)->where('offline_result', 'accepted')->where('result', '!=', 'accepted')->count());
    }

    public function test_more_of_a_table_let_in_offline_than_the_server_counted_fills_places_that_were_free(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 5);
        $id = (string) Str::uuid();

        $this->online($table->code, $id, party: 2)->assertJsonPath('admitted', 2);

        $result = $this->sync([$this->offline($table->code, 'accepted', ['client_id' => $id, 'party' => 4])])
            ->assertJsonCount(0, 'conflicts')
            ->json('data.0');

        // Four went in on a table of five, which the ticket allows. Counting
        // only two would leave places open for somebody else later.
        $this->assertSame(4, $table->refresh()->admitted_count);
        $this->assertSame(4, $result['admitted']);
        $this->assertSame(1, $result['remaining']);
        $this->assertNull($result['conflict']);
    }

    public function test_a_guest_turned_away_offline_after_the_server_let_them_in_is_a_conflict(): void
    {
        // Bought after the phone's list was downloaded.
        $ticket = $this->ticket('NEWB-ACDEFHJK');
        $id = (string) Str::uuid();

        $this->online($ticket->code, $id)->assertJsonPath('accepted', true);

        $response = $this->sync([$this->offline($ticket->code, 'not_found', ['client_id' => $id])])->assertOk();

        $this->assertSame('refused_valid', $response->json('conflicts.0.conflict'));
        $this->assertStringContainsString('They can come in', $response->json('conflicts.0.message'));

        // Not taken back off: the door is told, and the record still says who
        // the server let in.
        $this->assertSame(1, $ticket->refresh()->admitted_count);
        $this->assertSame('not_found', TicketScan::where('client_id', $id)->value('offline_result'));
    }

    public function test_a_guest_turned_away_because_the_door_had_let_somebody_in_on_the_ticket_offline_is_not_sent_back_in(): void
    {
        $ticket = $this->ticket('WFY7-F77K4EJW');
        $id = (string) Str::uuid();

        // No signal: the phone lets the first guest in from its list, which now
        // says the ticket is used, and queues the scan.
        $first = $this->offline($ticket->code, 'accepted', ['scanned_at' => now()->subMinutes(10)->toIso8601String()]);

        // Signal back before the queue has gone. Somebody else shows the same
        // ticket. The server has not heard of the first guest and lets them in,
        // and the answer is lost; the list says used, so they are turned away.
        $this->online($ticket->code, $id)->assertJsonPath('accepted', true);

        $batch = [
            $first,
            $this->offline($ticket->code, 'duplicate', [
                'client_id' => $id,
                'scanned_at' => now()->subMinutes(5)->toIso8601String(),
            ]),
        ];

        $response = $this->sync($batch)->assertOk();

        // Two people showed one ticket and one of them got in: that is the
        // conflict, on the scan that let them in.
        $this->assertSame('admitted_invalid', $response->json('data.0.conflict'));

        // The second was rightly turned away. Not "they can come in", which
        // would put two people in on one ticket.
        $second = $response->json('data.1');
        $this->assertNull($second['conflict']);
        $this->assertFalse($second['accepted']);
        $this->assertSame('duplicate', $second['result']);
        $this->assertStringNotContainsString('can come in', $second['message']);
        $this->assertStringContainsString('Nobody else goes in', $second['message']);
        $this->assertSame([$first['client_id']], array_column($response->json('conflicts'), 'client_id'));

        // One person inside, one counted. The scan is kept as the refusal it
        // was, so it reads as nobody let in.
        $this->assertSame(1, $ticket->refresh()->admitted_count);

        $row = TicketScan::where('client_id', $id)->sole();
        $this->assertSame('duplicate', $row->result);
        $this->assertSame('duplicate', $row->offline_result);
        $this->assertSame(0, $row->admitted);

        // Sent again, it says the same and counts nobody.
        $again = $this->sync($batch)->assertOk();
        $this->assertNull($again->json('data.1.conflict'));
        $this->assertStringNotContainsString('can come in', $again->json('data.1.message'));
        $this->assertCount(1, $again->json('conflicts'));
        $this->assertSame(1, $ticket->refresh()->admitted_count);
    }

    public function test_a_table_turned_away_because_the_door_had_filled_it_offline_is_not_sent_back_in(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 5);
        $id = (string) Str::uuid();

        // Three let in with no signal. Then three more asked for online, which
        // the server allows, not knowing about the first three; the answer is
        // lost, and the phone's list has two places left, so it says no.
        $first = $this->offline($table->code, 'accepted', [
            'party' => 3,
            'scanned_at' => now()->subMinutes(10)->toIso8601String(),
        ]);

        $this->online($table->code, $id, party: 3)->assertJsonPath('admitted', 3);

        $response = $this->sync([
            $first,
            $this->offline($table->code, 'over_capacity', [
                'client_id' => $id,
                'party' => 3,
                'scanned_at' => now()->subMinutes(5)->toIso8601String(),
            ]),
        ])->assertOk();

        $this->assertSame('admitted_invalid', $response->json('data.0.conflict'));
        $this->assertNull($response->json('data.1.conflict'));
        $this->assertStringNotContainsString('can come in', $response->json('data.1.message'));
        $this->assertCount(1, $response->json('conflicts'));

        // Nothing taken back off and nothing added.
        $this->assertSame(5, $table->refresh()->admitted_count);
        $this->assertSame(0, TicketScan::where('client_id', $id)->value('admitted'));
    }

    public function test_a_guest_let_in_offline_on_another_ticket_does_not_hide_a_guest_turned_away_on_this_one(): void
    {
        $other = $this->ticket('ACDE-FHJKLMNP');
        $ticket = $this->ticket('NEWB-ACDEFHJK');
        $id = (string) Str::uuid();

        // A conflict on a different ticket, already on record.
        $this->postJson("/api/events/{$this->event->id}/scan", ['code' => $other->code]);
        $this->sync([$this->offline($other->code)])->assertJsonPath('conflicts.0.conflict', 'admitted_invalid');

        // Bought after the phone's list was downloaded.
        $this->online($ticket->code, $id)->assertJsonPath('accepted', true);

        $this->sync([$this->offline($ticket->code, 'not_found', ['client_id' => $id])])
            ->assertJsonPath('conflicts.0.conflict', 'refused_valid');
    }

    public function test_a_sync_sent_again_after_a_conflict_repeats_it_and_counts_nobody_more(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 5);
        $id = (string) Str::uuid();

        $this->postJson("/api/events/{$this->event->id}/scan", ['code' => $table->code, 'party' => 3]);
        $this->online($table->code, $id, party: 4);

        $copy = [$this->offline($table->code, 'accepted', ['client_id' => $id, 'party' => 4])];

        $this->sync($copy)->assertJsonPath('conflicts.0.conflict', 'admitted_invalid');

        // The first answer was lost too. The phone still hears about it, and
        // the ticket is not touched again.
        $this->sync($copy)
            ->assertOk()
            ->assertJsonPath('conflicts.0.conflict', 'admitted_invalid')
            ->assertJsonPath('data.0.admitted', 2);

        $this->assertSame(5, $table->refresh()->admitted_count);
        $this->assertSame(1, TicketScan::where('client_id', $id)->count());
    }

    public function test_an_online_scan_sent_twice_is_answered_once(): void
    {
        $ticket = $this->ticket('WFY7-F77K4EJW');
        $id = (string) Str::uuid();

        $this->online($ticket->code, $id)->assertJsonPath('accepted', true);

        $this->online($ticket->code, $id)
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('offline_result', null)
            ->assertJsonPath('conflict', null);

        $this->assertSame(1, $ticket->refresh()->admitted_count);
        $this->assertNull(TicketScan::where('client_id', $id)->value('offline_result'));
    }

    public function test_another_events_door_cannot_rewrite_a_scan_by_its_id(): void
    {
        $ticket = $this->ticket('WFY7-F77K4EJW');
        $id = (string) Str::uuid();

        $this->postJson("/api/events/{$this->event->id}/scan", ['code' => $ticket->code]);
        $this->online($ticket->code, $id)->assertJsonPath('result', 'duplicate');

        $other = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'other',
            'title' => 'Other',
            'currency' => 'CAD',
            'starts_at' => now()->addDay(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);

        Sanctum::actingAs(User::factory()->create(), [TokenAbility::doorFor($other->id)]);

        // Same id, same code, a different night: not a copy of that scan.
        $this->postJson("/api/events/{$other->id}/scans/sync", [
            'scans' => [$this->offline($ticket->code, 'accepted', ['client_id' => $id])],
        ])->assertOk();

        $row = TicketScan::where('client_id', $id)->sole();
        $this->assertNull($row->offline_result);
        $this->assertSame('duplicate', $row->result);
    }

    // --- what the door did offline -------------------------------------------

    public function test_an_offline_admission_counts_and_keeps_the_time_it_happened(): void
    {
        $ticket = $this->ticket('WFY7-F77K4EJW');
        $when = now()->subMinutes(20)->startOfSecond();

        $this->sync([$this->offline($ticket->code, 'accepted', ['scanned_at' => $when->toIso8601String()])])
            ->assertOk()
            ->assertJsonCount(0, 'conflicts');

        $ticket->refresh();

        $this->assertSame('checked_in', $ticket->status);
        // When they walked in, not when the basement found signal again.
        $this->assertTrue($ticket->checked_in_at->equalTo($when));
    }

    public function test_two_offline_phones_on_one_ticket_count_it_once_and_say_so(): void
    {
        $ticket = $this->ticket('WFY7-F77K4EJW');

        // Both phones had no signal and both let the same ticket in.
        $response = $this->sync([
            $this->offline($ticket->code),
            $this->offline($ticket->code),
        ])->assertOk();

        $this->assertSame(1, $ticket->refresh()->admitted_count);

        $conflicts = $response->json('conflicts');
        $this->assertCount(1, $conflicts);
        $this->assertSame('admitted_invalid', $conflicts[0]['conflict']);
        $this->assertSame('duplicate', $conflicts[0]['result']);

        // Kept on the row, for the report that asks who got in on a spent ticket.
        $this->assertSame(1, TicketScan::where('offline_result', 'accepted')->where('result', '!=', 'accepted')->count());
    }

    public function test_a_table_waved_in_offline_fills_only_the_places_left(): void
    {
        $table = $this->ticket('TBLE-ACDEFHJK', admits: 5);

        // Another phone, online, let three of the table in.
        $this->postJson("/api/events/{$this->event->id}/scan", ['code' => $table->code, 'party' => 3]);

        // This phone, offline and not knowing, let four more in.
        $result = $this->sync([$this->offline($table->code, 'accepted', ['party' => 4])])->json('data.0');

        // Two places were genuinely left and all four are inside. Counting
        // nobody would leave two places for somebody else; counting four would
        // break the ticket. Two, and the disagreement.
        $this->assertSame(5, $table->refresh()->admitted_count);
        $this->assertSame(2, $result['admitted']);
        $this->assertSame('admitted_invalid', $result['conflict']);
    }

    public function test_a_guest_turned_away_offline_is_not_marked_as_in(): void
    {
        // Bought after the phone's list was downloaded, so the phone said
        // "not recognised" and they stayed outside.
        $ticket = $this->ticket('NEWB-ACDEFHJK');

        $result = $this->sync([$this->offline($ticket->code, 'not_found')])->json('data.0');

        // The ticket was fine; that is a guest to apologise to, not an admission.
        $this->assertSame('refused_valid', $result['conflict']);
        $this->assertSame(0, $ticket->refresh()->admitted_count);

        // And when they come back with signal, they get in.
        $this->postJson("/api/events/{$this->event->id}/scan", ['code' => $ticket->code])
            ->assertJsonPath('accepted', true);
    }

    public function test_an_unknown_code_sent_twice_is_recorded_once(): void
    {
        $scan = $this->offline('ZZZZ-ZZZZZZZZ', 'not_found');

        $this->sync([$scan])->assertOk();
        $this->sync([$scan])->assertOk();

        $this->assertSame(1, TicketScan::where('client_id', $scan['client_id'])->count());
    }

    public function test_a_phone_clock_in_the_wrong_year_is_not_believed(): void
    {
        $ticket = $this->ticket('WFY7-F77K4EJW');

        $this->sync([$this->offline($ticket->code, 'accepted', ['scanned_at' => '2019-01-01T22:00:00Z'])]);

        $this->assertTrue($ticket->refresh()->checked_in_at->isToday());
    }

    public function test_a_malformed_batch_is_refused_before_anything_is_recorded(): void
    {
        $ticket = $this->ticket('WFY7-F77K4EJW');

        // One good scan and one the phone could never have produced. The whole
        // batch is refused rather than half-applied: the phone keeps its queue
        // and nothing is admitted from a request that was not understood.
        $this->sync([
            $this->offline($ticket->code),
            ['client_id' => 'not-a-uuid', 'code' => 'X', 'offline_result' => 'accepted', 'scanned_at' => now()->toIso8601String()],
        ])->assertStatus(422);

        $this->assertSame(0, $ticket->refresh()->admitted_count);
        $this->assertSame(0, TicketScan::count());
    }
}
