<?php

namespace Tests\Feature;

use App\Exceptions\CheckoutException;
use App\Models\Code;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\Fulfiller;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reserving stock, and issuing tickets once money has actually arrived.
 */
class CheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    private CheckoutService $checkout;

    private Fulfiller $fulfiller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxRateSeeder::class);
        $this->checkout = app(CheckoutService::class);
        $this->fulfiller = app(Fulfiller::class);
    }

    private function event(): Event
    {
        return Event::create([
            'organization_id' => Organization::create(['name' => 'N', 'slug' => 'n-'.uniqid()])->id,
            'slug' => 'e-'.uniqid(),
            'title' => 'Test Event',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);
    }

    private function ticket(Event $e, int $price = 10000, array $attrs = []): TicketType
    {
        return TicketType::create(array_merge([
            'event_id' => $e->id,
            'name' => 'General',
            'price_amount' => $price,
            'status' => 'on_sale',
        ], $attrs));
    }

    private function reserve(Event $e, TicketType $t, int $qty = 1, ?string $code = null): Order
    {
        return $this->checkout->reserve($e, [$t->id => $qty], 'buyer@example.com', 'Ada Buyer', $code);
    }

    public function test_reserving_creates_a_pending_order_with_server_side_figures(): void
    {
        $event = $this->event();
        $type = $this->ticket($event);

        $order = $this->reserve($event, $type, 2);

        $this->assertSame('pending', $order->status);
        $this->assertSame(20000, $order->subtotal_amount);
        $this->assertSame(2600, $order->tax_amount);
        $this->assertSame(20000, $order->net_revenue_amount, 'The organizer is owed the ticket price.');
        $this->assertSame(1600, $order->service_charge_amount, 'The buyer pays the 8% on top.');
        $this->assertSame(24200, $order->total_amount);
        $this->assertSame('CAD', $order->currency);
        $this->assertCount(1, $order->lines);

        // Nothing is issued until money arrives.
        $this->assertSame(0, Ticket::count());
    }

    public function test_stock_cannot_be_oversold(): void
    {
        $event = $this->event();
        $type = $this->ticket($event, 10000, ['quantity_available' => 3]);

        $this->reserve($event, $type, 2);

        // A live hold is stock somebody is in the middle of buying. Treating it
        // as available is how two people pay for the same seat.
        $this->expectException(CheckoutException::class);
        $this->reserve($event, $type, 2);
    }

    public function test_an_expired_hold_returns_the_stock(): void
    {
        $event = $this->event();
        $type = $this->ticket($event, 10000, ['quantity_available' => 2]);

        $this->reserve($event, $type, 2);

        $this->travel(25)->minutes();

        // Abandoned baskets must not sell out an event forever.
        $order = $this->reserve($event, $type, 2);
        $this->assertSame('pending', $order->status);
    }

    public function test_a_capped_code_stops_being_redeemable(): void
    {
        $event = $this->event();
        $type = $this->ticket($event);

        Code::create([
            'organization_id' => $event->organization_id,
            'code' => 'FIRST',
            'discount_type' => 'percentage',
            'discount_value' => 1000,
            'max_redemptions' => 1,
        ]);

        $this->reserve($event, $type, 1, 'FIRST');

        // A code capped at one use is exactly as raceable as a ticket capped at
        // one, and is counted under the same lock.
        $this->expectException(CheckoutException::class);
        $this->reserve($event, $type, 1, 'FIRST');
    }

    public function test_fulfilment_issues_one_ticket_per_unit_and_writes_the_ledger(): void
    {
        $event = $this->event();
        $type = $this->ticket($event);

        $order = $this->fulfiller->fulfil($this->reserve($event, $type, 3));

        $this->assertSame('paid', $order->status);
        $this->assertSame(3, Ticket::where('order_id', $order->id)->count());

        // Separable facts, not one net figure: an organizer asking why they are
        // owed what they are owed needs to see each component.
        $types = LedgerEntry::where('order_id', $order->id)->pluck('amount', 'type');
        // The gross ticket side, HST included, because the entry below takes
        // the HST back out. Recording the tax-free subtotal here and removing
        // the tax anyway is what left Canadian organizers short the whole 13%.
        $this->assertSame(33900, (int) $types['sale']);
        $this->assertSame(-3900, (int) $types['tax'], 'Tax is never the organizer money.');

        // No service charge entry. The buyer paid it to the platform; it was
        // never in this balance to take out.
        $this->assertFalse($types->has('commission'));
        $this->assertSame(30000, (int) $types->sum(), 'What the organizer is owed.');
    }

    public function test_ticket_codes_are_unique_and_unpredictable(): void
    {
        $event = $this->event();
        $type = $this->ticket($event);

        $this->fulfiller->fulfil($this->reserve($event, $type, 10));

        $codes = Ticket::pluck('code');

        $this->assertCount(10, $codes->unique(), 'Codes must never collide.');

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[ACDEFHJKLMNPQRTVWXY34789]{4}-[ACDEFHJKLMNPQRTVWXY34789]{8}$/', $code);
            // Only one of each confusable pair survives, so a code read out
            // over noise has a single spelling. 8 and 9 stay because B and G
            // are the ones that were dropped.
            $this->assertDoesNotMatchRegularExpression('/[O0I1S5Z2BG6U]/', $code);
        }
    }

    public function test_fulfilment_is_idempotent(): void
    {
        $event = $this->event();
        $type = $this->ticket($event);

        $order = $this->reserve($event, $type, 2);

        // Gateways retry for days. A duplicate delivery must not mint a second
        // set of tickets.
        $this->fulfiller->fulfil($order);
        $this->fulfiller->fulfil($order);
        $this->fulfiller->fulfil($order);

        $this->assertSame(2, Ticket::count());

        // Two entries, not three: the sale and the tax. There is no service
        // charge entry because the service charge never enters the organizer's
        // balance — the buyer paid it to the platform.
        $this->assertSame(2, LedgerEntry::where('order_id', $order->id)->count());
        $this->assertSame(
            $order->net_revenue_amount,
            (int) LedgerEntry::where('order_id', $order->id)->sum('amount'),
            'What the ledger says the organizer is owed is the ticket price.',
        );
    }

    public function test_a_free_order_skips_the_gateway_entirely(): void
    {
        $event = $this->event();
        $type = $this->ticket($event, 0);

        $order = $this->reserve($event, $type, 1);

        $this->assertSame(0, $order->total_amount);
        $this->assertFalse($order->requiresPayment());

        // Comps, RSVPs, and full-value codes have nothing to charge. Checkout
        // that assumes a payment session exists breaks the first time an
        // organizer comps someone, which they will do in week one.
        $order = $this->fulfiller->fulfilFree($order);

        $this->assertSame('paid', $order->status);
        $this->assertSame(1, Ticket::count());
    }

    public function test_fulfil_free_refuses_an_order_with_a_balance(): void
    {
        $event = $this->event();
        $type = $this->ticket($event, 10000);

        $order = $this->reserve($event, $type, 1);

        // Otherwise it would be a way to mark a payable order paid without a
        // webhook, which is the whole thing this design prevents.
        $this->expectException(\LogicException::class);
        $this->fulfiller->fulfilFree($order);
    }

    public function test_every_buyer_gets_an_owner_record_even_without_an_account(): void
    {
        $event = $this->event();
        $type = $this->ticket($event);

        $order = $this->fulfiller->fulfil($this->reserve($event, $type, 1));

        $ticket = Ticket::first();

        // Accounts are optional; ownership is not. This is what makes transfer
        // an authenticated act rather than a public endpoint on a sequential id.
        $this->assertNotNull($ticket->owner_user_id);
        $this->assertSame('buyer@example.com', $ticket->owner_email);
        $this->assertTrue($ticket->owner->isUnclaimed(), 'Guest buyers start unclaimed.');
    }
}
