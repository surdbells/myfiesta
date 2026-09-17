<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Code;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The sales breakdown: by day, by ticket type, by code.
 */
class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $early;

    private TicketType $table;

    protected function setUp(): void
    {
        parent::setUp();

        // 1pm Lagos on 10 September.
        $this->travelTo(Carbon::parse('2026-09-10 12:00:00', 'UTC'));

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'NGN',
            'starts_at' => now()->addDays(10),
            'timezone' => 'Africa/Lagos',
            'city' => 'Lagos',
            'country' => 'NG',
            'status' => 'published',
        ]);

        $this->early = TicketType::create(['event_id' => $this->event->id, 'name' => 'Early Bird', 'price_amount' => 5000, 'quantity_available' => 100, 'status' => 'on_sale', 'sort_order' => 1]);
        $this->table = TicketType::create(['event_id' => $this->event->id, 'name' => 'Table of 5', 'price_amount' => 40000, 'status' => 'on_sale', 'sort_order' => 2, 'admits' => 5]);
    }

    private function actAs(Role $role): void
    {
        $user = User::factory()->create();
        $this->org->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => $role->value, 'accepted_at' => now()]);
        Sanctum::actingAs($user->fresh()->load('organizations'), [TokenAbility::Organizer->value]);
    }

    /**
     * @param  array<int, array{0: TicketType, 1: int, 2?: int}>  $lines  type, quantity, discount
     */
    private function sell(array $lines, Carbon $paidAt, string $status = 'paid', ?Code $code = null, ?string $refSlug = null): Order
    {
        $subtotal = array_sum(array_map(fn ($l) => $l[0]->price_amount * $l[1], $lines));
        $discount = array_sum(array_map(fn ($l) => $l[2] ?? 0, $lines));

        $order = Order::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => 'buyer@example.com',
            'buyer_name' => 'Buyer',
            'currency' => 'NGN',
            'subtotal_amount' => $subtotal,
            'discount_amount' => $discount,
            'tax_amount' => 0,
            'net_revenue_amount' => $subtotal - $discount,
            'service_charge_amount' => 0,
            'total_amount' => $subtotal - $discount,
            'gateway' => 'paystack',
            'gateway_reference' => 'ref_'.Str::random(6),
            'status' => $status,
            'paid_at' => $status === 'pending' ? null : $paidAt,
            'code_id' => $code?->id,
            'ref_slug' => $refSlug,
        ]);

        foreach ($lines as $line) {
            [$type, $quantity] = $line;

            OrderLine::create([
                'order_id' => $order->id,
                'ticket_type_id' => $type->id,
                'name' => $type->name,
                'unit_price_amount' => $type->price_amount,
                'quantity' => $quantity,
                'line_total_amount' => $type->price_amount * $quantity,
                'discount_amount' => $line[2] ?? 0,
            ]);

            if ($status !== 'pending') {
                for ($i = 0; $i < $quantity; $i++) {
                    $this->ticket($type, $order);
                }
            }
        }

        return $order;
    }

    private function ticket(TicketType $type, ?Order $order, string $status = 'valid', int $admitted = 0): Ticket
    {
        return Ticket::create([
            'event_id' => $this->event->id,
            'ticket_type_id' => $type->id,
            'order_id' => $order?->id,
            'code' => strtoupper(Str::random(12)),
            'owner_email' => 'buyer@example.com',
            'status' => $status,
            'admits' => $type->admits ?? 1,
            'admitted_count' => $admitted,
        ]);
    }

    private function report(): array
    {
        return $this->getJson("/api/organizer/events/{$this->event->id}/sales")->assertOk()->json();
    }

    public function test_sales_by_ticket_type_separate_sold_from_complimentary(): void
    {
        $this->actAs(Role::Manager);

        $this->sell([[$this->early, 3, 1000]], now());
        $this->sell([[$this->table, 1]], now());
        // A comp takes a place without being a sale.
        $this->ticket($this->early, null);
        // Refunded tickets no longer take a place.
        $refunded = $this->sell([[$this->early, 1]], now(), 'partially_refunded');
        $refunded->tickets()->update(['status' => 'refunded']);
        // A checkout nobody finished is not a sale.
        $this->sell([[$this->early, 5]], now(), 'pending');
        // Two of the table have arrived.
        Ticket::where('ticket_type_id', $this->table->id)->update(['admitted_count' => 2]);

        [$early, $table] = $this->report()['ticket_types'];

        $this->assertSame('Early Bird', $early['name']);
        $this->assertSame(100, $early['capacity']);
        $this->assertSame(3, $early['sold']);
        $this->assertSame(1, $early['comps']);
        // Sold for: 3 × 5000 less 1000 discount, plus the partly refunded order's 5000 at the time.
        $this->assertSame(19000, $early['revenue']['amount']);

        $this->assertNull($table['capacity']);
        $this->assertSame(1, $table['sold']);
        $this->assertSame(5, $table['people']);
        $this->assertSame(2, $table['arrived']);
        $this->assertSame(40000, $table['revenue']['amount']);
    }

    public function test_sales_by_day_follow_the_event_time_zone_and_keep_quiet_days(): void
    {
        $this->actAs(Role::Owner);

        // 23:30 UTC on the 6th is 00:30 on the 7th in Lagos.
        $this->sell([[$this->early, 2]], Carbon::parse('2026-09-06 23:30:00', 'UTC'));
        $this->sell([[$this->early, 1]], Carbon::parse('2026-09-09 10:00:00', 'UTC'));
        $this->sell([[$this->early, 4]], Carbon::parse('2026-09-09 15:00:00', 'UTC'));

        $days = collect($this->report()['days'])->keyBy('date');

        // From the first sale to today, with nothing missing.
        $this->assertSame(['2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10'], $days->keys()->all());
        $this->assertSame(['date' => '2026-09-07', 'orders' => 1, 'tickets' => 2, 'revenue' => 10000], $days['2026-09-07']);
        $this->assertSame(0, $days['2026-09-08']['tickets']);
        $this->assertSame(2, $days['2026-09-09']['orders']);
        $this->assertSame(5, $days['2026-09-09']['tickets']);
    }

    public function test_sales_by_code_include_promoter_links_and_deleted_codes(): void
    {
        $this->actAs(Role::Manager);

        $promo = Code::create(['organization_id' => $this->org->id, 'event_id' => $this->event->id, 'code' => 'EARLY10', 'discount_type' => 'fixed', 'discount_value' => 500, 'discount_currency' => 'NGN', 'label' => 'Instagram']);
        $ade = Code::create(['organization_id' => $this->org->id, 'event_id' => $this->event->id, 'code' => 'ADE', 'ref_slug' => 'ade', 'promoter_name' => 'Ade']);

        $this->sell([[$this->early, 2, 1000]], now(), 'paid', $promo);
        $this->sell([[$this->early, 1, 500]], now(), 'paid', $promo);
        $this->sell([[$this->table, 1]], now(), 'paid', $ade, 'ade');
        $this->sell([[$this->early, 1]], now());
        $promo->delete();

        $codes = collect($this->report()['codes'])->keyBy('code');

        $this->assertCount(2, $codes);
        $this->assertSame(2, $codes['EARLY10']['orders']);
        $this->assertSame(3, $codes['EARLY10']['tickets']);
        $this->assertSame(1500, $codes['EARLY10']['discount']['amount']);
        $this->assertSame(13500, $codes['EARLY10']['revenue']['amount']);
        $this->assertTrue($codes['EARLY10']['deleted']);
        $this->assertSame('Ade', $codes['ADE']['promoter']);
        $this->assertSame('ade', $codes['ADE']['ref_slug']);
        $this->assertSame(40000, $codes['ADE']['revenue']['amount']);
    }

    public function test_nothing_sold_is_an_empty_report_not_an_error(): void
    {
        $this->actAs(Role::Finance);

        $report = $this->report();

        $this->assertSame([], $report['days']);
        $this->assertSame([], $report['codes']);
        $this->assertSame(0, $report['ticket_types'][0]['sold']);
    }

    public function test_only_people_who_see_money_see_the_report(): void
    {
        foreach ([Role::Marketing, Role::Door] as $role) {
            $this->actAs($role);
            $this->getJson("/api/organizer/events/{$this->event->id}/sales")->assertForbidden();
        }

        $rival = Organization::create(['name' => 'Rival', 'slug' => 'rival']);
        $stranger = User::factory()->create();
        $rival->members()->attach($stranger->id, ['id' => (string) Str::uuid(), 'role' => 'owner', 'accepted_at' => now()]);
        Sanctum::actingAs($stranger->fresh()->load('organizations'), [TokenAbility::Organizer->value]);

        $this->getJson("/api/organizer/events/{$this->event->id}/sales")->assertForbidden();
    }
}
