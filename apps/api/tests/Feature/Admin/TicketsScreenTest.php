<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\TicketResource;
use App\Mail\YourTicket;
use App\Models\AuditLog;
use App\Models\ResaleListing;
use App\Models\Ticket;
use App\Models\TicketTransfer;
use App\Models\User;
use App\Services\StaffSupport\StaffActionRefused;
use App\Services\StaffSupport\TicketActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Tickets screen: found by who holds them or the order they came from,
 * never by their code and never showing one; resent by anybody on staff,
 * voided or moved only by finance and administrators, and every one of those
 * written to the audit trail with the member of staff as the actor.
 */
class TicketsScreenTest extends TestCase
{
    use RefreshDatabase, SupportFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_every_staff_role_reads_tickets_and_nobody_else(): void
    {
        foreach (PlatformRole::cases() as $role) {
            $this->actAs($this->staff($role));
            $this->assertTrue(TicketResource::canViewAny(), $role->value.' could not open Tickets.');
        }

        $this->actAs(User::factory()->create());
        $this->assertFalse(TicketResource::canViewAny());

        $this->actAs($this->staff(PlatformRole::Admin));
        $this->assertFalse(TicketResource::canCreate());
        $this->assertFalse(TicketResource::canDeleteAny());
        $this->assertFalse(TicketResource::canGloballySearch());
    }

