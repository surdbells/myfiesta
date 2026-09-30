<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Orders across the whole organization.
 *
 * The questions this pins are the ones support arrives with: a reference read
 * over the phone, a name, an address — and none of them come with the event.
 * Plus the two rules that keep the screen honest: it never crosses into
 * another organization, and it never adds two currencies together.
 */
class OrganizerOrderSearchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->event = $this->event($this->org, 'Afrobeats Rooftop');
    }

    private function signedInAs(Role $role, ?Organization $org = null): User
    {
        $user = User::factory()->create();

        ($org ?? $this->org)->members()->attach($user->id, [
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

    private function event(Organization $org, string $title, string $currency = 'CAD'): Event
    {
        return Event::create([
            'organization_id' => $org->id,
            'slug' => 'e-'.Str::random(8),
            'title' => $title,
            'currency' => $currency,
            'starts_at' => now()->addWeek(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);
    }

    private function order(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada Okafor',
            'currency' => 'CAD',
            'subtotal_amount' => 5000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'net_revenue_amount' => 5000,
            'service_charge_amount' => 400,
            'total_amount' => 5400,
            'gateway' => 'stripe',
            'gateway_reference' => 'pi_'.Str::random(6),
            'status' => 'paid',
            'paid_at' => now(),
        ], $attributes));
    }

    private function orders(array $query = []): array
    {
        return $this->getJson('/api/organizer/orders?'.http_build_query($query))->assertOk()->json();
    }

    /** An order's money that adds up (the table checks it): a total, with a $4 service charge in it. */
    private function priced(int $total): array
    {
        return [
            'subtotal_amount' => $total - 400,
            'net_revenue_amount' => $total - 400,
            'service_charge_amount' => 400,
            'total_amount' => $total,
        ];
    }

    public function test_several_statuses_and_events_at_once(): void
    {
        $this->signedInAs(Role::Owner);
        $other = $this->event($this->org, 'Amapiano Sundays');
        $third = $this->event($this->org, 'Jazz Brunch');

        $this->order(['status' => 'paid']);
        $this->order(['status' => 'refunded']);
        $this->order(['status' => 'pending', 'paid_at' => null]);
        $this->order(['event_id' => $other->id, 'status' => 'paid']);
        $this->order(['event_id' => $third->id, 'status' => 'paid']);

        // As a multi-select sends it, and as somebody types it into a link.
        $this->assertCount(2, $this->orders(['status' => ['refunded', 'pending']])['data']);
        $this->assertCount(2, $this->orders(['status' => 'refunded,pending'])['data']);
        $this->assertCount(4, $this->orders(['event_id' => [$this->event->id, $other->id]])['data']);
        $this->assertCount(1, $this->orders(['event_id' => [$this->event->id, $other->id], 'status' => ['pending']])['data']);
    }

    public function test_a_status_it_does_not_know_is_refused_rather_than_matching_nothing(): void
    {
        $this->signedInAs(Role::Owner);

        $this->getJson('/api/organizer/orders?status[]=paid&status[]=stolen')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->getJson('/api/organizer/orders?event_id[]=not-an-id')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event_id');
    }

    public function test_what_the_buyer_paid_bounds_the_list(): void
    {
        $this->signedInAs(Role::Owner);
        $this->order([...$this->priced(2000)]);
        $this->order([...$this->priced(5400)]);
        $this->order([...$this->priced(60000)]);

        $this->assertSame([5400, 60000], collect($this->orders(['min_total' => 5400, 'sort' => 'total'])['data'])->pluck('total.amount')->all());
        $this->assertSame([2000, 5400], collect($this->orders(['max_total' => 5400, 'sort' => 'total'])['data'])->pluck('total.amount')->all());
        $this->assertSame([2000], collect($this->orders(['min_total' => 0, 'max_total' => 2000])['data'])->pluck('total.amount')->all());

        $this->getJson('/api/organizer/orders?min_total=500&max_total=100')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_total');
    }

    public function test_sorted_by_the_column_asked_for_and_newest_first_otherwise(): void
    {
        $this->signedInAs(Role::Owner);
        $this->order(['buyer_name' => 'bisi', ...$this->priced(3000), 'paid_at' => now()->subDays(2)]);
        $this->order(['buyer_name' => 'Ada', ...$this->priced(9000), 'paid_at' => now()->subDay()]);
        $this->order(['buyer_name' => 'Chidi', ...$this->priced(1000), 'paid_at' => now()]);
        // Still confirming: no payment date, dated by when it was placed.
        $this->order(['buyer_name' => 'Dayo', ...$this->priced(5000), 'paid_at' => null, 'status' => 'pending', 'created_at' => now()->subDays(5)]);

        $names = fn (array $query) => collect($this->orders($query)['data'])->pluck('buyer_name')->all();

        $this->assertSame(['Chidi', 'Ada', 'bisi', 'Dayo'], $names([]));
        $this->assertSame(['Dayo', 'bisi', 'Ada', 'Chidi'], $names(['sort' => 'paid_at', 'dir' => 'asc']));
        $this->assertSame(['Ada', 'Dayo', 'bisi', 'Chidi'], $names(['sort' => 'total', 'dir' => 'desc']));
        // Case does not decide who comes first.
        $this->assertSame(['Ada', 'bisi', 'Chidi', 'Dayo'], $names(['sort' => 'buyer']));

        $this->getJson('/api/organizer/orders?sort=password')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/organizer/orders?sort=total&dir=sideways')->assertUnprocessable()->assertJsonValidationErrors('dir');
    }

    public function test_orders_of_the_same_moment_never_repeat_across_pages(): void
    {
        $this->signedInAs(Role::Owner);
        $moment = now()->startOfMinute();

        foreach (range(1, 7) as $i) {
            $this->order(['paid_at' => $moment, 'created_at' => $moment, ...$this->priced(1000)]);
        }

        $seen = collect([1, 2, 3, 4])
            ->flatMap(fn ($page) => collect($this->orders(['sort' => 'total', 'per_page' => 2, 'page' => $page])['data'])->pluck('id'));

        $this->assertCount(7, $seen);
        $this->assertCount(7, $seen->unique());
    }

    public function test_the_ticked_orders_can_be_exported_alone_in_the_order_shown(): void
    {
        $this->signedInAs(Role::Owner);
        $small = $this->order([...$this->priced(1000), 'reference' => 'SMALL00001']);
        $big = $this->order([...$this->priced(9000), 'reference' => 'BIG0000001']);
        $this->order([...$this->priced(5000), 'reference' => 'NOTTICKED1']);

        $response = $this->get('/api/organizer/orders/export?'.http_build_query([
            'ids' => [$small->id, $big->id],
            'sort' => 'total',
            'dir' => 'desc',
        ]))->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringNotContainsString('NOTTICKED1', $csv);
        $this->assertLessThan(strpos($csv, 'SMALL00001'), strpos($csv, 'BIG0000001'));
    }

    public function test_ticked_orders_of_another_organization_stay_out(): void
    {
        $this->signedInAs(Role::Owner);
        $theirs = Organization::create(['name' => 'Harbour Club', 'slug' => 'harbour-club']);
        $their = $this->order([
            'organization_id' => $theirs->id,
            'event_id' => $this->event($theirs, 'Their night')->id,
            'reference' => 'THEIRS0001',
        ]);

        $this->assertCount(0, $this->orders(['ids' => [$their->id]])['data']);
        $this->assertStringNotContainsString(
            'THEIRS0001',
            $this->get('/api/organizer/orders/export?ids[]='.$their->id)->assertOk()->streamedContent(),
        );
    }

    public function test_a_reference_read_over_the_phone_finds_the_order_in_any_case(): void
    {
        $this->signedInAs(Role::Owner);
        $order = $this->order(['reference' => 'WFY7F77K4E']);
        $this->order(['buyer_name' => 'Somebody Else']);

        // Lower case, because that is how a reference arrives when it is read
        // out rather than pasted.
        $found = $this->orders(['q' => 'wfy7f77'])['data'];

        $this->assertCount(1, $found);
        $this->assertSame($order->reference, $found[0]['reference']);
    }

    public function test_a_name_or_an_address_finds_it_too(): void
    {
        $this->signedInAs(Role::Owner);
        $this->order(['buyer_name' => 'Chidi Okeke', 'buyer_email' => 'chidi@example.com']);
        $this->order(['buyer_name' => 'Ada Okafor', 'buyer_email' => 'ada@example.com']);

        $this->assertCount(1, $this->orders(['q' => 'chidi ok'])['data']);
        $this->assertCount(1, $this->orders(['q' => 'CHIDI@EXAMPLE'])['data']);
    }

    public function test_orders_carry_the_event_they_were_bought_for(): void
    {
        $this->signedInAs(Role::Owner);
        $other = $this->event($this->org, 'Amapiano Sundays');

        $this->order();
        $this->order(['event_id' => $other->id]);

        $titles = collect($this->orders()['data'])->pluck('event.title')->sort()->values()->all();

        // The whole point of the screen: which night, without opening it.
        $this->assertSame(['Afrobeats Rooftop', 'Amapiano Sundays'], $titles);

        $filtered = $this->orders(['event_id' => $other->id])['data'];
        $this->assertCount(1, $filtered);
        $this->assertSame('Amapiano Sundays', $filtered[0]['event']['title']);
    }

    public function test_another_organizations_orders_are_never_in_the_list(): void
    {
        $this->signedInAs(Role::Owner);

        $stranger = Organization::create(['name' => 'Other Co', 'slug' => 'other-co']);
        $theirEvent = $this->event($stranger, 'Not Yours');

        Order::create([
            'organization_id' => $stranger->id,
            'event_id' => $theirEvent->id,
            'reference' => 'STRANGER01',
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada Okafor',
            'currency' => 'CAD',
            'subtotal_amount' => 9900,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'net_revenue_amount' => 9900,
            'service_charge_amount' => 0,
            'total_amount' => 9900,
            'gateway' => 'stripe',
            'gateway_reference' => 'pi_stranger',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->order();

        // Searching the buyer's own name, which both orders share: scope is
        // the organization, never the search term.
        $found = $this->orders(['q' => 'Ada'])['data'];

        $this->assertCount(1, $found);
        $this->assertNotSame('STRANGER01', $found[0]['reference']);
    }

    public function test_abandoned_baskets_are_not_orders_anybody_reads(): void
    {
        $this->signedInAs(Role::Owner);
        $this->order();
        $this->order(['status' => 'cancelled', 'paid_at' => null]);
        $this->order(['status' => 'pending', 'paid_at' => null]);

        $statuses = collect($this->orders()['data'])->pluck('status')->sort()->values()->all();

        // Pending stays: a payment stuck confirming is exactly what somebody
        // rings about. Cancelled goes.
        $this->assertSame(['paid', 'pending'], $statuses);
    }

    public function test_the_page_total_is_withheld_when_the_page_spans_currencies(): void
    {
        $this->signedInAs(Role::Owner);
        $lagos = $this->event($this->org, 'Lagos Night', 'NGN');

        $this->order();
        // The figures have to add up: the database enforces
        // total = net_revenue + tax + service_charge on every row.
        $this->order([
            'event_id' => $lagos->id,
            'currency' => 'NGN',
            'subtotal_amount' => 1_500_000,
            'net_revenue_amount' => 1_500_000,
            'tax_amount' => 0,
            'service_charge_amount' => 120_000,
            'total_amount' => 1_620_000,
        ]);

        // Two sets of money. Adding them makes a number that is true of
        // nothing, so the console is given none.
        $this->assertNull($this->orders()['meta']['summary']);

        $single = $this->orders(['event_id' => $lagos->id])['meta']['summary'];
        $this->assertSame('NGN', $single['gross']['currency']);
        $this->assertSame(1_620_000, $single['gross']['amount']);
    }

    /**
     * The page's takings are money that came in. A payment still confirming
     * is listed — it is what somebody rings about — but it is not takings
     * yet, and it was in "On this page" and "Net" as if it were.
     */
    public function test_the_page_total_counts_only_money_received(): void
    {
        $this->signedInAs(Role::Owner);

        $this->order($this->priced(5_400));
        $this->order($this->priced(10_400) + ['status' => 'refunded']);
        $this->order($this->priced(5_000) + ['status' => 'pending', 'paid_at' => null]);

        $summary = $this->orders()['meta']['summary'];

        $this->assertSame(15_800, $summary['gross']['amount']);
        $this->assertSame(1, $summary['confirming']);

        // Only confirming: nothing received, and it says so.
        $none = $this->orders(['status' => 'pending'])['meta']['summary'];
        $this->assertSame(0, $none['gross']['amount']);
        $this->assertSame('CAD', $none['gross']['currency']);
        $this->assertSame(1, $none['confirming']);
    }

    public function test_marketing_cannot_open_the_list_at_all(): void
    {
        $this->signedInAs(Role::Marketing);
        $this->order();

        // Refused rather than emptied: an order list with the totals stripped
        // out is still a list of names and addresses.
        $this->getJson('/api/organizer/orders')->assertForbidden();
    }

    /**
     * "Today" is the day the organizer is living in, all evening.
     *
     * Nine at night in a Toronto February is already tomorrow in Greenwich.
     * Read as a Greenwich day, the console's "Today" lost everything sold
     * after seven and took in the late sales of last night instead.
     */
    public function test_today_in_toronto_is_torontos_day_all_evening(): void
    {
        $this->signedInAs(Role::Owner);
        $this->travelTo(CarbonImmutable::parse('2026-02-07 21:00', 'America/Toronto')->utc());

        $afternoon = $this->order(['paid_at' => CarbonImmutable::parse('2026-02-07 14:00', 'America/Toronto')->utc()]);
        $tonight = $this->order(['paid_at' => CarbonImmutable::parse('2026-02-07 20:30', 'America/Toronto')->utc()]);
        // Still confirming, so it is dated by when it was placed: just now.
        $confirming = $this->order(['status' => 'pending', 'paid_at' => null]);
        $this->order(['paid_at' => CarbonImmutable::parse('2026-02-06 22:00', 'America/Toronto')->utc()]);

        $today = ['from' => '2026-02-07', 'to' => '2026-02-07', 'timezone' => 'America/Toronto'];
        $expected = collect([$afternoon, $tonight, $confirming])->pluck('reference')->sort()->values()->all();

        $found = collect($this->orders($today)['data'])->pluck('reference')->sort()->values()->all();
        $this->assertSame($expected, $found);

        // The spreadsheet is the same filter, so it holds the same evening.
        $csv = $this->get('/api/organizer/orders/export?'.http_build_query($today))->assertOk()->streamedContent();
        foreach ($expected as $reference) {
            $this->assertStringContainsString($reference, $csv);
        }
        $this->assertSame(count($expected) + 1, count(array_filter(explode("\n", trim($csv)))));
    }

    /** And in Lagos it starts at midnight, not at one in the morning. */
    public function test_today_in_lagos_starts_at_lagos_midnight(): void
    {
        $this->signedInAs(Role::Owner);
        $this->travelTo(CarbonImmutable::parse('2026-02-08 00:30', 'Africa/Lagos')->utc());

        $justNow = $this->order(['paid_at' => CarbonImmutable::parse('2026-02-08 00:10', 'Africa/Lagos')->utc()]);
        $this->order(['paid_at' => CarbonImmutable::parse('2026-02-07 23:50', 'Africa/Lagos')->utc()]);

        $found = $this->orders(['from' => '2026-02-08', 'to' => '2026-02-08', 'timezone' => 'Africa/Lagos'])['data'];

        $this->assertSame([$justNow->reference], collect($found)->pluck('reference')->all());
    }

    /**
     * A range that names no zone is read in Greenwich, as it always was, and
     * a zone nobody has heard of is refused rather than guessed at.
     */
    public function test_a_range_without_a_zone_is_greenwich_days(): void
    {
        $this->signedInAs(Role::Owner);

        // Half past eight at night in Toronto on the 7th; the 8th in Greenwich.
        $late = $this->order(['paid_at' => '2026-02-08T01:30:00Z']);
        $greenwich = ['from' => '2026-02-08', 'to' => '2026-02-08'];

        $this->assertSame([$late->reference], collect($this->orders($greenwich)['data'])->pluck('reference')->all());
        // The old name some browsers still report for it means the same.
        $this->assertSame([$late->reference], collect($this->orders($greenwich + ['timezone' => 'Etc/UTC'])['data'])->pluck('reference')->all());

        $this->getJson('/api/organizer/orders?'.http_build_query($greenwich + ['timezone' => 'Mars/Olympus_Mons']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timezone');
    }
}
