<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketTransfer;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\Fulfiller;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The API the clients consume.
 *
 * Weighted towards the boundaries rather than the happy paths: what a token
 * cannot reach matters more than what it can, because the mobile app carries
 * all three modes in one binary and the server is the only thing deciding.
 */
class ApiSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxRateSeeder::class);

        $this->event = Event::create([
            'organization_id' => Organization::create(['name' => 'N', 'slug' => 'n-'.uniqid()])->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 10000,
            'status' => 'on_sale',
        ]);
    }

    private function items(int $qty = 2): array
    {
        return ['items' => [['ticket_type_id' => $this->type->id, 'quantity' => $qty]]];
    }

    // --- discovery ---------------------------------------------------------

    public function test_events_are_listed_without_a_token(): void
    {
        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'afro-fest')
            ->assertJsonPath('data.0.from_price.amount', 10000)
            ->assertJsonPath('data.0.from_price.currency', 'CAD');
    }

    public function test_an_event_page_carries_what_a_social_card_needs(): void
    {
        $this->getJson('/api/events/afro-fest')
            ->assertOk()
            ->assertJsonPath('data.title', 'Afro Fest')
            ->assertJsonPath('data.currency', 'CAD')
            ->assertJsonCount(1, 'data.ticket_types');
    }

    public function test_an_invitation_event_is_not_findable_by_slug(): void
    {
        $this->event->update(['kind' => 'invitation']);

        // 404 rather than 403: the public API should not confirm that
        // somebody's wedding exists at all.
        $this->getJson('/api/events/afro-fest')->assertNotFound();
    }

    // --- quoting -----------------------------------------------------------

    public function test_a_quote_prices_the_basket_server_side(): void
    {
        $this->postJson('/api/events/afro-fest/quote', $this->items())
            ->assertOk()
            ->assertJsonPath('subtotal.amount', 20000)
            ->assertJsonPath('tax.amount', 2600)
            ->assertJsonPath('total.amount', 22600)
            ->assertJsonPath('tax_inclusive', false)
            ->assertJsonPath('tax_label', 'HST');
    }

    public function test_a_client_cannot_send_a_price(): void
    {
        $response = $this->postJson('/api/events/afro-fest/quote', [
            'items' => [[
                'ticket_type_id' => $this->type->id,
                'quantity' => 1,
                // Ignored — there is no rule that reads it and no code path
                // that could. This is the defect that cost the old platform
                // money, asserted rather than assumed.
                'price' => 1,
                'unit_amount' => 1,
            ]],
            'total' => 1,
        ]);

        $response->assertOk()->assertJsonPath('total.amount', 11300);
    }

    public function test_duplicate_line_items_are_summed_not_overwritten(): void
    {
        $this->postJson('/api/events/afro-fest/quote', ['items' => [
            ['ticket_type_id' => $this->type->id, 'quantity' => 3],
            ['ticket_type_id' => $this->type->id, 'quantity' => 2],
        ]])
            ->assertOk()
            ->assertJsonPath('subtotal.amount', 50000);
    }

    public function test_an_empty_basket_is_rejected(): void
    {
        $this->postJson('/api/events/afro-fest/quote', ['items' => []])
            ->assertStatus(422);
    }

    // --- ordering ----------------------------------------------------------

    public function test_a_free_order_is_fulfilled_without_a_gateway(): void
    {
        $this->type->update(['price_amount' => 0]);

        $this->postJson('/api/events/afro-fest/orders', $this->items() + [
            'buyer' => ['name' => 'Ada', 'email' => 'ada@example.com'],
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('payment', null);

        $this->assertSame(2, Ticket::count());
    }

    public function test_ordering_requires_buyer_details(): void
    {
        $this->postJson('/api/events/afro-fest/orders', $this->items())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['buyer.name', 'buyer.email']);
    }

    // --- attendee ----------------------------------------------------------

    public function test_my_tickets_needs_a_token(): void
    {
        $this->getJson('/api/me/tickets')->assertUnauthorized();
    }

    public function test_my_tickets_returns_only_my_own(): void
    {
        $mine = $this->paidTicketFor('mine@example.com');
        $this->paidTicketFor('theirs@example.com');

        Sanctum::actingAs($mine->owner, [TokenAbility::Attendee->value]);

        $this->getJson('/api/me/tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $mine->code);
    }

    public function test_a_ticket_cannot_be_transferred_by_someone_who_does_not_own_it(): void
    {
        $ticket = $this->paidTicketFor('owner@example.com');
        $stranger = User::factory()->create();

        Sanctum::actingAs($stranger, [TokenAbility::Attendee->value]);

        // The old endpoint took a ticket id and an email from anyone, and ids
        // were sequential integers.
        $this->postJson("/api/tickets/{$ticket->id}/transfer", [
            'email' => 'thief@example.com', 'name' => 'Thief',
        ])->assertForbidden();

        $this->assertSame('owner@example.com', $ticket->refresh()->owner_email);
    }

    public function test_an_owner_can_transfer_their_ticket(): void
    {
        $ticket = $this->paidTicketFor('owner@example.com');

        Sanctum::actingAs($ticket->owner, [TokenAbility::Attendee->value]);

        $this->postJson("/api/tickets/{$ticket->id}/transfer", [
            'email' => 'friend@example.com', 'name' => 'Friend',
        ])->assertOk();

        $this->assertSame('friend@example.com', $ticket->refresh()->owner_email);
        $this->assertSame(1, TicketTransfer::count());
    }

    public function test_a_used_ticket_cannot_be_transferred(): void
    {
        $ticket = $this->paidTicketFor('owner@example.com');
        $ticket->update(['status' => 'checked_in']);

        Sanctum::actingAs($ticket->owner, [TokenAbility::Attendee->value]);

        // Handing on a ticket already scanned is handing over an empty envelope.
        $this->postJson("/api/tickets/{$ticket->id}/transfer", [
            'email' => 'friend@example.com', 'name' => 'Friend',
        ])->assertStatus(422);
    }

    // --- the door ----------------------------------------------------------

    public function test_scanning_needs_a_token(): void
    {
        $this->postJson("/api/events/{$this->event->id}/scan", ['code' => 'XXXX-XXXXXXXX'])
            ->assertUnauthorized();
    }

    public function test_an_attendee_token_cannot_scan(): void
    {
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::Attendee->value]);

        $this->postJson("/api/events/{$this->event->id}/scan", ['code' => 'XXXX-XXXXXXXX'])
            ->assertForbidden();
    }

    public function test_a_door_token_scans_its_own_event(): void
    {
        $ticket = $this->paidTicketFor('guest@example.com');
        $staff = User::factory()->create();

        Sanctum::actingAs($staff, [TokenAbility::doorFor($this->event->id)]);

        $this->postJson("/api/events/{$this->event->id}/scan", ['code' => $ticket->code])
            ->assertOk()
            ->assertJsonPath('admitted', true);
    }

    public function test_a_door_token_cannot_scan_another_event(): void
    {
        $other = Event::create([
            'organization_id' => $this->event->organization_id,
            'slug' => 'other-'.uniqid(),
            'title' => 'Other',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);

        Sanctum::actingAs(User::factory()->create(), [TokenAbility::doorFor($this->event->id)]);

        // A door grant is for one night at one venue. It must not become a
        // general scanning credential.
        $this->postJson("/api/events/{$other->id}/scan", ['code' => 'XXXX-XXXXXXXX'])
            ->assertForbidden();
    }

    private function paidTicketFor(string $email): Ticket
    {
        $order = app(CheckoutService::class)
            ->reserve($this->event, [$this->type->id => 1], $email, 'Buyer');

        app(Fulfiller::class)->fulfil($order);

        return Ticket::where('owner_email', $email)->firstOrFail();
    }
}
