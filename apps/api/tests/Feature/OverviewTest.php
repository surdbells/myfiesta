<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The dashboard's figures.
 *
 * Two things are worth pinning here. The takings are a permission rather than
 * a screen, so the same endpoint has to answer door staff without them. And
 * the attention list only ever contains real conditions — a list that always
 * has something in it is a list nobody reads.
 */
class OverviewTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
    }

    private function signedInAs(Role $role): User
    {
        $user = User::factory()->create();

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($user->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        return $user;
    }

    private function event(array $attributes = []): Event
    {
        return Event::create(array_merge([
            'organization_id' => $this->org->id,
            'slug' => 'e-'.Str::random(8),
            'title' => 'A Night',
            'currency' => 'CAD',
            'starts_at' => now()->addWeek(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ], $attributes));
    }

    private function overview(): array
    {
        return $this->getJson('/api/organizer/overview')->assertOk()->json();
    }

    public function test_an_owner_sees_the_takings(): void
    {
        $this->signedInAs(Role::Owner);
        $this->event();

        $overview = $this->overview();

        $this->assertNotNull($overview['money']);
        $this->assertArrayHasKey('balance', $overview['money']);
        $this->assertSame('CAD', $overview['currency']);
    }

    public function test_door_staff_get_the_same_dashboard_without_the_money(): void
    {
        $this->signedInAs(Role::Door);
        $this->event();

        $overview = $this->overview();

        // Null, not zero, and not a different endpoint. Zero would read as
        // "you are owed nothing", which is a statement about the business
        // rather than about what this person may see.
        $this->assertNull($overview['money']);
        $this->assertSame(1, $overview['selling']['upcoming_events']);
    }

    public function test_a_published_event_with_nothing_on_sale_is_flagged(): void
    {
        $this->signedInAs(Role::Owner);
        $event = $this->event(['title' => 'Nothing To Buy']);

        $overview = $this->overview();

        // The worst state an event can be in: listed, visible, unbuyable.
        $this->assertCount(1, $overview['attention']);
        $this->assertSame($event->id, $overview['attention'][0]['event_id']);
        $this->assertSame('danger', $overview['attention'][0]['severity']);
    }

    public function test_an_event_that_is_actually_selling_is_not_flagged(): void
    {
        $this->signedInAs(Role::Owner);
        $event = $this->event();

        TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
            'quantity_available' => 100,
        ]);

        $this->assertSame([], $this->overview()['attention']);
    }

    public function test_the_next_event_reports_a_capacity_only_when_there_is_one(): void
    {
        $this->signedInAs(Role::Owner);
        $event = $this->event();

        TicketType::create([
            'event_id' => $event->id, 'name' => 'General', 'price_amount' => 5000,
            'status' => 'on_sale', 'quantity_available' => 40,
        ]);

        $this->assertSame(40, $this->overview()['next_event']['capacity']);

        // One unlimited type and the whole capacity is unknowable. A
        // percentage against a total that does not exist is a figure somebody
        // would plan a door around.
        TicketType::create([
            'event_id' => $event->id, 'name' => 'Guest list', 'price_amount' => 0,
            'status' => 'on_sale', 'quantity_available' => null,
        ]);

        $this->assertNull($this->overview()['next_event']['capacity']);
    }

    public function test_an_organization_with_nothing_on_says_so_rather_than_erroring(): void
    {
        $this->signedInAs(Role::Owner);

        $overview = $this->overview();

        $this->assertNull($overview['next_event']);
        $this->assertSame(0, $overview['selling']['upcoming_events']);
        $this->assertSame([], $overview['attention']);
    }

    /** A paid order on a given day, for the series and the pulse. */
    private function paidOrder(Event $event, int $net, \DateTimeInterface $paidAt): Order
    {
        return Order::create([
            'organization_id' => $this->org->id,
            'event_id' => $event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada Okafor',
            'currency' => 'CAD',
            'subtotal_amount' => $net,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'net_revenue_amount' => $net,
            'service_charge_amount' => 0,
            'total_amount' => $net,
            'gateway' => 'stripe',
            'gateway_reference' => 'pi_'.Str::random(6),
            'status' => 'paid',
            'paid_at' => $paidAt,
        ]);
    }

    public function test_the_sales_series_is_a_full_month_with_the_quiet_days_at_zero(): void
    {
        $this->signedInAs(Role::Owner);
        $event = $this->event();

        $this->paidOrder($event, 6000, now());
        $this->paidOrder($event, 4000, now()->subDays(3));

        $series = $this->overview()['sales_by_day'];

        // Zero-filled server-side: a chart with missing days lies about pace.
        $this->assertCount(30, $series);
        $this->assertSame(now()->toDateString(), $series[29]['date']);
        $this->assertSame(6000, $series[29]['net']['amount']);
        $this->assertSame(4000, $series[26]['net']['amount']);
        $this->assertSame(0, $series[25]['net']['amount']);
        $this->assertSame(1, $series[29]['orders']);
    }

    public function test_recent_orders_run_newest_first(): void
    {
        $this->signedInAs(Role::Owner);
        $event = $this->event(['title' => 'A Night']);

        $this->paidOrder($event, 3000, now()->subHours(5));
        $newest = $this->paidOrder($event, 5000, now()->subHour());

        $orders = $this->overview()['recent_orders'];

        $this->assertCount(2, $orders);
        $this->assertSame($newest->reference, $orders[0]['reference']);
        $this->assertSame('Ada Okafor', $orders[0]['buyer_name']);
        $this->assertSame('A Night', $orders[0]['event_title']);
        $this->assertSame(5000, $orders[0]['total']['amount']);
    }

    public function test_door_staff_get_the_progress_list_but_none_of_the_new_money(): void
    {
        $this->signedInAs(Role::Door);
        $event = $this->event();

        TicketType::create([
            'event_id' => $event->id, 'name' => 'General', 'price_amount' => 5000,
            'status' => 'on_sale', 'quantity_available' => 40,
        ]);

        $overview = $this->overview();

        // The series and the pulse are money and gated like it; the progress
        // list is ticket counts, with its takings column withheld instead of
        // the whole list.
        $this->assertNull($overview['sales_by_day']);
        $this->assertNull($overview['recent_orders']);
        $this->assertCount(1, $overview['selling_events']);
        $this->assertSame($event->id, $overview['selling_events'][0]['id']);
        $this->assertSame(40, $overview['selling_events'][0]['capacity']);
        $this->assertNull($overview['selling_events'][0]['net']);
    }

    public function test_the_progress_list_carries_each_events_takings_for_an_owner(): void
    {
        $this->signedInAs(Role::Owner);
        $event = $this->event();

        $this->paidOrder($event, 6000, now());

        $row = $this->overview()['selling_events'][0];

        $this->assertSame(6000, $row['net']['amount']);
        $this->assertSame('CAD', $row['net']['currency']);
    }
}
