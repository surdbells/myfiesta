<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Mail\TicketIssued;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Door\CheckInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issuing a ticket by hand.
 *
 * Comps, guest list, somebody who paid cash at the door. A hand-issued ticket
 * has to be an ordinary ticket — the previous platform had a separate path for
 * free tickets, which is how magic values ended up in the middle of its tickets
 * table.
 */
class ManualTicketIssueTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $this->type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);
    }

    private function member(Role $role): User
    {
        $user = User::factory()->create();

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        return $user->fresh()->load('organizations');
    }

    private function asOrganizer(User $user): void
    {
        Sanctum::actingAs($user, [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);
    }

    private function issue(array $overrides = []): TestResponse
    {
        return $this->postJson("/api/organizer/events/{$this->event->id}/tickets", array_merge([
            'ticket_type_id' => $this->type->id,
            'name' => 'Ada Okafor',
            'email' => 'ada@example.com',
        ], $overrides));
    }

    public function test_a_manager_can_issue_a_ticket(): void
    {
        $this->asOrganizer($this->member(Role::Manager));

        $this->issue()
            ->assertCreated()
            ->assertJsonPath('message', 'Ticket issued.')
            ->assertJsonCount(1, 'tickets');

        $this->assertSame(1, Ticket::count());
    }

    public function test_a_hand_issued_ticket_is_an_ordinary_ticket(): void
    {
        $this->asOrganizer($this->member(Role::Manager));
        $this->issue();

        $ticket = Ticket::first();

        // Same code format, same owner model, no order behind it, and nothing
        // magic sitting in a column to mark it as different.
        $this->assertMatchesRegularExpression('/^[ACDEFHJKLMNPQRTVWXY34789]{4}-[ACDEFHJKLMNPQRTVWXY34789]{8}$/', $ticket->code);
        $this->assertNull($ticket->order_id);
        $this->assertNotNull($ticket->owner_user_id);
        $this->assertSame('valid', $ticket->status);
    }

    public function test_a_hand_issued_ticket_opens_the_door(): void
    {
        $this->asOrganizer($this->member(Role::Manager));
        $this->issue();

        $ticket = Ticket::first();
        $outcome = app(CheckInService::class)->scan($ticket->code, $this->event->id);

        // No new scanner path, no new check-in logic.
        $this->assertTrue($outcome->admittedAnyone());
    }

    public function test_a_table_issued_by_hand_still_admits_its_whole_party(): void
    {
        $table = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'Table of 5',
            'price_amount' => 50000,
            'admits' => 5,
            'status' => 'on_sale',
        ]);

        $this->asOrganizer($this->member(Role::Manager));
        $this->issue(['ticket_type_id' => $table->id])
            ->assertCreated()
            ->assertJsonPath('tickets.0.admits', 5);

        $ticket = Ticket::first();
        $outcome = app(CheckInService::class)->scan($ticket->code, $this->event->id, party: 3);

        $this->assertSame(3, $outcome->admitted);
        $this->assertSame(2, $outcome->remaining);
    }

    public function test_several_can_be_issued_at_once(): void
    {
        $this->asOrganizer($this->member(Role::Manager));

        $this->issue(['quantity' => 4])
            ->assertCreated()
            ->assertJsonPath('message', '4 tickets issued.')
            ->assertJsonCount(4, 'tickets');

        // Distinct codes, not four copies of one.
        $this->assertSame(4, Ticket::distinct('code')->count('code'));
    }

    public function test_comps_count_against_the_room(): void
    {
        $this->type->update(['quantity_available' => 2]);

        $this->asOrganizer($this->member(Role::Manager));

        $this->issue(['quantity' => 2])->assertCreated();

        // A venue holds the number of people it holds. A guest list that does
        // not count is how an event sells out and then admits forty more.
        $this->issue()->assertStatus(422);
        $this->assertSame(2, Ticket::count());
    }

    public function test_a_sold_out_type_says_so_rather_than_failing_vaguely(): void
    {
        $this->type->update(['quantity_available' => 1]);
        $this->asOrganizer($this->member(Role::Manager));
        $this->issue()->assertCreated();

        $this->issue()
            ->assertStatus(422)
            ->assertJsonPath('message', 'General has sold out — none left to issue.');
    }

    public function test_no_email_is_sent_unless_asked_for(): void
    {
        $this->asOrganizer($this->member(Role::Manager));
        $this->issue();

        // Adding forty names to a guest list should not fire forty emails, and
        // a comp handed over in person needs none at all.
        Mail::assertNothingQueued();
    }

    public function test_an_email_can_be_sent_with_a_note(): void
    {
        $this->asOrganizer($this->member(Role::Manager));

        $this->issue(['send_email' => true, 'note' => 'Ask for Ade at the door.'])
            ->assertCreated();

        Mail::assertQueued(TicketIssued::class, fn ($mail) => $mail->hasTo('ada@example.com')
            && $mail->note === 'Ask for Ade at the door.');
    }

    public function test_marketing_cannot_mint_tickets(): void
    {
        $this->asOrganizer($this->member(Role::Marketing));

        // Messaging attendees is not the same as creating them.
        $this->issue()->assertForbidden();
        $this->assertSame(0, Ticket::count());
    }

    public function test_door_staff_cannot_mint_tickets(): void
    {
        $this->asOrganizer($this->member(Role::Door));

        $this->issue()->assertForbidden();
    }

    public function test_a_ticket_type_from_another_event_is_refused(): void
    {
        $other = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'other',
            'title' => 'Other',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'draft',
        ]);

        $foreign = TicketType::create([
            'event_id' => $other->id,
            'name' => 'Theirs',
            'price_amount' => 100,
            'status' => 'on_sale',
        ]);

        $this->asOrganizer($this->member(Role::Manager));

        $this->issue(['ticket_type_id' => $foreign->id])->assertNotFound();
        $this->assertSame(0, Ticket::count());
    }
}
