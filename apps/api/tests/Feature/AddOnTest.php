<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Exceptions\CheckoutException;
use App\Http\Controllers\Api\Organizer\AddOnController;
use App\Models\AddOn;
use App\Models\Code;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\Fulfiller;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The second thing on the order.
 *
 * One rule holds the whole feature up: an add-on admits nobody. Everything
 * worth testing follows from it — a bottle mints no ticket, does not count as
 * one in a report, and is not what a discount code is about. The rest is
 * ordinary selling: it is priced by the server, its stock cannot be oversold,
 * and it is on the same order and the same ledger as the tickets beside it.
 */
class AddOnTest extends TestCase
{
    use RefreshDatabase;

    private CheckoutService $checkout;

    private Fulfiller $fulfiller;

    private Organization $org;

    private Event $event;

    private TicketType $general;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxRateSeeder::class);

        $this->checkout = app(CheckoutService::class);
        $this->fulfiller = app(Fulfiller::class);

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::factory()->published()->create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'currency' => 'CAD',
            'country' => 'CA',
            'subdivision' => 'ON',
        ]);

        $this->general = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 10000,
            'status' => 'on_sale',
        ]);
    }

    private function addOn(array $attributes = []): AddOn
    {
        return AddOn::create([
            'event_id' => $this->event->id,
            'name' => 'Bottle of Hennessy',
            'price_amount' => 25000,
            'status' => 'on_sale',
            ...$attributes,
        ]);
    }

    private function order(array $addOns = [], int $tickets = 1): Order
    {
        return $this->checkout->reserve(
            event: $this->event,
            quantities: [$this->general->id => $tickets],
            buyerEmail: 'ada@example.test',
            buyerName: 'Ada Buyer',
            addOns: $addOns,
        );
    }

    // --- what it is -----------------------------------------------------------

    public function test_an_add_on_is_on_the_order_and_admits_nobody(): void
    {
        $bottle = $this->addOn();

        $order = $this->order([$bottle->id => 2], tickets: 3);

        $this->assertCount(2, $order->lines, 'One line of tickets, one of bottles.');

        $ticketLine = $order->lines->firstWhere('ticket_type_id', $this->general->id);
        $bottleLine = $order->lines->firstWhere('add_on_id', $bottle->id);

        $this->assertSame('General', $ticketLine->name);
        $this->assertSame('Bottle of Hennessy', $bottleLine->name);
        $this->assertSame(50000, $bottleLine->line_total_amount);
        $this->assertNull($bottleLine->ticket_type_id, 'An add-on line is not a ticket line.');

        $this->fulfiller->fulfil($order);

        // Three tickets, not five. The bottles walk through nothing.
        $this->assertSame(3, Ticket::where('order_id', $order->id)->count());
    }

    public function test_the_order_totals_include_what_was_added(): void
    {
        $bottle = $this->addOn(['price_amount' => 25000]);

        $order = $this->order([$bottle->id => 1], tickets: 1);

        // 10000 ticket + 25000 bottle, then HST on the pair and the service
        // charge on top — an add-on is money like any other money.
        $this->assertSame(35000, $order->subtotal_amount);
        $this->assertSame(4550, $order->tax_amount);
        $this->assertSame(35000, $order->net_revenue_amount);
        $this->assertSame(2800, $order->service_charge_amount);
        $this->assertSame(42350, $order->total_amount);
    }

    public function test_the_ledger_owes_the_organizer_for_the_bottle_too(): void
    {
        $bottle = $this->addOn(['price_amount' => 25000]);

        $order = $this->fulfiller->fulfil($this->order([$bottle->id => 1]));

        $owed = (int) DB::table('ledger_entries')->where('order_id', $order->id)->sum('amount');

        $this->assertSame($order->net_revenue_amount, $owed);
    }

    public function test_a_basket_of_only_add_ons_is_not_an_order(): void
    {
        $bottle = $this->addOn();

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Select at least one ticket.');

        // A bottle on its own is a bar tab, and this is not a bar.
        $this->checkout->reserve(
            event: $this->event,
            quantities: [],
            buyerEmail: 'ada@example.test',
            buyerName: 'Ada Buyer',
            addOns: [$bottle->id => 1],
        );
    }

    public function test_an_add_on_from_another_event_cannot_be_bought_here(): void
    {
        $elsewhere = Event::factory()->published()->create(['organization_id' => $this->org->id]);
        $theirs = AddOn::create([
            'event_id' => $elsewhere->id,
            'name' => 'Their bottle',
            'price_amount' => 100,
            'status' => 'on_sale',
        ]);

        $this->expectException(CheckoutException::class);

        $this->order([$theirs->id => 1]);
    }

    public function test_a_closed_add_on_is_not_sold(): void
    {
        $bottle = $this->addOn(['status' => 'closed']);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Bottle of Hennessy is not currently available.');

        $this->order([$bottle->id => 1]);
    }

    public function test_a_limit_per_order_is_enforced_by_the_server(): void
    {
        $table = $this->addOn(['name' => 'Booth', 'max_per_order' => 1]);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('You can add at most 1 of Booth to one order.');

        $this->order([$table->id => 2]);
    }

    // --- stock ---------------------------------------------------------------

    public function test_stock_cannot_be_oversold(): void
    {
        $table = $this->addOn(['name' => 'Table', 'quantity_available' => 2]);

        $this->fulfiller->fulfil($this->order([$table->id => 2]));

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Table has gone.');

        $this->order([$table->id => 1]);
    }

    public function test_a_basket_in_progress_holds_the_stock(): void
    {
        $table = $this->addOn(['name' => 'Table', 'quantity_available' => 2]);

        // Reserved and not yet paid: the tables are somebody else's for the
        // length of the hold, which is the point of a hold.
        $this->order([$table->id => 2]);

        $this->assertSame(0, $table->remainingNow());

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Table has gone.');

        $this->order([$table->id => 1]);
    }

    public function test_the_refusal_says_how_many_are_left(): void
    {
        $table = $this->addOn(['name' => 'Table', 'quantity_available' => 3]);

        $this->order([$table->id => 2]);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Only 1 of Table left.');

        $this->order([$table->id => 2]);
    }

    public function test_paying_releases_the_hold_rather_than_counting_it_twice(): void
    {
        $table = $this->addOn(['name' => 'Table', 'quantity_available' => 5]);

        $this->fulfiller->fulfil($this->order([$table->id => 2]));

        // Two sold, three left — not one, which is what double-counting the
        // hold against the sale would say.
        $this->assertSame(3, $table->remainingNow());
    }

    public function test_an_unlimited_add_on_never_runs_out(): void
    {
        $pass = $this->addOn(['name' => 'Cloakroom', 'price_amount' => 300, 'quantity_available' => null]);

        $this->fulfiller->fulfil($this->order([$pass->id => 40]));

        $this->assertNull($pass->remainingNow());
    }

    // --- codes ---------------------------------------------------------------

    public function test_a_discount_code_is_about_the_tickets(): void
    {
        $bottle = $this->addOn(['price_amount' => 25000]);

        Code::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'code' => 'HALF',
            'discount_type' => 'percentage',
            'discount_value' => 5000,
            'status' => 'active',
        ]);

        $order = $this->checkout->reserve(
            event: $this->event,
            quantities: [$this->general->id => 1],
            buyerEmail: 'ada@example.test',
            buyerName: 'Ada Buyer',
            codeInput: 'HALF',
            addOns: [$bottle->id => 1],
        );

        // Half the ticket, and not a penny off the bottle: "50% off" is
        // something an organizer says about their night, not about the bar.
        $this->assertSame(5000, $order->discount_amount);
    }

    // --- what the buyer sees --------------------------------------------------

    public function test_the_event_page_offers_what_is_on_sale_and_nothing_else(): void
    {
        $this->addOn(['name' => 'Bottle of Hennessy', 'quantity_available' => 4]);
        $this->addOn(['name' => 'Last year\'s shirt', 'status' => 'closed']);

        $this->getJson('/api/events/afro-fest')
            ->assertOk()
            ->assertJsonCount(1, 'data.add_ons')
            ->assertJsonPath('data.add_ons.0.name', 'Bottle of Hennessy')
            ->assertJsonPath('data.add_ons.0.price.amount', 25000)
            ->assertJsonPath('data.add_ons.0.remaining', 4)
            ->assertJsonPath('data.add_ons.0.sold_out', false);
    }

    public function test_an_event_that_sells_nothing_extra_says_so_rather_than_leaving_it_out(): void
    {
        $this->getJson('/api/events/afro-fest')->assertOk()->assertJsonPath('data.add_ons', []);
    }

    public function test_a_quote_says_which_lines_are_tickets(): void
    {
        $bottle = $this->addOn();

        $body = $this->postJson('/api/events/afro-fest/quote', [
            'items' => [['ticket_type_id' => $this->general->id, 'quantity' => 2]],
            'add_ons' => [['add_on_id' => $bottle->id, 'quantity' => 1]],
        ])->assertOk()->json();

        $this->assertSame(['ticket', 'add_on'], array_column($body['lines'], 'kind'));
        $this->assertSame(45000, $body['subtotal']['amount']);
    }

    public function test_the_ticket_link_shows_what_else_was_bought(): void
    {
        $bottle = $this->addOn();

        $order = $this->fulfiller->fulfil($this->order([$bottle->id => 2]));

        $body = $this->getJson("/api/tickets/{$order->access_token}")->assertOk()->json();

        // An add-on has no code and nothing to scan, so this screen is the
        // only evidence the buyer holds of it.
        $this->assertSame([['name' => 'Bottle of Hennessy', 'quantity' => 2]], $body['extras']);
        $this->assertCount(1, $body['tickets']);
    }

    // --- what the organizer sees ---------------------------------------------

    private function asOwner(): void
    {
        $user = User::factory()->create();

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Owner->value,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($user->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);
    }

    public function test_the_sales_report_counts_bottles_as_money_and_not_as_tickets(): void
    {
        $bottle = $this->addOn(['price_amount' => 25000]);

        $this->fulfiller->fulfil($this->order([$bottle->id => 2], tickets: 3));

        $this->asOwner();

        $report = $this->getJson("/api/organizer/events/{$this->event->id}/sales")->assertOk()->json();

        $this->assertSame(3, $report['days'][0]['tickets'], 'Three tickets sold, not five things.');
        $this->assertSame(80000, $report['days'][0]['revenue'], 'And the bottles are still money.');

        $this->assertSame('Bottle of Hennessy', $report['add_ons'][0]['name']);
        $this->assertSame(2, $report['add_ons'][0]['sold']);
        $this->assertSame(50000, $report['add_ons'][0]['revenue']['amount']);

        // Its own section: an add-on fills no room, so it stays out of the
        // list an organizer reads capacity from.
        $this->assertSame(3, $report['ticket_types'][0]['sold']);
    }

    public function test_an_organizer_adds_one_and_the_page_offers_it(): void
    {
        $this->asOwner();

        $this->postJson("/api/organizer/events/{$this->event->id}/add-ons", [
            'name' => 'Table for six',
            'description' => 'Two bottles included',
            'price_amount' => 90000,
            'quantity_available' => 10,
            'max_per_order' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Table for six')
            ->assertJsonPath('data.price.amount', 90000)
            ->assertJsonPath('data.remaining', 10)
            ->assertJsonPath('data.sold', 0);

        $this->getJson('/api/events/afro-fest')
            ->assertOk()
            ->assertJsonPath('data.add_ons.0.name', 'Table for six');
    }

    public function test_one_that_has_sold_is_closed_rather_than_removed(): void
    {
        $bottle = $this->addOn();
        $this->fulfiller->fulfil($this->order([$bottle->id => 1]));

        $this->asOwner();

        $this->deleteJson("/api/organizer/events/{$this->event->id}/add-ons/{$bottle->id}")
            ->assertOk()
            ->assertJsonPath('status', 'closed');

        // The order line still points at it, and an order has to stay
        // explainable.
        $this->assertNotNull($bottle->fresh());
        $this->assertSame('closed', $bottle->fresh()->status);
    }

    public function test_one_that_has_never_sold_is_simply_removed(): void
    {
        $bottle = $this->addOn();

        $this->asOwner();

        $this->deleteJson("/api/organizer/events/{$this->event->id}/add-ons/{$bottle->id}")->assertOk();

        $this->assertNull(AddOn::find($bottle->id));
    }

    public function test_the_order_they_are_offered_in_is_set_as_one_list(): void
    {
        $first = $this->addOn(['name' => 'First']);
        $second = $this->addOn(['name' => 'Second']);

        $this->asOwner();

        $this->postJson("/api/organizer/events/{$this->event->id}/add-ons/order", [
            'ids' => [$second->id, $first->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Second')
            ->assertJsonPath('data.1.name', 'First');
    }

    public function test_there_is_a_limit_on_how_many_are_offered(): void
    {
        for ($i = 0; $i < AddOnController::MAX_PER_EVENT; $i++) {
            $this->addOn(['name' => 'Extra '.$i]);
        }

        $this->asOwner();

        $this->postJson("/api/organizer/events/{$this->event->id}/add-ons", [
            'name' => 'One more',
            'price_amount' => 100,
        ])->assertStatus(422);
    }

    public function test_an_add_on_on_another_event_is_not_reachable_through_this_one(): void
    {
        $elsewhere = Event::factory()->published()->create(['organization_id' => $this->org->id]);
        $theirs = AddOn::create([
            'event_id' => $elsewhere->id,
            'name' => 'Theirs',
            'price_amount' => 100,
            'status' => 'on_sale',
        ]);

        $this->asOwner();

        $this->patchJson("/api/organizer/events/{$this->event->id}/add-ons/{$theirs->id}", ['name' => 'Mine now'])
            ->assertNotFound();
    }

    public function test_somebody_who_cannot_manage_tickets_cannot_price_a_bottle(): void
    {
        $marketing = User::factory()->create();

        $this->org->members()->attach($marketing->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Marketing->value,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($marketing->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        $this->postJson("/api/organizer/events/{$this->event->id}/add-ons", [
            'name' => 'Anything',
            'price_amount' => 100,
        ])->assertForbidden();
    }

    public function test_no_request_may_name_a_price(): void
    {
        $bottle = $this->addOn(['price_amount' => 25000]);

        $body = $this->postJson('/api/events/afro-fest/quote', [
            'items' => [['ticket_type_id' => $this->general->id, 'quantity' => 1]],
            'add_ons' => [[
                'add_on_id' => $bottle->id,
                'quantity' => 1,
                // Ignored, as everywhere else: there is no rule that reads it
                // and no code path that could.
                'price' => 1,
                'unit_amount' => 1,
            ]],
        ])->assertOk()->json();

        $this->assertSame(35000, $body['subtotal']['amount']);
    }
}
