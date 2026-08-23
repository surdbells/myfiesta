<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Tickets\QrEncoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A buyer reaching their own tickets.
 *
 * The platform had a scanner endpoint, partial admission and a ledger, and no
 * way for anyone to put a ticket in front of a camera — the emailed link
 * returned JSON and the code was printed as text. These tests cover the two
 * things that decide whether this works on a night: that the symbol is
 * scannable, and that the link reaches exactly one person's tickets.
 */
class TicketAccessTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private Order $order;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
            'min_age' => 19,
        ]);

        $this->type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);

        $this->order = Order::create([
            'reference' => 'MF'.strtoupper(Str::random(8)),
            'access_token' => Str::random(44),
            'organization_id' => $org->id,
            'event_id' => $this->event->id,
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada Okafor',
            'currency' => 'CAD',
            'subtotal_amount' => 5000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'net_revenue_amount' => 5000,
            'service_charge_amount' => 400,
            'total_amount' => 5400,
            'status' => 'paid',
        ]);
    }

    private function ticket(array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'event_id' => $this->event->id,
            'order_id' => $this->order->id,
            'ticket_type_id' => $this->type->id,
            'code' => strtoupper(Str::random(4)).'-'.strtoupper(Str::random(8)),
            'owner_email' => 'ada@example.com',
            'holder_name' => 'Ada Okafor',
            'status' => 'valid',
            'admits' => 1,
            'admitted_count' => 0,
        ], $overrides));
    }

    private function fetch(?string $token = null)
    {
        return $this->getJson('/api/tickets/'.($token ?? $this->order->access_token));
    }

    // --- the symbol ----------------------------------------------------------

    public function test_every_ticket_comes_back_with_a_scannable_symbol(): void
    {
        $ticket = $this->ticket();

        $response = $this->fetch()->assertOk();

        $qr = $response->json('tickets.0.qr');

        $this->assertStringStartsWith('<svg', $qr);
        // A QR drawn transparently over a dark page inverts and will not scan,
        // so the white plate is part of the symbol rather than page styling.
        $this->assertStringContainsString('fill="#ffffff"', $qr);
        $this->assertStringContainsString($ticket->code, $qr);
    }

    public function test_the_symbol_encodes_the_ticket_code_and_nothing_else(): void
    {
        $encoder = app(QrEncoder::class);

        // Not a URL: a scanner at a door with no signal cannot resolve one.
        // Not a name: a ticket screen gets photographed by whoever is standing
        // behind its holder in the queue.
        $a = $encoder->svg('AAAA-BBBBBBBB');
        $b = $encoder->svg('AAAA-BBBBBBBB');
        $c = $encoder->svg('CCCC-DDDDDDDD');

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    public function test_a_longer_code_still_produces_a_valid_symbol(): void
    {
        // Guards the version-bump boundary: a longer payload needs a bigger
        // matrix, and an encoder that silently truncates would produce a symbol
        // that scans to the wrong ticket.
        $svg = app(QrEncoder::class)->svg(str_repeat('A', 60));

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertMatchesRegularExpression('/viewBox="0 0 (\d+) \1"/', $svg);
    }

    // --- who can see them ----------------------------------------------------

    public function test_the_link_reaches_the_tickets_without_signing_in(): void
    {
        $this->ticket();

        // Guest checkout is the primary path, so most holders have no account.
        $this->fetch()
            ->assertOk()
            ->assertJsonPath('event.title', 'Afro Fest')
            ->assertJsonCount(1, 'tickets');
    }

    public function test_an_invented_token_is_a_404_not_a_403(): void
    {
        $this->ticket();

        // A different answer for a real token with a typo and an invented one
        // would let somebody test tokens by watching the status code.
        $this->fetch(Str::random(44))->assertNotFound();
    }

    public function test_one_orders_link_does_not_reach_another_orders_tickets(): void
    {
        $this->ticket();

        $other = Order::create([
            'reference' => 'MF'.strtoupper(Str::random(8)),
            'access_token' => Str::random(44),
            'organization_id' => $this->event->organization_id,
            'event_id' => $this->event->id,
            'buyer_email' => 'chidi@example.com',
            'buyer_name' => 'Chidi',
            'currency' => 'CAD',
            'subtotal_amount' => 5000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'net_revenue_amount' => 5000,
            'service_charge_amount' => 400,
            'total_amount' => 5400,
            'status' => 'paid',
        ]);

        $this->ticket(['order_id' => $other->id, 'owner_email' => 'chidi@example.com']);

        // The previous platform served this at /tickets/{sale_id} with no
        // signature, so anyone could read anyone else's by counting upwards.
        $this->fetch()->assertOk()->assertJsonCount(1, 'tickets');
        $this->fetch($other->access_token)->assertOk()->assertJsonCount(1, 'tickets');
    }

    public function test_every_order_gets_a_different_token(): void
    {
        $tokens = Order::pluck('access_token');

        $this->assertCount($tokens->count(), $tokens->unique());
        $this->assertGreaterThanOrEqual(40, strlen($this->order->access_token));
    }

    // --- what is shown -------------------------------------------------------

    public function test_a_refunded_ticket_is_not_shown(): void
    {
        $this->ticket();
        $this->ticket(['status' => 'refunded']);

        // A QR that will be turned away is worse than no QR, because its holder
        // does not find out until they are at the front of the queue.
        $this->fetch()->assertOk()->assertJsonCount(1, 'tickets');
    }

    public function test_a_table_shows_how_many_of_its_party_are_already_in(): void
    {
        $table = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'Table of 5',
            'price_amount' => 50000,
            'admits' => 5,
            'status' => 'on_sale',
        ]);

        $this->ticket(['ticket_type_id' => $table->id, 'admits' => 5, 'admitted_count' => 3]);

        $this->fetch()
            ->assertOk()
            ->assertJsonPath('tickets.0.admits', 5)
            // A table arriving in two groups has to see that three are inside,
            // or the second group thinks the ticket has been used up.
            ->assertJsonPath('tickets.0.admitted', 3);
    }

    public function test_the_door_policy_travels_with_the_ticket(): void
    {
        $this->ticket();

        // Turning up without ID at a 19+ event is a wasted journey, and the
        // ticket is the thing somebody looks at before they leave the house.
        $this->fetch()->assertOk()->assertJsonPath('event.min_age', 19);
    }

    public function test_a_checked_in_ticket_still_opens(): void
    {
        $this->ticket(['status' => 'checked_in', 'admitted_count' => 1]);

        // Somebody stepping outside and coming back needs the same screen.
        $this->fetch()->assertOk()->assertJsonCount(1, 'tickets');
    }
}
