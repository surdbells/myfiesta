<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Models\Code;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A small, fully known week of trade for the admin's performance screens.
 *
 * The clock is stopped at noon UTC on Sunday 20 September 2026 — eight in the
 * morning in Toronto — so "the last 7 days" in dollars is Monday 14 to Sunday
 * 20 September on Toronto's clock. Every figure the tests assert is worked
 * out by hand in the comments below, so a wrong number in a report is a
 * wrong number, not a changed fixture.
 *
 * Toronto Collective sells in CAD:
 *
 *   order  paid (UTC)        Toronto day  channel  tickets       net    tax  service  total   fee
 *   O1     Sep 15 15:00      Sep 15       online   2 GA        10000   1300      500  11800   400
 *   O2     Sep 18 01:00      Sep 17 (!)   online   1 VIP (x4)   9000   1170      500  10670   350  code EARLY, 1000 off
 *   O3     Sep 20 03:00      Sep 19       door     1 GA         5000      0        0   5000     —  cash, after doors
 *   O4     Sep 16 12:00      Sep 16       online   1 GA         5000    650      250   5900   200  refunded in full Sep 17
 *   O7     Sep 19 12:00      Sep 19       online   1 GA         5000    650      250   5900  null  fee not settled yet
 *   O6     Sep 10 12:00      previous     online   1 GA         5000    650      250   5900   200
 *   O5     pending — never a sale
 *
 * Lagos Nights sells one NGN order on Sep 15 — 1,125,000 kobo — which must
 * never appear in a CAD figure.
 */
trait AnalyticsFixtures
{
    protected CarbonImmutable $now;

    protected Organization $toronto;

    protected Organization $lagos;

    protected Event $night;

    protected Event $upcoming;

    protected Event $lagosNight;

    protected TicketType $ga;

    protected TicketType $vip;

    /** @var array<string, Order> */
    protected array $orders = [];

    protected function stopTheClock(): void
    {
        $this->now = CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC');
        $this->travelTo($this->now);
        Cache::flush();
    }

