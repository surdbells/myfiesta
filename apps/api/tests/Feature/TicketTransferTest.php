<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\TokenAbility;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Mail\TicketsIssued;
use App\Mail\YourTicket;
use App\Models\AddOn;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\ResaleListing;
use App\Models\Ticket;
use App\Models\TicketTransfer;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Door\CheckInService;
use App\Services\Door\DoorList;
use App\Services\Door\ScanOutcome;
use App\Services\PersonalData\Eraser;
use App\Services\PersonalData\Subject;
use App\Services\Resale\Resale;
use App\Services\StaffSupport\TicketActions;
use App\Services\Tickets\TicketHandover;
use App\Services\Tickets\TicketHandoverRefused;
use App\Services\Tickets\TicketLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Admin\SupportFixtures;
use Tests\TestCase;

/**
 * Sending a ticket to somebody else, from the phone and from the link in the
 * buyer's email, and what that has to change at the door.
 *
 * What must hold is what makes a transfer a transfer rather than a copy: the
 * code that went with the old holder stops opening the door, online and on a
 * door phone's list; the new holder hears about it and gets a link of their
 * own, which shows them their ticket and nothing of what somebody else paid;
 * and a ticket somebody has already come in on, or has given back, stays
 * where it is.
 */
class TicketTransferTest extends TestCase
{
    use RefreshDatabase, SupportFixtures;

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = $this->event($this->organization(), ['title' => 'Highlife Night', 'resale_enabled' => true]);
        $this->type = $this->ticketType($this->event, ['name' => 'General']);
    }

    /** A paid order bought by Ada, signed in, so the phone can send it too. */
    private function bought(int $quantity = 1): Order
    {
        $ada = User::factory()->create(['email' => 'ada@example.com', 'name' => 'Ada Okafor']);
        $order = $this->paidOrder($this->event, $this->type, $quantity, ['user_id' => $ada->id]);
        $order->tickets()->update(['owner_user_id' => $ada->id]);

        return $order->refresh();
    }

    private function fromPhone(Ticket $ticket, string $email = 'chioma@example.com', string $name = 'Chioma Obi'): TestResponse
    {
        Sanctum::actingAs(User::findOrFail($ticket->owner_user_id), [TokenAbility::Attendee->value]);

        return $this->postJson("/api/tickets/{$ticket->id}/transfer", ['email' => $email, 'name' => $name]);
    }

    private function fromLink(string $token, Ticket $ticket, string $email = 'chioma@example.com', string $name = 'Chioma Obi'): TestResponse
    {
        return $this->postJson("/api/tickets/{$token}/transfer/{$ticket->id}", ['email' => $email, 'name' => $name]);
    }

    private function sentWith(Ticket $ticket): TicketTransfer
    {
        // The one whose link is open: each handover closes the one before.
        return TicketTransfer::query()->where('ticket_id', $ticket->id)->whereNotNull('access_token')->sole();
    }

    // --- the door -------------------------------------------------------------

    public function test_the_old_code_is_refused_at_the_door_and_the_new_one_admitted(): void
    {
        $ticket = $this->bought()->tickets()->sole();
        $old = $ticket->code;

        $this->fromPhone($ticket)->assertOk();

        $new = $ticket->fresh()->code;
        $this->assertNotSame($old, $new);

        $refused = app(CheckInService::class)->scan($old, $this->event->id);
        $this->assertSame(0, $refused->admitted);
        $this->assertSame(ScanOutcome::NOT_FOUND, $refused->result);

        $admitted = app(CheckInService::class)->scan($new, $this->event->id);
        $this->assertSame(ScanOutcome::ACCEPTED, $admitted->result);
        $this->assertSame(1, $admitted->admitted);
    }

    public function test_the_door_phones_list_carries_the_new_codes_hash_not_the_old_ones(): void
    {
        $ticket = $this->bought()->tickets()->sole();
        $doorList = app(DoorList::class);
        $salt = $doorList->salt($this->event);

        // Downloaded once before the transfer, so the old hash is cached.
        $before = collect($doorList->for($this->event)['tickets'])->pluck('hash')->all();
        $this->assertSame([$doorList->hash($ticket->code, $salt)], $before);

        $this->fromPhone($ticket)->assertOk();

        $after = collect($doorList->for($this->event)['tickets'])->pluck('hash')->all();
        $this->assertSame([$doorList->hash($ticket->fresh()->code, $salt)], $after);
        $this->assertNotSame($before, $after);
    }

    public function test_the_door_lists_cache_is_keyed_by_the_event_the_ticket_and_its_code(): void
    {
        $ticket = $this->bought()->tickets()->sole();
        $salt = app(DoorList::class)->salt($this->event);
        $key = DoorList::cacheKey($ticket);

        $this->assertMatchesRegularExpression(
            '/^door-hash:v1:'.preg_quote($this->event->id, '/').':'.preg_quote($ticket->id, '/').':[0-9a-f]{12}$/',
            $key,
        );
        // Nothing of the code itself in a key a cache store shows anybody,
        // and nothing a door phone could work out from the list it holds:
        // not a plain digest of the code, nor one keyed with the salt the
        // list comes with.
        $this->assertStringNotContainsString($ticket->code, $key);
        $this->assertStringNotContainsString(substr(sha1($ticket->code), 0, 12), $key);
        foreach (['sha1', 'sha256'] as $algorithm) {
            $this->assertStringNotContainsString(substr(hash_hmac($algorithm, $ticket->code, $salt), 0, 12), $key);
        }

        $ticket->code = Ticket::generateCode();
        $this->assertNotSame($key, DoorList::cacheKey($ticket));
    }

    // --- the new holder ----------------------------------------------------------

    public function test_the_new_holder_is_emailed_their_ticket_with_a_link_of_their_own(): void
    {
        $ticket = $this->bought()->tickets()->sole();

        $this->fromPhone($ticket, 'Chioma@Example.com')->assertOk();

        $transfer = $this->sentWith($ticket);
        $this->assertNotNull($transfer->access_token);
        $this->assertSame('chioma@example.com', $transfer->to_email);

        Mail::assertQueued(YourTicket::class, fn (YourTicket $mail) => $mail->hasTo('chioma@example.com')
            && $mail->link === TicketHandover::link($transfer)
            && $mail->sender === 'Ada Okafor'
            && ! $mail->reissued
            && $mail->newCode);

        // The holder's own ticket, with its new code — and only that.
        $rendered = (new YourTicket($ticket->fresh(['event.venue', 'event.organization', 'ticketType']), newCode: true, link: TicketHandover::link($transfer), sender: 'Ada Okafor'))
            ->to('chioma@example.com')
            ->render();

        $this->assertStringContainsString($ticket->fresh()->code, $rendered);
        $this->assertStringContainsString($transfer->access_token, $rendered);
        $this->assertStringContainsString('Ada Okafor sent you a ticket', $rendered);
    }

    public function test_an_email_that_waited_in_the_queue_does_not_show_the_next_holders_code(): void
    {
        $ticket = $this->bought()->tickets()->sole();
        $this->fromPhone($ticket)->assertOk();
        $first = $this->sentWith($ticket);

        // Chioma passes it straight on before her email went out.
        $this->fromLink($first->access_token, $ticket->fresh(), 'tunde@example.com', 'Tunde Bello')->assertOk();

        $late = (new YourTicket($ticket->fresh(['event.venue', 'event.organization', 'ticketType']), newCode: true, link: TicketHandover::link($first), sender: 'Ada Okafor'))
            ->to('chioma@example.com')
            ->render();

        $this->assertStringNotContainsString($ticket->fresh()->code, $late);
        $this->assertStringContainsString('no longer yours', $late);
    }

    public function test_the_ticket_reaches_the_account_at_that_address_however_it_was_typed(): void
    {
        $chioma = User::factory()->create(['email' => 'chioma@example.com']);
        $ticket = $this->bought()->tickets()->sole();

        $this->fromPhone($ticket, '  CHIOMA@example.COM ')->assertOk();

        $this->assertSame($chioma->id, $ticket->fresh()->owner_user_id);
        $this->assertSame(1, User::whereRaw('lower(email) = ?', ['chioma@example.com'])->count());
    }

    public function test_the_phone_is_told_what_happened_and_never_handed_the_new_code(): void
    {
        $ticket = $this->bought()->tickets()->sole();

        $response = $this->fromPhone($ticket)->assertOk();

        $this->assertSame('Sent to chioma@example.com. The code on this phone no longer gets anybody in.', $response->json('message'));
        $this->assertStringNotContainsString($ticket->fresh()->code, $response->getContent());
        // Nor is it in the sender's list any more.
        $this->getJson('/api/me/tickets')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_it_is_written_down_without_a_code_in_the_record(): void
    {
        $ticket = $this->bought()->tickets()->sole();
        $old = $ticket->code;

        $this->fromPhone($ticket)->assertOk();

        $entry = AuditLog::where('action', 'ticket.transferred')->sole();
        $this->assertSame($ticket->owner_user_id, $entry->actor_id);
        $this->assertSame($ticket->id, $entry->subject_id);
        $this->assertSame('app', $entry->metadata['via']);
        $this->assertSame($this->sentWith($ticket)->id, $entry->metadata['transfer_id']);
        $this->assertStringNotContainsString($old, json_encode($entry->metadata));
        $this->assertStringNotContainsString($ticket->fresh()->code, json_encode($entry->metadata));
    }

    // --- what stays where it is ---------------------------------------------------

    public function test_a_ticket_somebody_has_come_in_on_is_not_sent_on(): void
    {
        $ticket = $this->bought()->tickets()->sole();
        // A table of four, two of them inside: still `valid`.
        $ticket->update(['admits' => 4, 'admitted_count' => 2]);
        $code = $ticket->code;

        $this->fromPhone($ticket)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Somebody has already come in on this ticket, so it stays with you.');

        $this->assertSame($code, $ticket->fresh()->code);
        $this->assertSame('ada@example.com', $ticket->fresh()->owner_email);
        $this->assertSame(0, TicketTransfer::count());
        Mail::assertNothingQueued();
    }

    public function test_a_ticket_given_back_for_resale_is_not_sent_on(): void
    {
        $order = $this->bought();
        $ticket = $order->tickets()->sole();
        $ticket->update(['status' => 'listed']);

        $this->fromLink($order->access_token, $ticket)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This ticket is waiting for somebody to take it. Keep it first if you would rather send it to somebody.');

        $this->fromPhone($ticket)->assertStatus(422);

        $this->assertSame('listed', $ticket->fresh()->status);
        $this->assertSame(0, TicketTransfer::count());
    }

    public function test_a_used_ticket_is_not_sent_on(): void
    {
        $ticket = $this->bought()->tickets()->sole();
        $ticket->update(['status' => 'checked_in', 'admitted_count' => 1, 'checked_in_at' => now()]);

        $this->fromPhone($ticket)->assertStatus(422)->assertJsonPath('message', 'This ticket has already been used.');
        $this->assertSame(0, TicketTransfer::count());
    }

    public function test_nothing_is_sent_on_once_the_night_has_started(): void
    {
        $ticket = $this->bought()->tickets()->sole();
        $this->event->update(['starts_at' => now()->subMinute()]);

        $this->fromPhone($ticket)
            ->assertStatus(422)
            ->assertJsonPath('message', 'The event has started, so tickets can no longer be sent to somebody else.');

        $this->assertSame(0, TicketTransfer::count());
    }

    public function test_sending_it_to_the_address_that_holds_it_is_refused(): void
    {
        $ticket = $this->bought()->tickets()->sole();

        $this->fromPhone($ticket, 'ADA@example.com')->assertStatus(422)->assertJsonPath('message', 'This ticket is already at that address.');
        $this->assertSame(0, TicketTransfer::count());
    }

    public function test_somebody_elses_ticket_is_not_theirs_to_send(): void
    {
        $ticket = $this->bought()->tickets()->sole();
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::Attendee->value]);

        $this->postJson("/api/tickets/{$ticket->id}/transfer", ['email' => 'thief@example.com', 'name' => 'Thief'])->assertForbidden();
        $this->assertSame(0, TicketTransfer::count());
    }

    // --- from the link in the buyer's email ----------------------------------------

    public function test_the_buyers_link_sends_one_ticket_and_keeps_the_rest(): void
    {
        $order = $this->bought(2);
        [$kept, $sent] = $order->tickets()->orderBy('id')->get()->all();

        $response = $this->fromLink($order->access_token, $sent)->assertOk();

        $this->assertStringStartsWith('Sent to chioma@example.com.', $response->json('message'));
        // The page as it now is: the ticket that went is gone from it, and
        // its new code is nowhere in the answer.
        $this->assertSame([$kept->id], array_column($response->json('access.tickets'), 'id'));
        $this->assertStringNotContainsString($sent->fresh()->code, $response->getContent());

        $this->assertSame('chioma@example.com', $sent->fresh()->owner_email);
        $this->assertSame('Chioma Obi', $sent->fresh()->holder_name);
        $this->assertNotSame($sent->code, $sent->fresh()->code);

        $entry = AuditLog::where('action', 'ticket.transferred')->sole();
        $this->assertNull($entry->actor_id);
        $this->assertSame('link', $entry->metadata['via']);
    }

    public function test_the_buyers_link_no_longer_shows_a_ticket_it_sent(): void
    {
        $order = $this->bought(2);
        [$kept, $sent] = $order->tickets()->orderBy('id')->get()->all();

        $this->fromLink($order->access_token, $sent)->assertOk();

        $page = $this->getJson('/api/tickets/'.$order->access_token)->assertOk();
        $this->assertSame([$kept->id], array_column($page->json('tickets'), 'id'));
        $this->assertStringNotContainsString($sent->fresh()->code, $page->getContent());

        // Nor can it send it again, give it back, or keep it from the list.
        $this->fromLink($order->access_token, $sent, 'tunde@example.com', 'Tunde')->assertNotFound();
        $this->postJson("/api/tickets/{$order->access_token}/resale/{$sent->id}")->assertNotFound();
        $this->assertSame('chioma@example.com', $sent->fresh()->owner_email);
        $this->assertSame(0, ResaleListing::count());
    }

    public function test_the_link_refuses_a_ticket_that_is_not_on_its_order(): void
    {
        $order = $this->bought();
        $other = $this->paidOrder($this->event, $this->type, 1, ['buyer_email' => 'bola@example.com', 'buyer_name' => 'Bola'])->tickets()->sole();

        $this->fromLink($order->access_token, $other)->assertNotFound();
        $this->fromLink('not-a-real-token', $order->tickets()->sole())->assertNotFound();

        $this->assertSame('bola@example.com', $other->fresh()->owner_email);
        $this->assertSame(0, TicketTransfer::count());
    }

    public function test_the_link_asks_for_a_name_and_an_address(): void
    {
        $order = $this->bought();
        $ticket = $order->tickets()->sole();

        $this->postJson("/api/tickets/{$order->access_token}/transfer/{$ticket->id}", ['email' => 'not an address', 'name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'name']);
    }

    // --- the page the new holder opens -------------------------------------------

    public function test_the_new_holders_page_shows_their_ticket_and_nothing_of_the_order(): void
    {
        $order = $this->bought(2);
        $bottle = AddOn::create(['event_id' => $this->event->id, 'name' => 'Bottle service', 'price_amount' => 20000]);
        $order->lines()->create(['add_on_id' => $bottle->id, 'name' => 'Bottle service', 'unit_price_amount' => 20000, 'quantity' => 1, 'line_total_amount' => 20000]);
        [, $sent] = $order->tickets()->orderBy('id')->get()->all();

        $this->fromLink($order->access_token, $sent)->assertOk();
        $token = $this->sentWith($sent)->access_token;

        $page = $this->getJson('/api/tickets/'.$token)->assertOk();

        $this->assertSame([$sent->id], array_column($page->json('tickets'), 'id'));
        $this->assertSame($sent->fresh()->code, $page->json('tickets.0.code'));
        $this->assertNotNull($page->json('tickets.0.qr'));
        $this->assertTrue($page->json('sent'));
        $this->assertNull($page->json('receipt'));
        $this->assertSame([], $page->json('extras'));
        $this->assertNull($page->json('reference'));
        $this->assertNull($page->json('buyer_name'));
        $this->assertTrue($page->json('tickets.0.transferable'));
        // Passed on, not given back: the money would go to whoever paid.
        $this->assertNotNull($page->json('tickets.0.return.refusal'));

        // Nothing that reaches the order: not its link, not its reference.
        $this->assertStringNotContainsString($order->access_token, $page->getContent());
        $this->assertStringNotContainsString($order->reference, $page->getContent());
        $this->assertStringNotContainsString('Bottle service', $page->getContent());

        // The buyer's own page still says it is the buyer's.
        $this->assertFalse($this->getJson('/api/tickets/'.$order->access_token)->json('sent'));
    }

    public function test_the_new_holder_can_send_it_on_and_their_link_then_closes(): void
    {
        $order = $this->bought();
        $ticket = $order->tickets()->sole();
        $this->fromLink($order->access_token, $ticket)->assertOk();
        $chiomas = $this->sentWith($ticket)->access_token;

        $response = $this->fromLink($chiomas, $ticket->fresh(), 'tunde@example.com', 'Tunde Bello')->assertOk();

        // The answer is Chioma's page as it now is: nothing on it.
        $this->assertSame([], $response->json('access.tickets'));
        $this->assertStringNotContainsString($ticket->fresh()->code, $response->getContent());

        $this->getJson('/api/tickets/'.$chiomas)->assertNotFound();
        $this->fromLink($chiomas, $ticket->fresh(), 'kemi@example.com', 'Kemi')->assertNotFound();

        $tundes = $this->sentWith($ticket);
        $this->assertSame('tunde@example.com', $tundes->to_email);
        $this->assertSame('chioma@example.com', $tundes->from_email);
        $this->getJson('/api/tickets/'.$tundes->access_token)->assertOk()->assertJsonPath('tickets.0.id', $ticket->id);
    }

    public function test_a_new_holders_link_closes_when_the_ticket_is_refunded(): void
    {
        $order = $this->bought();
        $ticket = $order->tickets()->sole();
        $this->fromLink($order->access_token, $ticket)->assertOk();
        $token = $this->sentWith($ticket)->access_token;

        $ticket->update(['status' => 'refunded']);

        $this->getJson('/api/tickets/'.$token)->assertNotFound();
    }

    // --- support moving it -----------------------------------------------------------

    public function test_support_reissuing_goes_through_the_same_handover(): void
    {
        $finance = $this->staff(PlatformRole::Finance);
        $order = $this->bought();
        $ticket = $order->tickets()->sole();
        // At the door, with the night under way: what support is there for.
        $this->event->update(['starts_at' => now()->subHour()]);

        app(TicketActions::class)->reissue($ticket, $finance, 'chioma@example.com', 'Chioma Obi', true, 'Wrong address at checkout.');

        $transfer = $this->sentWith($ticket);
        $this->assertNotNull($transfer->access_token);
        $this->assertSame($finance->id, $transfer->initiated_by);

        Mail::assertQueued(YourTicket::class, fn (YourTicket $mail) => $mail->hasTo('chioma@example.com')
            && $mail->reissued && $mail->newCode && $mail->sender === null
            && $mail->link === TicketHandover::link($transfer));

        $this->getJson('/api/tickets/'.$transfer->access_token)->assertOk()->assertJsonPath('tickets.0.id', $ticket->id);
        $this->assertSame(0, AuditLog::where('action', 'ticket.transferred')->count());
        $this->assertSame('Wrong address at checkout.', AuditLog::where('action', 'ticket.reissued')->sole()->metadata['reason']);
    }

    public function test_support_is_told_why_a_partly_used_ticket_stays_put(): void
    {
        $finance = $this->staff(PlatformRole::Finance);
        $ticket = $this->bought()->tickets()->sole();
        $ticket->update(['admits' => 4, 'admitted_count' => 1]);

        $this->expectExceptionMessage('Somebody has already come in on this ticket.');

        app(TicketActions::class)->reissue($ticket, $finance, 'chioma@example.com', 'Chioma Obi');
    }

    // --- what the ticket says about itself --------------------------------------------

    public function test_a_ticket_says_whether_it_can_be_sent_on(): void
    {
        $order = $this->bought(2);
        [$free, $table] = $order->tickets()->orderBy('id')->get()->all();
        $table->update(['admits' => 4, 'admitted_count' => 1]);

        $page = collect($this->getJson('/api/tickets/'.$order->access_token)->assertOk()->json('tickets'))->keyBy('id');
        $this->assertTrue($page[$free->id]['transferable']);
        $this->assertFalse($page[$table->id]['transferable']);

        Sanctum::actingAs(User::findOrFail($free->owner_user_id), [TokenAbility::Attendee->value]);
        $mine = collect($this->getJson('/api/me/tickets')->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($mine[$free->id]['transferable']);
        $this->assertFalse($mine[$table->id]['transferable']);

        $this->event->update(['starts_at' => now()->subMinute()]);
        $this->assertFalse($this->getJson('/api/tickets/'.$order->access_token)->json('tickets.0.transferable'));
    }

    // --- giving one back ------------------------------------------------------------------

    public function test_a_table_some_of_whom_are_inside_cannot_be_given_back(): void
    {
        $order = $this->bought();
        $ticket = $order->tickets()->sole();
        $ticket->update(['admits' => 4, 'admitted_count' => 1]);

        $this->assertSame(
            'Somebody has already come in on this ticket, so it cannot be given back.',
            app(Resale::class)->refusal($ticket->fresh(), $this->event),
        );

        $this->postJson("/api/tickets/{$order->access_token}/resale/{$ticket->id}")->assertStatus(422);
        $this->assertSame('valid', $ticket->fresh()->status);
        $this->assertSame(0, ResaleListing::count());
    }

    // --- the contract -----------------------------------------------------------------

    /**
     * Every field the contract declares, on both kinds of page and on both
     * answers: a generated client reads a missing field as a broken server.
     */
    public function test_the_pages_and_answers_carry_what_the_contract_promises(): void
    {
        $schemas = Yaml::parseFile(base_path('../../packages/contract/openapi.yaml'))['components']['schemas'];

        $order = $this->bought(2);
        [$first, $second] = $order->tickets()->orderBy('id')->get()->all();

        $answer = $this->fromLink($order->access_token, $first)->assertOk()->json();
        $sentPage = $this->getJson('/api/tickets/'.$this->sentWith($first)->access_token)->assertOk()->json();
        $orderPage = $this->getJson('/api/tickets/'.$order->access_token)->assertOk()->json();

        foreach ($schemas['TicketTransferAnswer']['required'] as $field) {
            $this->assertArrayHasKey($field, $answer, "TicketTransferAnswer requires '{$field}'.");
        }

        foreach (['sent page' => $sentPage, 'order page' => $orderPage, 'answer' => $answer['access']] as $which => $page) {
            foreach (array_keys($schemas['TicketAccess']['properties']) as $field) {
                $this->assertArrayHasKey($field, $page, "TicketAccess declares '{$field}' and the {$which} does not return it.");
            }
        }

        foreach ($schemas['HeldTicket']['required'] as $field) {
            $this->assertArrayHasKey($field, $sentPage['tickets'][0], "HeldTicket requires '{$field}' and the sent page does not return it.");
        }

        $this->fromPhone($second)->assertOk()->assertExactJsonStructure(['message']);
    }

    // --- two at once ----------------------------------------------------------------

    public function test_a_send_checked_before_another_landed_does_not_move_it_on_from_the_new_holder(): void
    {
        $order = $this->bought();
        $ticket = $order->tickets()->sole();

        // Both requests found the ticket on the link, still Ada's, before
        // either had moved it; the first lands.
        $stale = TicketLink::find($order->access_token)?->ticket($ticket->id);
        $this->assertNotNull($stale);
        $this->fromLink($order->access_token, $ticket)->assertOk();

        try {
            app(TicketHandover::class)->send($stale, 'mallory@example.com', 'Mallory', null);
            $this->fail('The second send moved the ticket on from the person the first gave it to.');
        } catch (TicketHandoverRefused $refused) {
            $this->assertSame(TicketHandoverRefused::MOVED, $refused->reason);
        }

        $this->assertSame('chioma@example.com', $ticket->fresh()->owner_email);
        $this->assertSame(1, TicketTransfer::count());
        Mail::assertQueued(YourTicket::class, 1);
    }

    public function test_a_phone_send_checked_before_another_landed_is_refused_too(): void
    {
        $ticket = $this->bought()->tickets()->sole();
        $ada = User::findOrFail($ticket->owner_user_id);
        $stale = $ticket->fresh();

        $this->fromPhone($ticket)->assertOk();

        $this->expectException(TicketHandoverRefused::class);
        app(TicketHandover::class)->send($stale, 'mallory@example.com', 'Mallory', $ada);
    }

    // --- what the sender typed --------------------------------------------------------

    public function test_names_typed_as_links_arrive_in_the_email_as_words(): void
    {
        $order = $this->bought();
        $ticket = $order->tickets()->sole();
        // The name given at checkout is who the email says it is from.
        $ticket->update(['holder_name' => '[Verify your card](https://evil.example/verify)']);

        $this->fromLink($order->access_token, $ticket, 'chioma@example.com', '[Your payment failed](https://evil.example/pay)')
            ->assertOk();

        $mail = Mail::queued(YourTicket::class)->sole();
        $this->assertSame('[Verify your card](https://evil.example/verify)', $mail->sender);

        $rendered = $mail->render();

        $this->assertStringNotContainsString('href="https://evil.example', $rendered);
        $this->assertStringContainsString('Verify your card', $rendered);
        $this->assertStringContainsString('Your payment failed', $rendered);
    }

    // --- the buyer's own confirmation -------------------------------------------------

    public function test_a_confirmation_sent_after_a_ticket_went_on_leaves_its_new_code_out(): void
    {
        $order = $this->bought(2);
        [$kept, $sent] = $order->tickets()->orderBy('id')->get()->all();

        // The order confirmation waited in the queue; the buyer sent one on.
        $this->fromLink($order->access_token, $sent)->assertOk();

        $rendered = (new TicketsIssued($order->fresh()))->to('ada@example.com')->render();

        $this->assertStringContainsString($kept->code, $rendered);
        $this->assertStringNotContainsString($sent->fresh()->code, $rendered);
    }

    // --- being forgotten --------------------------------------------------------------

    public function test_the_senders_erasure_leaves_the_recipients_address_and_link_alone(): void
    {
        $order = $this->bought();
        $ticket = $order->tickets()->sole();
        $this->fromLink($order->access_token, $ticket)->assertOk();
        $transfer = $this->sentWith($ticket);

        app(Eraser::class)->erase(Subject::forEmail('ada@example.com'));

        $after = $transfer->fresh();
        $this->assertNotNull($after);
        $this->assertStringEndsWith('@erased.invalid', $after->from_email);
        $this->assertSame('chioma@example.com', $after->to_email);
        $this->getJson('/api/tickets/'.$transfer->access_token)->assertOk()->assertJsonPath('tickets.0.id', $ticket->id);
    }

    public function test_the_recipients_erasure_clears_their_address_and_closes_their_link(): void
    {
        $order = $this->bought();
        $ticket = $order->tickets()->sole();
        $this->fromLink($order->access_token, $ticket)->assertOk();
        $transfer = $this->sentWith($ticket);
        $token = $transfer->access_token;

        app(Eraser::class)->erase(Subject::forEmail('chioma@example.com'));

        $after = $transfer->fresh();
        $this->assertNotNull($after);
        $this->assertStringEndsWith('@erased.invalid', $after->to_email);
        $this->assertNull($after->access_token);
        $this->assertSame('ada@example.com', $after->from_email);
        $this->getJson('/api/tickets/'.$token)->assertNotFound();
    }

    // --- the admin panel ----------------------------------------------------------------

    public function test_each_transfer_says_how_it_was_sent_and_who_it_went_to(): void
    {
        $finance = $this->staff(PlatformRole::Finance);
        $order = $this->bought(3);
        [$byApp, $byLink, $bySupport] = $order->tickets()->orderBy('id')->get()->all();

        $this->fromPhone($byApp, 'chioma@example.com')->assertOk();
        $this->fromLink($order->access_token, $byLink, 'tunde@example.com', 'Tunde Bello')->assertOk();
        app(TicketHandover::class)->reissue($bySupport, 'kemi@example.com', 'Kemi Ade', $finance);

        foreach (['chioma@example.com' => [$byApp, 'app'], 'tunde@example.com' => [$byLink, 'link'], 'kemi@example.com' => [$bySupport, 'support']] as $email => [$ticket, $via]) {
            $transfer = $this->sentWith($ticket);
            $this->assertSame($via, $transfer->via);
            $this->assertSame($ticket->fresh()->owner_user_id, $transfer->to_user_id);
            $this->assertSame($email, User::findOrFail($transfer->to_user_id)->email);
        }
    }

    public function test_the_admin_panel_says_a_send_from_a_link_was_the_holders(): void
    {
        $order = $this->bought();
        $ticket = $order->tickets()->sole();
        $this->fromLink($order->access_token, $ticket)->assertOk();

        $this->actAs($this->staff(PlatformRole::Support));

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->assertSuccessful()
            ->assertSee('chioma@example.com')
            ->assertSee('The holder, from their ticket link');
    }
}
