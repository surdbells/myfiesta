<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Door\DoorPasses;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Walk-ups.
 *
 * Two things are being protected. That a door sale is an ordinary order —
 * taking real stock, minting real tickets, landing in the real reports —
 * because the alternative is the comp it replaces, which recorded no money at
 * all. And that the money stays where it actually is: the organizer took it
 * into their own tin, so the platform charges nothing on it and does not owe
 * it back.
 */
class DoorSaleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $general;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxRateSeeder::class);

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
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);

        $this->owner = $this->member(Role::Owner);
        $this->actAsOrganizer($this->owner);
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

    private function actAsOrganizer(User $user): void
    {
        Sanctum::actingAs($user, [TokenAbility::Attendee->value, TokenAbility::Organizer->value]);
    }

    /**
     * Stop acting as the organizer from setUp.
     *
     * Sanctum::actingAs resolves every request to that user whatever bearer
     * token is sent, so a test about a door phone has to put the guard back
     * before the token means anything.
     */
    private function forgetSession(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function sell(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/events/{$this->event->id}/door-sales", [
            'items' => [['ticket_type_id' => $this->general->id, 'quantity' => 1]],
            'method' => 'cash',
            ...$body,
        ]);
    }

    // --- an ordinary order ----------------------------------------------------

    public function test_a_sale_at_the_door_mints_a_ticket_and_says_what_to_scan(): void
    {
        $response = $this->sell()->assertCreated();

        $order = Order::sole();

        $this->assertSame('paid', $order->status);
        $this->assertSame('door', $order->channel);
        $this->assertSame('cash', $order->payment_method);
        $this->assertSame($this->owner->id, $order->sold_by_user_id);

        // The code comes back because the phone that sold it is usually the
        // phone that scans it straight back in.
        $response->assertJsonPath('reference', $order->reference);
        $this->assertSame(1, Ticket::where('order_id', $order->id)->count());
        $this->assertSame(Ticket::sole()->code, $response->json('tickets.0.code'));
    }

    public function test_it_takes_the_same_stock_as_a_buyer_online(): void
    {
        $this->general->update(['quantity_available' => 2]);

        $this->sell(['items' => [['ticket_type_id' => $this->general->id, 'quantity' => 2]]])->assertCreated();

        // A door that could oversell is a door with people outside holding
        // tickets for a room that is full.
        $this->sell()
            ->assertStatus(422)
            ->assertJsonPath('message', 'General has sold out.');
    }

    public function test_the_ticket_it_mints_opens_the_door(): void
    {
        $this->sell()->assertCreated();

        $this->postJson("/api/events/{$this->event->id}/scan", ['code' => Ticket::sole()->code])
            ->assertOk()
            ->assertJsonPath('accepted', true);
    }

    public function test_a_walk_up_needs_to_give_nothing(): void
    {
        $this->sell()->assertCreated()->assertJsonPath('emailed', false);

        $order = Order::sole();

        // Nobody spelled out an address at a door with a queue behind them.
        $this->assertNull($order->buyer_email);
        $this->assertNull(Ticket::sole()->owner_email);
        $this->assertNull(Ticket::sole()->owner_user_id);
    }

    public function test_an_address_given_at_the_door_gets_the_ticket_by_email(): void
    {
        $this->sell(['name' => 'Ada Okoro', 'email' => 'ada@example.test'])
            ->assertCreated()
            ->assertJsonPath('emailed', true);

        $order = Order::sole();

        $this->assertSame('ada@example.test', $order->buyer_email);
        $this->assertSame('Ada Okoro', $order->buyer_name);
        $this->assertSame('ada@example.test', Ticket::sole()->owner_email);
    }

    // --- the money ------------------------------------------------------------

    public function test_the_platform_charges_nothing_on_money_it_never_touched(): void
    {
        $this->sell()->assertCreated();

        $order = Order::sole();

        // 5000 plus HST, and no service charge: there was no checkout to
        // charge for, and invoicing an organizer for cash we cannot see is a
        // worse business than not charging.
        $this->assertSame(0, $order->service_charge_amount);
        $this->assertSame(650, $order->tax_amount);
        $this->assertSame(5000, $order->net_revenue_amount);
        $this->assertSame(5650, $order->total_amount);
        $this->assertNull($order->gateway);
    }

    public function test_the_night_still_reads_as_the_night_but_we_owe_nothing(): void
    {
        $this->sell()->assertCreated();

        $order = Order::sole();
        $entries = LedgerEntry::where('order_id', $order->id)->get();

        // The sale and its tax are recorded as for any order, so the gross is
        // the gross...
        $this->assertSame(5650, (int) $entries->firstWhere('type', 'sale')->amount);
        $this->assertSame(-650, (int) $entries->firstWhere('type', 'tax')->amount);

        // ...and then the organizer's share is taken back out, because they
        // are holding it already.
        $this->assertSame(-5000, (int) $entries->firstWhere('type', 'collected')->amount);
        $this->assertStringContainsString('cash', $entries->firstWhere('type', 'collected')->reason);

        $this->assertSame(0, (int) $entries->sum('amount'), 'A door sale owes the organizer nothing.');
    }

    public function test_an_online_sale_is_still_owed_in_full(): void
    {
        // The other side of the same rule, so the door case cannot quietly
        // start applying to everything.
        $order = app(\App\Services\Checkout\CheckoutService::class)->reserve(
            event: $this->event,
            quantities: [$this->general->id => 1],
            buyerEmail: 'ada@example.test',
            buyerName: 'Ada',
        );

        app(\App\Services\Checkout\Fulfiller::class)->fulfil($order);

        $this->assertSame(5000, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));
        $this->assertSame(0, LedgerEntry::where('order_id', $order->id)->where('type', 'collected')->count());
    }

    public function test_the_sale_is_recorded_against_whoever_took_it(): void
    {
        $this->sell(['method' => 'transfer'])->assertCreated();

        $entry = DB::table('audit_logs')->where('action', 'door.sold')->first();

        // Money handled in a doorway, with a name against it. That is the
        // control; the rest is bookkeeping.
        $this->assertNotNull($entry);
        $this->assertSame($this->owner->id, $entry->actor_id);
        $this->assertStringContainsString('transfer', $entry->metadata);
    }

    public function test_a_method_nobody_pays_with_is_refused(): void
    {
        $this->sell(['method' => 'crypto'])->assertStatus(422);
    }

    // --- the till -------------------------------------------------------------

    public function test_the_takings_add_up_the_way_somebody_counts_them(): void
    {
        $this->sell(['items' => [['ticket_type_id' => $this->general->id, 'quantity' => 2]], 'method' => 'cash']);
        $this->sell(['method' => 'cash']);
        $this->sell(['method' => 'card']);

        $takings = $this->getJson("/api/events/{$this->event->id}/takings")->assertOk()->json();

        $this->assertSame(4, $takings['tickets']);
        // 3 cash tickets + 1 card, at 5650 each.
        $this->assertSame(22600, $takings['total']['amount']);

        $cash = collect($takings['by_method'])->firstWhere('method', 'cash');
        $card = collect($takings['by_method'])->firstWhere('method', 'card');

        $this->assertSame(16950, $cash['total']['amount'], 'What should be in the tin.');
        $this->assertSame(5650, $card['total']['amount'], 'What the terminal should say.');

        // A method nobody used is not a row of zeroes to read at 3am.
        $this->assertNull(collect($takings['by_method'])->firstWhere('method', 'transfer'));
    }

    public function test_the_takings_are_split_by_till(): void
    {
        $this->sell()->assertCreated();

        $tills = $this->getJson("/api/events/{$this->event->id}/takings")->assertOk()->json('by_till');

        // Sold from the organizer's own session rather than a door phone, so
        // the till is named after them.
        $this->assertCount(1, $tills);
        $this->assertSame(5650, $tills[0]['total']['amount']);
    }

    public function test_an_online_order_is_not_in_the_takings(): void
    {
        app(\App\Services\Checkout\Fulfiller::class)->fulfil(
            app(\App\Services\Checkout\CheckoutService::class)->reserve(
                event: $this->event,
                quantities: [$this->general->id => 1],
                buyerEmail: 'ada@example.test',
                buyerName: 'Ada',
            )
        );

        $takings = $this->getJson("/api/events/{$this->event->id}/takings")->assertOk()->json();

        // Nobody is counting that in a tin.
        $this->assertSame(0, $takings['tickets']);
        $this->assertSame(0, $takings['total']['amount']);
    }

    // --- who may sell ---------------------------------------------------------

    public function test_the_door_phone_can_sell_and_its_sale_names_the_till(): void
    {
        $passes = app(DoorPasses::class);
        ['pass' => $pass, 'secret' => $secret] = $passes->issue($this->event, $this->owner, 'Front door');

        ['token' => $token] = $passes->claim($secret);

        // The bearer token has to be what identifies the request, or the pass
        // behind it cannot be recognised — and it is the pass that names the
        // till.
        $this->forgetSession();

        $sold = $this->withToken($token)->postJson("/api/events/{$this->event->id}/door-sales", [
            'items' => [['ticket_type_id' => $this->general->id, 'quantity' => 1]],
            'method' => 'cash',
        ])->assertCreated();

        $this->assertSame($pass->id, Order::sole()->door_pass_id);

        $tills = $this->withToken($token)
            ->getJson("/api/events/{$this->event->id}/takings")
            ->assertOk()
            ->json('by_till');

        $this->assertSame('Front door', $tills[0]['label']);
        $this->assertSame($sold->json('reference'), Order::sole()->reference);
    }

    public function test_a_door_phone_cannot_sell_for_somebody_elses_event(): void
    {
        $elsewhere = Organization::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $theirs = Event::factory()->published()->create(['organization_id' => $elsewhere->id]);
        $theirType = TicketType::create([
            'event_id' => $theirs->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);

        $passes = app(DoorPasses::class);
        ['secret' => $secret] = $passes->issue($this->event, $this->owner, 'Front door');
        ['token' => $token] = $passes->claim($secret);

        $this->forgetSession();

        // A pass is bound to one event, and is only ever as good as the
        // member who issued it. Neither of those reaches another
        // organization's door.
        $this->withToken($token)->postJson("/api/events/{$theirs->id}/door-sales", [
            'items' => [['ticket_type_id' => $theirType->id, 'quantity' => 1]],
            'method' => 'cash',
        ])->assertForbidden();
    }

    public function test_marketing_cannot_take_money_at_a_door(): void
    {
        $this->actAsOrganizer($this->member(Role::Marketing));

        // The same authority as scanning, which marketing does not have
        // either.
        $this->sell()->assertForbidden();
    }

    public function test_another_organizations_door_is_not_reachable(): void
    {
        $stranger = User::factory()->create();
        $elsewhere = Organization::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $elsewhere->members()->attach($stranger->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Owner->value,
            'accepted_at' => now(),
        ]);

        $this->actAsOrganizer($stranger->fresh()->load('organizations'));

        $this->sell()->assertForbidden();
    }

    // --- what the door is offered ---------------------------------------------

    public function test_the_door_is_told_what_is_left_rather_than_what_was_printed(): void
    {
        $this->general->update(['quantity_available' => 10]);
        $this->sell(['items' => [['ticket_type_id' => $this->general->id, 'quantity' => 3]]])->assertCreated();

        $this->getJson("/api/events/{$this->event->id}/sellable")
            ->assertOk()
            ->assertJsonPath('ticket_types.0.name', 'General')
            ->assertJsonPath('ticket_types.0.price.amount', 5000)
            ->assertJsonPath('ticket_types.0.remaining', 7)
            ->assertJsonPath('methods', ['cash', 'card', 'transfer']);
    }
}
