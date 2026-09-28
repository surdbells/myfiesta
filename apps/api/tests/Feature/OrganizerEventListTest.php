<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The organizer's list of events, read the way it is used: which nights need
 * looking at today.
 *
 * Pinned: search, statuses and cities narrow it; it sorts by what sold, what
 * it earned and how full the room is (an unlimited room neither full nor
 * empty); each night carries its last fortnight day by day and this week
 * against the last; and the strip above it describes the whole filtered set,
 * never money in two currencies added together or shown to somebody who may
 * not see it.
 */
class OrganizerEventListTest extends TestCase
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
        $this->org->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => $role->value, 'accepted_at' => now()]);
        Sanctum::actingAs($user->fresh()->load('organizations'), [TokenAbility::Organizer->value]);

        return $user;
    }

    private function event(string $title, array $attributes = []): Event
    {
        return Event::create(array_merge([
            'organization_id' => $this->org->id,
            'slug' => Str::slug($title).'-'.Str::random(4),
            'title' => $title,
            'currency' => 'CAD',
            'starts_at' => now()->addWeeks(2),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ], $attributes));
    }

    private function tier(Event $event, ?int $capacity): TicketType
    {
        return TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'quantity_available' => $capacity,
            'status' => 'on_sale',
        ]);
    }

    /** Tickets out, the given number of days ago. */
    private function sold(Event $event, TicketType $tier, int $count, int $daysAgo = 0): void
    {
        foreach (range(1, $count) as $i) {
            $ticket = Ticket::create([
                'event_id' => $event->id,
                'ticket_type_id' => $tier->id,
                'code' => 'WFY7-'.strtoupper(Str::random(8)),
                'owner_email' => 'guest@example.com',
                'status' => 'valid',
                'admits' => 1,
                'admitted_count' => 0,
            ]);
            $ticket->forceFill(['created_at' => now()->subDays($daysAgo)->setTime(12, 0)])->save();
        }
    }

    private function paid(Event $event, int $net, ?string $currency = null, int $daysAgo = 0): void
    {
        Order::create([
            'organization_id' => $this->org->id,
            'event_id' => $event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada',
            'currency' => $currency ?? $event->currency,
            'subtotal_amount' => $net,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'net_revenue_amount' => $net,
            'service_charge_amount' => 0,
            'total_amount' => $net,
            'gateway' => 'stripe',
            'gateway_reference' => 'pi_'.Str::random(6),
            'status' => 'paid',
            'paid_at' => now()->subDays($daysAgo),
        ]);
    }

    private function titles(array $query): array
    {
        return collect($this->getJson('/api/organizer/events?'.http_build_query($query))->assertOk()->json('data'))
            ->pluck('title')->all();
    }

    public function test_search_statuses_cities_and_days_narrow_the_list(): void
    {
        $this->signedInAs(Role::Owner);
        $this->event('Afro Fest');
        $this->event('Jazz Brunch', ['city' => 'Lagos', 'country' => 'NG', 'currency' => 'NGN', 'timezone' => 'Africa/Lagos']);
        $this->event('Draft Night', ['status' => 'draft']);
        $this->event('Far Away', ['starts_at' => now()->addMonths(6)]);

        $this->assertSame(['Afro Fest'], $this->titles(['q' => 'afro']));
        $this->assertSame(['Jazz Brunch'], $this->titles(['q' => 'lagos']));
        $this->assertSame(['Draft Night'], $this->titles(['status' => 'draft']));
        $this->assertSame(['Jazz Brunch'], $this->titles(['city' => ['Lagos']]));
        $this->assertSame(
            ['Far Away'],
            $this->titles(['from' => now()->addMonths(5)->toDateString(), 'timezone' => 'America/Toronto']),
        );

        $this->getJson('/api/organizer/events?status=stolen')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(['Lagos', 'Toronto'], $this->getJson('/api/organizer/events')->json('cities'));
    }

    public function test_it_sorts_by_what_sold_what_it_earned_and_how_full_the_room_is(): void
    {
        $this->signedInAs(Role::Owner);

        $half = $this->event('Half Full');
        $this->sold($half, $this->tier($half, 10), 5);
        $this->paid($half, 20000);

        $full = $this->event('Nearly Full');
        $this->sold($full, $this->tier($full, 4), 4);
        $this->paid($full, 5000);

        $open = $this->event('Open Room');
        $this->sold($open, $this->tier($open, null), 7);

        $this->assertSame(['Open Room', 'Half Full', 'Nearly Full'], $this->titles(['sort' => 'sold', 'dir' => 'desc']));
        $this->assertSame(['Half Full', 'Nearly Full', 'Open Room'], $this->titles(['sort' => 'revenue', 'dir' => 'desc']));
        // An unlimited room is neither full nor empty: last, whichever way.
        $this->assertSame(['Nearly Full', 'Half Full', 'Open Room'], $this->titles(['sort' => 'sell_through', 'dir' => 'desc']));
        $this->assertSame(['Half Full', 'Nearly Full', 'Open Room'], $this->titles(['sort' => 'sell_through', 'dir' => 'asc']));

        $this->getJson('/api/organizer/events?sort=secret')->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_each_night_carries_its_fortnight_and_this_week_against_the_last(): void
    {
        $this->signedInAs(Role::Owner);
        $event = $this->event('Afro Fest');
        $tier = $this->tier($event, 100);

        $this->sold($event, $tier, 2, 10);
        $this->sold($event, $tier, 3, 1);
        $this->sold($event, $tier, 1, 0);
        // Outside the fortnight: counted in the total, not the trend.
        $this->sold($event, $tier, 4, 20);

        $trend = $this->getJson('/api/organizer/events')->assertOk()->json('data.0.trend');

        $this->assertCount(14, $trend['days']);
        $this->assertSame(1, $trend['days'][13]);
        $this->assertSame(3, $trend['days'][12]);
        $this->assertSame(2, $trend['days'][3]);
        $this->assertSame(4, $trend['this_week']);
        $this->assertSame(2, $trend['last_week']);
        $this->assertEquals(1.0, $trend['momentum']);
    }

    public function test_up_from_nothing_is_not_a_percentage(): void
    {
        $this->signedInAs(Role::Owner);
        $event = $this->event('New Night');
        $this->sold($event, $this->tier($event, 100), 3, 0);

        $trend = $this->getJson('/api/organizer/events')->json('data.0.trend');

        $this->assertSame(3, $trend['this_week']);
        $this->assertNull($trend['momentum']);
    }

    public function test_the_strip_describes_the_whole_filtered_set(): void
    {
        $this->signedInAs(Role::Owner);

        $gone = $this->event('Nearly Gone');
        $this->sold($gone, $this->tier($gone, 10), 9);
        $this->paid($gone, 45000, daysAgo: 1);

        $quiet = $this->event('Gone Quiet');
        $this->sold($quiet, $this->tier($quiet, 100), 10, 20);
        $this->paid($quiet, 50000, daysAgo: 20);

        $lagos = $this->event('Lagos Night', ['city' => 'Lagos', 'country' => 'NG', 'currency' => 'NGN', 'timezone' => 'Africa/Lagos']);
        $this->paid($lagos, 1000000);

        $summary = $this->getJson('/api/organizer/events?per_page=1')->assertOk()->json('summary');

        $this->assertSame(3, $summary['events']);
        $this->assertSame(3, $summary['upcoming']);
        $this->assertSame(19, $summary['tickets_issued']);
        $this->assertEquals(round(19 / 110, 3), $summary['sell_through']);
        $this->assertSame(1, $summary['stalled']);
        $this->assertSame(1, $summary['nearly_sold_out']);
        // Two currencies, never added together.
        $this->assertEqualsCanonicalizing(
            [['amount' => 95000, 'currency' => 'CAD'], ['amount' => 1000000, 'currency' => 'NGN']],
            $summary['revenue'],
        );

        $this->assertSame(1, $this->getJson('/api/organizer/events?city[]=Lagos')->json('summary.events'));
    }

    public function test_the_strip_keeps_the_money_from_somebody_who_may_not_see_it(): void
    {
        $this->signedInAs(Role::Door);
        $event = $this->event('Afro Fest');
        $this->paid($event, 5000);

        $body = $this->getJson('/api/organizer/events')->assertOk()->json();

        $this->assertNull($body['summary']['revenue']);
        $this->assertNull($body['data'][0]['revenue']);
    }
}