    protected function staffMember(PlatformRole $role): User
    {
        return User::factory()->create([
            'platform_role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    protected function signIn(User $user): User
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    protected function seedTrade(): void
    {
        $this->stopTheClock();

        $this->toronto = Organization::factory()->create(['name' => 'Toronto Collective', 'created_at' => '2026-01-01 00:00:00', 'verified_at' => '2026-02-01 00:00:00']);
        $this->lagos = Organization::factory()->create(['name' => 'Lagos Nights', 'created_at' => '2026-09-15 09:00:00']);

        // Held at 10pm Toronto on Saturday 19th: 02:00 UTC on the 20th.
        $this->night = Event::factory()->published()->create([
            'organization_id' => $this->toronto->id,
            'title' => 'Harvest Moon Party',
            'starts_at' => '2026-09-20 02:00:00',
            'created_at' => '2026-08-01 00:00:00',
        ]);

        $this->upcoming = Event::factory()->published()->create([
            'organization_id' => $this->toronto->id,
            'title' => 'Winter Warmer',
            'starts_at' => '2026-12-12 02:00:00',
        ]);
        TicketType::factory()->create(['event_id' => $this->upcoming->id, 'name' => 'GA', 'price_amount' => 5000, 'quantity_available' => 50]);

        $this->lagosNight = Event::factory()->published()->inLagos()->create([
            'organization_id' => $this->lagos->id,
            'title' => 'Eko Groove',
            'starts_at' => '2026-10-03 20:00:00',
        ]);
        $naira = TicketType::factory()->create(['event_id' => $this->lagosNight->id, 'name' => 'Regular', 'price_amount' => 250_000, 'quantity_available' => 500]);

        $this->ga = TicketType::factory()->create(['event_id' => $this->night->id, 'name' => 'GA', 'price_amount' => 5000, 'quantity_available' => 100, 'sort_order' => 1]);
        $this->vip = TicketType::factory()->create(['event_id' => $this->night->id, 'name' => 'VIP table', 'price_amount' => 10000, 'admits' => 4, 'quantity_available' => 20, 'sort_order' => 2]);

        $early = Code::create([
            'organization_id' => $this->toronto->id,
            'event_id' => $this->night->id,
            'code' => 'EARLY',
            'discount_type' => 'fixed',
            'discount_value' => 1000,
            'discount_currency' => 'CAD',
        ]);

        $o = &$this->orders;
        $o['O1'] = $this->sale($this->night, $this->ga, 2, '2026-09-15 15:00:00', ['tax' => 1300, 'service' => 500, 'fee' => 400, 'email' => 'ada@example.com']);
        $o['O2'] = $this->sale($this->night, $this->vip, 1, '2026-09-18 01:00:00', ['discount' => 1000, 'tax' => 1170, 'service' => 500, 'fee' => 350, 'email' => 'bola@example.com', 'code_id' => $early->id]);
        $o['O3'] = $this->sale($this->night, $this->ga, 1, '2026-09-20 03:00:00', ['door' => true]);
        $o['O4'] = $this->sale($this->night, $this->ga, 1, '2026-09-16 12:00:00', ['tax' => 650, 'service' => 250, 'fee' => 200, 'email' => 'Ada@Example.com', 'status' => 'refunded']);
        $o['O7'] = $this->sale($this->night, $this->ga, 1, '2026-09-19 12:00:00', ['tax' => 650, 'service' => 250, 'fee' => null, 'email' => 'dayo@example.com']);
        $o['O6'] = $this->sale($this->night, $this->ga, 1, '2026-09-10 12:00:00', ['tax' => 650, 'service' => 250, 'fee' => 200, 'email' => 'chi@example.com']);
        $o['O5'] = $this->sale($this->night, $this->ga, 1, '2026-09-18 12:00:00', ['tax' => 650, 'service' => 250, 'email' => 'eze@example.com', 'status' => 'pending']);
        $o['N1'] = $this->sale($this->lagosNight, $naira, 4, '2026-09-15 12:00:00', ['tax' => 75_000, 'service' => 50_000, 'fee' => 20_000, 'email' => 'tunde@example.com']);
        unset($o);

        DB::table('refunds')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $this->orders['O4']->id,
            'event_id' => $this->night->id,
            'organization_id' => $this->toronto->id,
            'currency' => 'CAD',
            'amount' => 5900,
            'tax_amount' => 650,
            'service_charge_amount' => 250,
            'status' => 'succeeded',
            'reason' => 'requested_by_customer',
            'created_at' => '2026-09-17 12:00:00',
            'updated_at' => '2026-09-17 12:00:00',
        ]);

        // Tickets that still admit somebody: O1's two, O2's table for four,
        // O3's and O7's, and one comp — nine people. Six arrived: one of O1,
        // three of the table, O3, and the comp.
        $this->ticket($this->orders['O1'], $this->ga, 'checked_in', 1, 1);
        $this->ticket($this->orders['O1'], $this->ga, 'valid', 1, 0);
        $this->ticket($this->orders['O2'], $this->vip, 'checked_in', 4, 3);
        $this->ticket($this->orders['O3'], $this->ga, 'checked_in', 1, 1);
        $this->ticket($this->orders['O4'], $this->ga, 'refunded', 1, 0);
        $this->ticket($this->orders['O6'], $this->ga, 'void', 1, 0);
        $this->ticket($this->orders['O7'], $this->ga, 'valid', 1, 0);
        $this->ticket(null, $this->ga, 'checked_in', 1, 1);

        // Scans on Toronto's clock: 22:05, 22:20 (the table, three in), a
        // duplicate at 22:21, 22:40, and 23:10 — slots 22:00 to 23:00.
        foreach ([['02:05', 'accepted', 1], ['02:20', 'accepted', 3], ['02:21', 'duplicate', 0], ['02:40', 'accepted', 1], ['03:10', 'accepted', 1]] as [$at, $result, $admitted]) {
            DB::table('ticket_scans')->insert([
                'id' => (string) Str::uuid(),
                'event_id' => $this->night->id,
                'scanned_code' => 'SCAN-'.Str::random(6),
                'result' => $result,
                'admitted' => $admitted,
                'scanned_at' => '2026-09-20 '.$at.':00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('event_views')->insert([
            ['event_id' => $this->night->id, 'day' => '2026-09-15', 'views' => 80, 'embed_views' => 20],
            ['event_id' => $this->night->id, 'day' => '2026-09-10', 'views' => 50, 'embed_views' => 0],
        ]);

        DB::table('disputes')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $this->orders['O2']->id,
            'organization_id' => $this->toronto->id,
            'event_id' => $this->night->id,
            'gateway' => 'stripe',
            'gateway_reference' => 'dp_'.Str::random(10),
            'amount' => 10670,
            'currency' => 'CAD',
            'reason' => 'fraudulent',
            'status' => 'open',
            'opened_at' => '2026-09-18 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Owed 19,000: 29,000 earned less the 10,000 settled on the 18th.
        foreach ([[29000, 'sale'], [-10000, 'settlement']] as [$amount, $type]) {
            DB::table('ledger_entries')->insert([
                'id' => (string) Str::uuid(),
                'organization_id' => $this->toronto->id,
                'event_id' => $this->night->id,
                'type' => $type,
                'amount' => $amount,
                'currency' => 'CAD',
                'occurred_at' => '2026-09-18 12:00:00',
            ]);
        }

        DB::table('settlements')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->toronto->id,
            'amount' => 10000,
            'currency' => 'CAD',
            'rail' => 'interac',
            'type' => 'partial',
            'status' => 'success',
            'settled_at' => '2026-09-18 12:00:00',
            'created_at' => '2026-09-18 12:00:00',
            'updated_at' => '2026-09-18 12:00:00',
        ]);

        DB::table('payout_requests')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->toronto->id,
            'currency' => 'CAD',
            'amount' => 5000,
            'balance_at_request' => 19000,
            'status' => 'pending',
            'created_at' => '2026-09-19 12:00:00',
            'updated_at' => '2026-09-19 12:00:00',
        ]);
    }

    /** @param array<string, mixed> $o */
    private function sale(Event $event, TicketType $type, int $quantity, string $paidAt, array $o = []): Order
    {
        $subtotal = $type->price_amount * $quantity;
        $discount = $o['discount'] ?? 0;
        $net = $subtotal - $discount;
        $tax = $o['tax'] ?? 0;
        $service = $o['service'] ?? 0;
        $door = $o['door'] ?? false;

        $order = Order::create([
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
            'buyer_email' => $door ? null : ($o['email'] ?? 'buyer@example.com'),
            'buyer_name' => 'A buyer',
            'currency' => $event->currency,
            'subtotal_amount' => $subtotal,
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'net_revenue_amount' => $net,
            'service_charge_amount' => $service,
            'total_amount' => $net + $tax + $service,
            'gateway' => $door ? null : ($event->currency === 'NGN' ? 'paystack' : 'stripe'),
            'gateway_fee_amount' => $door ? null : ($o['fee'] ?? null),
            'channel' => $door ? 'door' : 'online',
            'payment_method' => $door ? 'cash' : null,
            'code_id' => $o['code_id'] ?? null,
            'status' => $o['status'] ?? 'paid',
            'paid_at' => ($o['status'] ?? 'paid') === 'pending' ? null : $paidAt,
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);

        $order->lines()->create([
            'ticket_type_id' => $type->id,
            'name' => $type->name,
            'unit_price_amount' => $type->price_amount,
            'quantity' => $quantity,
            'line_total_amount' => $subtotal,
            'discount_amount' => $discount,
        ]);

        return $order;
    }

    private function ticket(?Order $order, TicketType $type, string $status, int $admits, int $admitted): Ticket
    {
        return Ticket::create([
            'code' => 'T-'.Str::upper(Str::random(12)),
            'event_id' => $type->event_id,
            'ticket_type_id' => $type->id,
            'order_id' => $order?->id,
            'owner_email' => $order === null ? 'guest@example.com' : null,
            'status' => $status,
            'admits' => $admits,
            'admitted_count' => $admitted,
        ]);
    }
}