    public function test_tickets_are_found_by_holder_or_order_and_never_by_code(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));
        $event = $this->event($this->organization());
        $type = $this->ticketType($event);

        $ada = $this->paidOrder($event, $type, 1)->tickets()->sole();
        $tundeOrder = $this->paidOrder($event, $type, 1, ['buyer_name' => 'Tunde Bello', 'buyer_email' => 'tunde@example.com']);
        $tunde = $tundeOrder->tickets()->sole();

        $list = Livewire::test(ListTickets::class)
            ->assertCanSeeTableRecords([$ada, $tunde])
            ->assertDontSee($ada->code)
            ->assertDontSee($tunde->code)
            ->searchTable('okafor')
            ->assertCanSeeTableRecords([$ada])
            ->assertCanNotSeeTableRecords([$tunde])
            ->searchTable('tunde@example.com')
            ->assertCanSeeTableRecords([$tunde])
            ->assertCanNotSeeTableRecords([$ada])
            ->searchTable($tundeOrder->reference)
            ->assertCanSeeTableRecords([$tunde])
            ->assertCanNotSeeTableRecords([$ada]);

        // A code typed into the search box finds nothing: the list is not a
        // way to turn a code into a name.
        $list->searchTable($ada->code)
            ->assertCanNotSeeTableRecords([$ada, $tunde]);
    }

    public function test_the_filters_narrow_to_what_they_say(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));
        $organization = $this->organization();
        $afro = $this->event($organization);
        $highlife = $this->event($organization, ['title' => 'Highlife Night']);
        $general = $this->ticketType($afro);
        $vip = $this->ticketType($afro, ['name' => 'VIP', 'price_amount' => 15000]);

        $valid = $this->paidOrder($afro, $general, 1)->tickets()->sole();
        $in = $this->paidOrder($afro, $vip, 1)->tickets()->sole();
        $in->update(['status' => 'checked_in', 'admitted_count' => 1, 'checked_in_at' => now()]);
        $other = $this->paidOrder($highlife, $this->ticketType($highlife), 1)->tickets()->sole();
        $refunded = $this->paidOrder($afro, $general, 1)->tickets()->sole();
        $refunded->update(['status' => 'refunded']);

        Livewire::test(ListTickets::class)
            ->filterTable('status', ['refunded'])
            ->assertCanSeeTableRecords([$refunded])
            ->assertCanNotSeeTableRecords([$valid, $in, $other])
            ->resetTableFilters()
            ->filterTable('event', $highlife->id)
            ->assertCanSeeTableRecords([$other])
            ->assertCanNotSeeTableRecords([$valid, $in])
            ->resetTableFilters()
            ->filterTable('ticketType', $vip->id)
            ->assertCanSeeTableRecords([$in])
            ->assertCanNotSeeTableRecords([$valid, $other])
            ->resetTableFilters()
            ->filterTable('checked_in', true)
            ->assertCanSeeTableRecords([$in])
            ->assertCanNotSeeTableRecords([$valid, $other])
            ->resetTableFilters()
            ->filterTable('checked_in', false)
            ->assertCanSeeTableRecords([$valid, $other])
            ->assertCanNotSeeTableRecords([$in])
            ->resetTableFilters()
            ->sortTable('holder_name')
            ->assertSuccessful()
            ->sortTable('admitted_count', 'desc')
            ->assertSuccessful();
    }

    /** A ticket with no name on it sorts last, both ways: Postgres put it first going down. */
    public function test_tickets_with_no_holder_name_sort_last(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));
        $event = $this->event($this->organization());
        $type = $this->ticketType($event);

        $ada = $this->paidOrder($event, $type, 1)->tickets()->sole();
        $zara = $this->paidOrder($event, $type, 1, ['buyer_name' => 'Zara Bello'])->tickets()->sole();
        $nameless = $this->paidOrder($event, $type, 1)->tickets()->sole();
        $nameless->update(['holder_name' => null]);

        Livewire::test(ListTickets::class)
            ->sortTable('holder_name', 'desc')
            ->assertCanSeeTableRecords([$zara, $ada, $nameless], inOrder: true)
            ->sortTable('holder_name', 'asc')
            ->assertCanSeeTableRecords([$ada, $zara, $nameless], inOrder: true);
    }

    public function test_the_ticket_page_tells_its_story_without_the_code(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));
        $event = $this->event($this->organization(), ['title' => 'Highlife Night']);
        $ticket = $this->paidOrder($event, $this->ticketType($event, ['name' => 'Early Bird']), 1)->tickets()->sole();

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->assertSuccessful()
            ->assertSee('Highlife Night')
            ->assertSee('Early Bird')
            ->assertSee('Ada Okafor')
            ->assertDontSee($ticket->code);
    }

    public function test_support_resends_a_ticket_to_whoever_holds_it(): void
    {
        $support = $this->actAs($this->staff(PlatformRole::Support));
        $event = $this->event($this->organization());
        $ticket = $this->paidOrder($event, $this->ticketType($event), 1)->tickets()->sole();
        $ticket->update(['owner_email' => 'friend@example.com']);

        Livewire::test(ListTickets::class)
            ->callTableAction('resendTicket', $ticket)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Ticket resent');

        Mail::assertQueued(YourTicket::class, fn (YourTicket $mail) => $mail->hasTo('friend@example.com') && ! $mail->reissued);

        $entry = AuditLog::where('action', 'ticket.resent')->sole();
        $this->assertSame($support->id, $entry->actor_id);
        $this->assertSame($ticket->id, $entry->subject_id);
    }

    public function test_support_cannot_void_or_reissue(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));
        $event = $this->event($this->organization());
        $ticket = $this->paidOrder($event, $this->ticketType($event), 1)->tickets()->sole();

        Livewire::test(ListTickets::class)
            ->assertTableActionVisible('resendTicket', $ticket)
            ->assertTableActionHidden('voidTicket', $ticket)
            ->assertTableActionHidden('reissueTicket', $ticket);

        // And the service refuses the same thing if a hidden button is pressed.
        $this->expectException(StaffActionRefused::class);
        app(TicketActions::class)->void($ticket, auth()->user(), 'Trying anyway.');
    }

    public function test_finance_voids_a_ticket_with_a_reason(): void
    {
        $finance = $this->actAs($this->staff(PlatformRole::Finance));
        $event = $this->event($this->organization());
        $order = $this->paidOrder($event, $this->ticketType($event), 1);
        $ticket = $order->tickets()->sole();

        Livewire::test(ListTickets::class)
            ->callTableAction('voidTicket', $ticket, data: ['reason' => 'Issued twice by the import.'])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Ticket voided');

        $this->assertSame('void', $ticket->fresh()->status);
        // Nothing about the money moves: a void is not a refund.
        $this->assertSame('paid', $order->fresh()->status);

        $entry = AuditLog::where('action', 'ticket.voided')->sole();
        $this->assertSame($finance->id, $entry->actor_id);
        $this->assertSame('Issued twice by the import.', $entry->metadata['reason']);
    }

    public function test_a_ticket_somebody_came_in_on_is_not_voided(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));
        $event = $this->event($this->organization());
        $ticket = $this->paidOrder($event, $this->ticketType($event), 1)->tickets()->sole();
        $ticket->update(['status' => 'checked_in', 'admitted_count' => 1, 'checked_in_at' => now()]);

        Livewire::test(ListTickets::class)
            ->assertTableActionHidden('voidTicket', $ticket)
            ->assertTableActionHidden('reissueTicket', $ticket);

        try {
            app(TicketActions::class)->void($ticket, $admin, 'Trying anyway.');
            $this->fail('A used ticket was voided.');
        } catch (StaffActionRefused) {
            $this->assertSame('checked_in', $ticket->fresh()->status);
        }

        $this->assertSame(0, AuditLog::where('action', 'ticket.voided')->count());
    }

    public function test_finance_reissues_a_ticket_to_another_address_with_a_new_code(): void
    {
        $finance = $this->actAs($this->staff(PlatformRole::Finance));
        $event = $this->event($this->organization());
        $ticket = $this->paidOrder($event, $this->ticketType($event), 1)->tickets()->sole();
        $oldCode = $ticket->code;

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->callAction('reissueTicket', data: [
                'email' => 'Chioma@Example.com',
                'name' => 'Chioma Obi',
                'new_code' => true,
                'reason' => 'Buyer asked from their own address.',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Ticket reissued');

        $ticket->refresh();
        $this->assertSame('chioma@example.com', $ticket->owner_email);
        $this->assertSame('Chioma Obi', $ticket->holder_name);
        $this->assertNotSame($oldCode, $ticket->code);
        $this->assertSame('valid', $ticket->status);

        // The same record an attendee's own transfer leaves.
        $transfer = TicketTransfer::where('ticket_id', $ticket->id)->sole();
        $this->assertSame('ada@example.com', $transfer->from_email);
        $this->assertSame('chioma@example.com', $transfer->to_email);
        $this->assertSame($finance->id, $transfer->initiated_by);
        $this->assertNotNull(User::where('email', 'chioma@example.com')->first());

        Mail::assertQueued(YourTicket::class, fn (YourTicket $mail) => $mail->hasTo('chioma@example.com') && $mail->reissued && $mail->newCode);

        $entry = AuditLog::where('action', 'ticket.reissued')->sole();
        $this->assertSame($finance->id, $entry->actor_id);
        $this->assertArrayNotHasKey('code', $entry->metadata);
        $this->assertStringNotContainsString($ticket->code, json_encode($entry->metadata));
        $this->assertStringNotContainsString($oldCode, json_encode($entry->metadata));
    }

    public function test_after_a_reissue_the_buyers_link_no_longer_reaches_the_ticket(): void
    {
        $finance = $this->actAs($this->staff(PlatformRole::Finance));
        $event = $this->event($this->organization());
        $order = $this->paidOrder($event, $this->ticketType($event), 2);
        [$kept, $moved] = $order->tickets()->get()->all();

        app(TicketActions::class)->reissue($moved, $finance, 'chioma@example.com', 'Chioma Obi');
        $newCode = $moved->fresh()->code;

        // The link never expires, and it went to the address the ticket was
        // taken from. Showing the ticket there would hand the new code back.
        $page = $this->getJson('/api/tickets/'.$order->access_token)->assertOk();
        $this->assertSame([$kept->id], array_column($page->json('tickets'), 'id'));
        $this->assertStringNotContainsString($newCode, $page->getContent());

        // Nor can it give the ticket back, which would stop the new holder's
        // code and repay the old address.
        $this->postJson("/api/tickets/{$order->access_token}/resale/{$moved->id}")->assertNotFound();
        $this->assertSame('valid', $moved->fresh()->status);
        $this->assertSame(0, ResaleListing::count());
    }

    public function test_a_buyer_whose_account_moved_address_still_sees_their_tickets(): void
    {
        $event = $this->event($this->organization());
        $order = $this->paidOrder($event, $this->ticketType($event), 1);

        // What an account moving to a new address does to its tickets
        // (AccountController). Not a transfer: they are still the buyer's.
        $order->tickets()->update(['owner_email' => 'ada.new@example.com']);

        $this->getJson('/api/tickets/'.$order->access_token)
            ->assertOk()
            ->assertJsonCount(1, 'tickets');
    }

    public function test_reissuing_to_the_same_address_is_refused(): void
    {
        $this->actAs($this->staff(PlatformRole::Admin));
        $event = $this->event($this->organization());
        $ticket = $this->paidOrder($event, $this->ticketType($event), 1)->tickets()->sole();

        Livewire::test(ListTickets::class)
            ->callTableAction('reissueTicket', $ticket, data: [
                'email' => 'ada@example.com',
                'name' => 'Ada Okafor',
                'new_code' => true,
                'reason' => 'Lost the email.',
            ])
            ->assertNotified('Not done');

        $this->assertSame(0, TicketTransfer::count());
        $this->assertSame(0, AuditLog::where('action', 'ticket.reissued')->count());
    }

    public function test_guest_list_tickets_are_listed_as_such(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));
        $event = $this->event($this->organization());
        $type = $this->ticketType($event);
        $comp = Ticket::create([
            'code' => Ticket::generateCode(),
            'event_id' => $event->id,
            'ticket_type_id' => $type->id,
            'owner_email' => 'dj@example.com',
            'holder_name' => 'The DJ',
            'admits' => 2,
            'status' => 'valid',
        ]);
        $bought = $this->paidOrder($event, $type, 1)->tickets()->sole();

        Livewire::test(ListTickets::class)
            ->filterTable('from_order', false)
            ->assertCanSeeTableRecords([$comp])
            ->assertCanNotSeeTableRecords([$bought])
            ->assertSee('Guest list')
            ->assertDontSee($comp->code);
    }
}
