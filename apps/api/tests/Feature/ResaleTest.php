<?php

namespace Tests\Feature;

use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Contracts\Payments\RefundResult;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Refund;
use App\Models\ResaleListing;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Checkout\Fulfiller;
use App\Services\Door\CheckInService;
use App\Services\Door\ScanOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Giving a ticket back.
 *
 * The argument against touts, so the things that must hold are the ones that
 * make touting pointless rather than merely discouraged. A returned ticket
 * stops working the moment it is handed back, so nobody can sell a place and
 * walk in on it. The place goes back at the organizer's price, so there is no
 * price to inflate. And the seller gets exactly what they paid, when it sells
 * and not before — because a refund before the place is taken is the
 * organizer paying for somebody's change of heart.
 */
class ResaleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $general;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('name')->andReturn('stripe');
        $gateway->shouldReceive('supports')->andReturn(true);
        $gateway->shouldReceive('refund')->andReturnUsing(
            fn (Order $order, int $amount) => new RefundResult(true, 're_'.Str::random(6), $amount, $order->currency));

        $registry = new PaymentGatewayRegistry;
        $registry->register($gateway);
        $this->app->instance(PaymentGatewayRegistry::class, $registry);

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
            'status' => 'published',
            'resale_enabled' => true,
        ]);

        $this->general = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'quantity_available' => 2,
            'status' => 'on_sale',
        ]);
    }

    /** A paid order for one General ticket, through the ordinary fulfilment. */
    private function buy(string $email, int $quantity = 1, int $discount = 0): Order
    {
        $subtotal = 5000 * $quantity;

        $order = Order::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => $email,
            'buyer_name' => 'Buyer',
            'currency' => 'CAD',
            'subtotal_amount' => $subtotal,
            'discount_amount' => $discount,
            'net_revenue_amount' => $subtotal - $discount,
            'service_charge_amount' => 500 * $quantity,
            'total_amount' => $subtotal - $discount + 500 * $quantity,
            'gateway' => 'stripe',
            'gateway_reference' => 'pi_'.Str::random(8),
            'status' => 'pending',
        ]);

        $order->lines()->create([
            'ticket_type_id' => $this->general->id,
            'name' => 'General',
            'unit_price_amount' => 5000,
            'quantity' => $quantity,
            'line_total_amount' => $subtotal,
            'discount_amount' => $discount,
        ]);

        return app(Fulfiller::class)->fulfil($order->refresh());
    }

    private function listBack(Order $order, ?Ticket $ticket = null): TestResponse
    {
        $ticket ??= $order->tickets()->first();

        return $this->postJson("/api/tickets/{$order->access_token}/resale/{$ticket->id}");
    }

    // --- handing one back --------------------------------------------------------

    public function test_a_returned_ticket_stops_working_and_frees_the_place(): void
    {
        $seller = $this->buy('ada@example.com');
        $ticket = $seller->tickets()->first();

        $this->assertSame(1, $this->general->fresh()->remainingNow());

        $this->listBack($seller)->assertCreated();

        $this->assertSame('listed', $ticket->fresh()->status);
        // The place is on sale again, which is the whole mechanism.
        $this->assertSame(2, $this->general->fresh()->remainingNow());
        // And nothing has been paid back yet: nobody has taken the place.
        $this->assertSame(0, Refund::count());
    }

    public function test_a_returned_ticket_is_turned_away_at_the_door(): void
    {
        $seller = $this->buy('ada@example.com');
        $ticket = $seller->tickets()->first();
        $this->listBack($seller)->assertCreated();

        $outcome = app(CheckInService::class)->scan($ticket->code, $this->event->id);

        $this->assertSame(ScanOutcome::VOID, $outcome->result);
        $this->assertStringContainsString('handed back', $outcome->message);
    }

    public function test_its_qr_disappears_from_the_buyers_own_page(): void
    {
        $seller = $this->buy('ada@example.com');
        $this->listBack($seller)->assertCreated();

        $response = $this->getJson("/api/tickets/{$seller->access_token}")->assertOk();

        $this->assertNull($response->json('tickets.0.qr'));
        $this->assertNull($response->json('tickets.0.code'));
        $this->assertTrue($response->json('tickets.0.return.listed'));
    }

    public function test_it_can_be_taken_back_off_the_list_until_it_sells(): void
    {
        $seller = $this->buy('ada@example.com');
        $ticket = $seller->tickets()->first();
        $this->listBack($seller)->assertCreated();

        $this->deleteJson("/api/tickets/{$seller->access_token}/resale/{$ticket->id}")->assertOk();

        $this->assertSame('valid', $ticket->fresh()->status);
        $this->assertSame('cancelled', ResaleListing::sole()->status);
        $this->assertSame(1, $this->general->fresh()->remainingNow());
    }

    // --- selling it on ---------------------------------------------------------------

    public function test_the_seller_gets_back_what_they_paid_when_somebody_takes_the_place(): void
    {
        $seller = $this->buy('ada@example.com');
        $this->listBack($seller)->assertCreated();

        // Somebody else buys, through the ordinary checkout, at the
        // organizer's price. They are never told whose place it was.
        $buyer = $this->buy('chidi@example.com');

        $listing = ResaleListing::sole();
        $this->assertSame('sold', $listing->status);
        $this->assertSame($buyer->id, $listing->sold_to_order_id);

        $refund = Refund::sole();
        $this->assertSame('succeeded', $refund->status);
        // Everything they paid, the booking fee included: they are not buying
        // a service, they are getting their evening back.
        $this->assertSame(5500, (int) $refund->amount);
        $this->assertSame($seller->id, $refund->order_id);

        $this->assertSame('refunded', $seller->tickets()->first()->fresh()->status);
        $this->assertSame(1, $buyer->tickets()->count());
    }

    public function test_the_organizer_is_left_whole(): void
    {
        $seller = $this->buy('ada@example.com');
        $this->listBack($seller)->assertCreated();
        $this->buy('chidi@example.com');

        // One sale in, one sale out, one refund: the organizer has been paid
        // for exactly one ticket, which is what they sold.
        $net = (int) LedgerEntry::where('event_id', $this->event->id)->sum('amount');

        $this->assertSame(5000, $net);
    }

    public function test_the_longest_wait_goes_first(): void
    {
        $this->general->update(['quantity_available' => 5]);

        $first = $this->buy('first@example.com');
        $this->travel(1)->hours();
        $second = $this->buy('second@example.com');

        $this->listBack($second)->assertCreated();
        $this->travel(1)->hours();
        $this->listBack($first)->assertCreated();

        // Second listed first, so second is paid first.
        $this->buy('chidi@example.com');

        $this->assertSame('sold', ResaleListing::where('order_id', $second->id)->sole()->status);
        $this->assertSame('listed', ResaleListing::where('order_id', $first->id)->sole()->status);
        $this->assertSame($second->id, Refund::sole()->order_id);
    }

    public function test_buying_a_second_place_does_not_resell_your_own_returned_one(): void
    {
        $this->general->update(['quantity_available' => 5]);
        $seller = $this->buy('ada@example.com');
        $this->listBack($seller)->assertCreated();

        // The same order buying again would otherwise be matched to itself,
        // refunding somebody for a place they just paid for.
        app(Fulfiller::class)->fulfil($seller->refresh());

        $this->assertSame('listed', ResaleListing::sole()->status);
        $this->assertSame(0, Refund::count());
    }

    public function test_a_ticket_bought_with_a_discount_is_worth_what_was_paid(): void
    {
        // Two tickets at 5000 with 2000 off the line: 4000 each.
        $this->general->update(['quantity_available' => 5]);
        $seller = $this->buy('ada@example.com', quantity: 2, discount: 2000);

        $this->listBack($seller)->assertCreated();

        $this->assertSame(4000, (int) ResaleListing::sole()->price_amount);
    }

    // --- when it cannot be done ------------------------------------------------------

    public function test_an_organizer_who_has_not_allowed_it_is_the_end_of_it(): void
    {
        $this->event->update(['resale_enabled' => false]);
        $seller = $this->buy('ada@example.com');

        $this->listBack($seller)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This organizer is not taking tickets back for this event.');
    }

    public function test_it_closes_before_the_doors(): void
    {
        $seller = $this->buy('ada@example.com');
        $this->event->update(['starts_at' => now()->addHours(3), 'resale_closes_hours' => 24]);

        $this->listBack($seller)->assertStatus(422)->assertJsonPath(
            'message',
            'Tickets can be given back up to 24 hours before the doors, and it is later than that now.',
        );
    }

    public function test_a_ticket_already_used_cannot_be_given_back(): void
    {
        $seller = $this->buy('ada@example.com');
        $ticket = $seller->tickets()->first();
        app(CheckInService::class)->scan($ticket->code, $this->event->id);

        $this->listBack($seller)->assertStatus(422)->assertJsonPath('message', 'This ticket has already been used to get in.');
    }

    public function test_somebody_elses_ticket_is_not_theirs_to_return(): void
    {
        $mine = $this->buy('ada@example.com');
        $theirs = $this->buy('chidi@example.com');

        $this->postJson("/api/tickets/{$mine->access_token}/resale/{$theirs->tickets()->first()->id}")
            ->assertNotFound();

        $this->assertSame('valid', $theirs->tickets()->first()->fresh()->status);
    }

    public function test_a_made_up_link_says_nothing(): void
    {
        $seller = $this->buy('ada@example.com');

        $this->postJson("/api/tickets/not-a-token/resale/{$seller->tickets()->first()->id}")->assertNotFound();
    }

    public function test_one_ticket_cannot_be_listed_twice(): void
    {
        $seller = $this->buy('ada@example.com');

        $this->listBack($seller)->assertCreated();
        $this->listBack($seller)->assertStatus(422)->assertJsonPath('message', 'This one is already waiting for somebody to take it.');

        $this->assertSame(1, ResaleListing::count());
    }
}
