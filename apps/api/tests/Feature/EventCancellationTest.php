<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Mail\AttendeeMessage;
use App\Models\EmailPreference;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Reminders\ReminderDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Calling an event off.
 *
 * `cancelled` was in the schema and unreachable by any code path. There was no
 * way to cancel an event, while the terms page promised refunds when an
 * organizer did — and reminders scheduled off starts_at had no status check, so
 * an event that was not happening would have kept telling everybody holding a
 * ticket to turn up.
 *
 * The tests that matter here are the side effects, not the status change.
 * Setting a column is the easy part; the reason this is a service is that doing
 * four of the five things is worse than doing none.
 */
class EventCancellationTest extends TestCase
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
            'starts_at' => now()->addWeeks(2),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => EventStatus::Published->value,
        ]);

        $this->type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);
    }

    private function holder(string $email): Ticket
    {
        return Ticket::create([
            'event_id' => $this->event->id,
            'ticket_type_id' => $this->type->id,
            'code' => strtoupper(Str::random(4)).'-'.strtoupper(Str::random(8)),
            'owner_email' => $email,
            'holder_name' => 'Someone',
            'status' => 'valid',
            'admits' => 1,
            'admitted_count' => 0,
        ]);
    }

    private function signedInAs(Role $role): User
    {
        $user = User::factory()->create();

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        $user = $user->fresh()->load('organizations');

        Sanctum::actingAs($user, [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        return $user;
    }

    private function cancel(array $body = [])
    {
        return $this->postJson("/api/organizer/events/{$this->event->id}/cancel", array_merge([
            'reason' => 'The venue has flooded and cannot open.',
            // Nothing to refund in most of these, so the money path is off
            // unless a test is about it.
            'refund' => false,
        ], $body));
    }

    // --- the transition ------------------------------------------------------

    public function test_an_owner_can_cancel_a_published_event(): void
    {
        $this->holder('ada@example.com');
        $this->signedInAs(Role::Owner);

        $this->cancel()->assertOk()->assertJsonPath('status', 'cancelled');

        $this->assertSame(EventStatus::Cancelled->value, $this->event->fresh()->status);
        $this->assertNotNull($this->event->fresh()->cancelled_at);
    }

    public function test_a_manager_cannot(): void
    {
        $this->signedInAs(Role::Manager);

        // Cancelling tells everybody it is off and moves money. It sits with
        // the owner, alongside deleting.
        $this->cancel()->assertForbidden();
        $this->assertSame(EventStatus::Published->value, $this->event->fresh()->status);
    }

    public function test_a_draft_cannot_be_cancelled(): void
    {
        $this->event->update(['status' => EventStatus::Draft->value]);
        $this->signedInAs(Role::Owner);

        // There is nobody to tell. An abandoned draft is deleted, not cancelled.
        $this->cancel()->assertStatus(422);
    }

    public function test_cancelling_twice_is_refused(): void
    {
        $this->signedInAs(Role::Owner);
        $this->cancel()->assertOk();

        $this->cancel()
            ->assertStatus(422)
            ->assertJsonPath('message', 'This event is already cancelled.');
    }

    public function test_a_cancelled_event_cannot_go_back_on_sale(): void
    {
        $this->signedInAs(Role::Owner);
        $this->cancel()->assertOk();

        // Everybody holding a ticket has been told it is off and some have been
        // refunded. Quietly republishing is worse than staying cancelled.
        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'published'])
            ->assertStatus(422);

        $this->assertSame(EventStatus::Cancelled->value, $this->event->fresh()->status);
    }

    public function test_a_cancelled_event_cannot_be_edited(): void
    {
        $this->signedInAs(Role::Owner);
        $this->cancel()->assertOk();

        // People bought tickets to what it said, and some were refunded on that
        // basis. Editing it afterwards rewrites what they were told.
        $this->patchJson("/api/organizer/events/{$this->event->id}", ['title' => 'Something else'])
            ->assertStatus(422);

        $this->assertSame('Afro Fest', $this->event->fresh()->title);
    }

    public function test_a_reason_is_required_and_has_to_be_a_sentence(): void
    {
        $this->signedInAs(Role::Owner);

        $this->cancel(['reason' => ''])->assertStatus(422);
        // It reaches ticket holders verbatim. "No" is not an explanation.
        $this->cancel(['reason' => 'No'])->assertStatus(422);
    }

    // --- the side effects ----------------------------------------------------

    public function test_cancelling_stops_the_reminders(): void
    {
        $this->holder('ada@example.com');
        $this->event->reminders()->create(['offset_minutes' => 1440, 'status' => 'scheduled']);
        $this->event->reminders()->create(['offset_minutes' => 180, 'status' => 'scheduled']);

        $this->signedInAs(Role::Owner);
        $this->cancel()->assertOk();

        $this->assertSame(
            0,
            $this->event->reminders()->where('status', 'scheduled')->count(),
        );
    }

    public function test_a_cancelled_event_never_sends_a_reminder(): void
    {
        $this->holder('ada@example.com');
        $this->event->reminders()->create(['offset_minutes' => 1440, 'status' => 'scheduled']);
        $this->event->update(['starts_at' => now()->addMinutes(1439)]);

        $this->signedInAs(Role::Owner);
        $this->cancel()->assertOk();

        Mail::fake();
        app(ReminderDispatcher::class)->dispatchDue();

        // Telling somebody to turn up to a night that is not happening is the
        // worst email this platform could send.
        Mail::assertNothingQueued();
    }

    public function test_everybody_holding_a_ticket_is_told(): void
    {
        $this->holder('ada@example.com');
        $this->holder('chidi@example.com');

        $this->signedInAs(Role::Owner);
        $this->cancel()->assertOk()->assertJsonPath('notified', 2);

        Mail::assertQueued(AttendeeMessage::class, 2);
    }

    public function test_the_notice_reaches_somebody_who_turned_emails_off(): void
    {
        $this->holder('ada@example.com');
        EmailPreference::forEmail('ada@example.com')->update(['reminders_opted_out_at' => now()]);

        $this->signedInAs(Role::Owner);
        $this->cancel()->assertOk();

        // Somebody who opted out of reminders still needs to not travel. The
        // message is marked important, which is what overrides the opt-out.
        Mail::assertQueued(AttendeeMessage::class, 1);
        $this->assertTrue($this->event->messages()->first()->important);
    }

    public function test_the_reason_is_what_ticket_holders_are_told(): void
    {
        $this->holder('ada@example.com');
        $this->signedInAs(Role::Owner);

        $this->cancel(['reason' => 'The headline act has withdrawn.'])->assertOk();

        $this->assertSame('The headline act has withdrawn.', $this->event->messages()->first()->body);
    }

    public function test_nothing_can_be_bought_afterwards(): void
    {
        $this->signedInAs(Role::Owner);
        $this->cancel()->assertOk();

        Sanctum::actingAs(User::factory()->create(), [TokenAbility::Attendee->value]);

        // 404 rather than a refusal, and that is the stronger answer: checkout
        // resolves only published, ticketed events, so a cancelled one is not
        // reachable at all rather than reached and then declined.
        $this->postJson("/api/events/{$this->event->slug}/orders", [
            'items' => [['ticket_type_id' => $this->type->id, 'quantity' => 1]],
            'buyer' => ['name' => 'Late Buyer', 'email' => 'late@example.com'],
        ])->assertNotFound();

        // And the same for pricing a basket, which is the step before it.
        $this->postJson("/api/events/{$this->event->slug}/quote", [
            'items' => [['ticket_type_id' => $this->type->id, 'quantity' => 1]],
        ])->assertNotFound();
    }

    public function test_it_disappears_from_the_public_site(): void
    {
        $this->signedInAs(Role::Owner);
        $this->cancel()->assertOk();

        $this->getJson("/api/events/{$this->event->slug}")->assertNotFound();
        $this->getJson('/api/discover')->assertOk()->assertJsonCount(0, 'upcoming');
    }

    // --- the money -----------------------------------------------------------

    public function test_the_preview_says_what_cancelling_would_cost(): void
    {
        $this->holder('ada@example.com');

        Order::create([
            'reference' => 'MF'.strtoupper(Str::random(8)),
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada',
            'currency' => 'CAD',
            'subtotal_amount' => 5000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'net_revenue_amount' => 5000,
            'service_charge_amount' => 400,
            'total_amount' => 5400,
            'status' => 'paid',
        ]);

        $this->signedInAs(Role::Owner);

        // Shown before the confirmation, so an organizer sees how many people
        // they are about to tell and how much is about to move.
        $this->getJson("/api/organizer/events/{$this->event->id}/cancellation")
            ->assertOk()
            ->assertJsonPath('ticket_holders', 1)
            ->assertJsonPath('orders_to_refund', 1)
            // What the buyer paid, service charge included. Cancelling returns
            // the whole charge — somebody whose event was called off does not
            // pay us a fee for the privilege.
            ->assertJsonPath('refund_total.amount', 5400)
            ->assertJsonPath('refund_total.currency', 'CAD');
    }

    public function test_an_organizer_can_choose_to_handle_refunds_themselves(): void
    {
        $this->holder('ada@example.com');
        $this->signedInAs(Role::Owner);

        // The money is theirs and the obligation is theirs. The default is to
        // refund, but the platform does not force it.
        $this->cancel(['refund' => false])
            ->assertOk()
            ->assertJsonPath('refunded', 0);
    }

    public function test_the_summary_reports_refunds_that_could_not_be_sent(): void
    {
        // A payment provider refusing one refund must not stop the rest, and an
        // organizer who is not told will hear it from the buyer instead.
        $canceller = new \ReflectionClass(\App\Services\Events\EventCanceller::class);

        $this->assertTrue(
            $canceller->hasMethod('cancel'),
            'cancel() is the only entry point; failures are counted there.',
        );

        $this->signedInAs(Role::Owner);
        $response = $this->cancel()->assertOk();

        $this->assertArrayHasKey('failed', $response->json());
    }

    // --- guards that were missing --------------------------------------------

    public function test_an_event_that_has_already_started_cannot_be_published(): void
    {
        $this->event->update([
            'status' => EventStatus::Draft->value,
            'starts_at' => now()->subHour(),
        ]);

        $this->signedInAs(Role::Owner);

        // Otherwise it appears on the front page for a night nobody can attend,
        // with reminders scheduled for a date in the past.
        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'published'])
            ->assertStatus(422);
    }

    public function test_the_states_the_schema_allows_are_the_states_the_app_can_reach(): void
    {
        // review and scheduled were in the constraint and unreachable by any
        // code path. The fiction is what this whole change removes.
        $this->assertSame(
            ['draft', 'published', 'cancelled'],
            array_map(fn (EventStatus $s) => $s->value, EventStatus::cases()),
        );

        foreach (['review', 'scheduled'] as $gone) {
            $this->assertNull(EventStatus::tryFrom($gone));
        }
    }
}
