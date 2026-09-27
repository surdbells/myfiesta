<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Campaign;
use App\Models\Code;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * How a night is doing, beyond what it sold.
 *
 * Looked, bought, came, from where, and against last time — each number the
 * one an organizer would get by counting by hand, and none of them built from
 * anything about the people who looked.
 */
class InsightsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->event = $this->night('afro-fest', now()->addDays(10));

        $owner = User::factory()->create();
        $this->org->members()->attach($owner->id, ['id' => (string) Str::uuid(), 'role' => Role::Owner->value, 'accepted_at' => now()]);
        Sanctum::actingAs($owner->fresh(), [TokenAbility::Attendee->value, TokenAbility::Organizer->value]);
    }

    private function night(string $slug, $when): Event
    {
        $event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => $slug,
            'title' => Str::headline($slug),
            'currency' => 'CAD',
            'starts_at' => $when,
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);

        TicketType::create(['event_id' => $event->id, 'name' => 'General', 'price_amount' => 5000, 'status' => 'on_sale']);

        return $event;
    }

    /** An order of $tickets tickets, paid at $paidAt, however it arrived. */
    private function sale(Event $event, int $tickets = 1, array $order = [], $paidAt = null, int $admitted = 0): Order
    {
        $placed = Order::create([
            'organization_id' => $this->org->id,
            'event_id' => $event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => 'buyer@example.com',
            'buyer_name' => 'A buyer',
            'currency' => 'CAD',
            'subtotal_amount' => 5000 * $tickets,
            'total_amount' => 5000 * $tickets,
            'net_revenue_amount' => 5000 * $tickets,
            'status' => 'paid',
            'paid_at' => $paidAt ?? now(),
            ...$order,
        ]);

        for ($i = 0; $i < $tickets; $i++) {
            Ticket::create([
                'event_id' => $event->id,
                'ticket_type_id' => $event->ticketTypes()->value('id'),
                'order_id' => $placed->id,
                'owner_email' => 'buyer@example.com',
                'code' => strtoupper(Str::random(12)),
                'status' => $i < $admitted ? 'checked_in' : 'valid',
                'admitted_count' => $i < $admitted ? 1 : 0,
            ]);
        }

        return $placed;
    }

    private function insights(?Event $event = null): array
    {
        return $this->getJson('/api/organizer/events/'.($event ?? $this->event)->id.'/sales')->assertOk()->json('insights');
    }

    // --- looking --------------------------------------------------------------

    public function test_a_page_view_is_a_number_and_nothing_else(): void
    {
        $this->postJson('/api/events/afro-fest/views')->assertNoContent();
        $this->postJson('/api/events/afro-fest/views')->assertNoContent();
        $this->postJson('/api/events/afro-fest/views', ['embed' => true])->assertNoContent();

        $row = DB::table('event_views')->sole();
        $this->assertSame(2, (int) $row->views);
        $this->assertSame(1, (int) $row->embed_views);

        // Nothing about who: the table has no column that could hold it.
        $this->assertEqualsCanonicalizing(['event_id', 'day', 'views', 'embed_views'], array_keys((array) $row));
    }

    public function test_a_draft_or_missing_event_is_not_counted_and_does_not_say_so(): void
    {
        $this->event->update(['status' => 'draft']);

        $this->postJson('/api/events/afro-fest/views')->assertNoContent();
        $this->postJson('/api/events/no-such-night/views')->assertNoContent();

        $this->assertSame(0, DB::table('event_views')->count());
    }

    public function test_conversion_is_online_orders_over_views(): void
    {
        DB::table('event_views')->insert(['event_id' => $this->event->id, 'day' => now()->toDateString(), 'views' => 180, 'embed_views' => 20]);

        $this->sale($this->event, 2);
        $this->sale($this->event, 1);
        // Never saw the page.
        $this->sale($this->event, 1, ['channel' => 'door', 'payment_method' => 'cash', 'buyer_email' => null]);
        // Walked away.
        $this->sale($this->event, 1, ['status' => 'cancelled', 'paid_at' => null]);

        $summary = $this->insights()['summary'];

        $this->assertSame(3, $summary['orders']);
        $this->assertSame(200, $summary['views'] + $summary['embed_views']);
        $this->assertSame(0.01, $summary['conversion']);
    }

    public function test_no_views_is_no_rate_rather_than_zero(): void
    {
        $this->sale($this->event);

        $this->assertNull($this->insights()['summary']['conversion']);
    }

    // --- coming -------------------------------------------------------------------

    public function test_attendance_is_only_a_rate_once_the_doors_have_opened(): void
    {
        $this->sale($this->event, 4);
        $this->assertNull($this->insights()['summary']['attendance']);

        $last = $this->night('last-time', now()->subWeek());
        $this->sale($last, 4, admitted: 3);

        $summary = $this->insights($last)['summary'];
        $this->assertSame(4, $summary['people']);
        $this->assertSame(3, $summary['arrived']);
        $this->assertSame(0.75, $summary['attendance']);
    }

    // --- from where -----------------------------------------------------------------

    public function test_each_order_is_counted_once_under_where_it_came_from(): void
    {
        $campaign = Campaign::create(['organization_id' => $this->org->id, 'audience' => 'followers', 'subject' => 's', 'body' => 'b', 'status' => 'sent']);
        $promoter = Code::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'code' => 'TUNDE',
            'ref_slug' => 'tunde',
            'status' => 'active',
        ]);

        $this->sale($this->event, 2);
        $this->sale($this->event, 1, ['ref_slug' => $campaign->ref]);
        $this->sale($this->event, 3, ['ref_slug' => 'tunde', 'code_id' => $promoter->id]);
        // Through the widget, but a campaign brought them: the email wins.
        $this->sale($this->event, 1, ['ref_slug' => $campaign->ref, 'embedded' => true]);
        $this->sale($this->event, 2, ['embedded' => true]);
        $this->sale($this->event, 1, ['channel' => 'door', 'payment_method' => 'cash', 'buyer_email' => null]);

        $sources = collect($this->insights()['sources'])->keyBy('source');

        $this->assertSame(['direct', 'link', 'campaign', 'embed', 'door'], $sources->keys()->all());
        $this->assertSame(2, $sources['direct']['tickets']);
        $this->assertSame(3, $sources['link']['tickets']);
        $this->assertSame(2, $sources['campaign']['orders']);
        $this->assertSame(2, $sources['embed']['tickets']);
        $this->assertSame(1, $sources['door']['orders']);
        $this->assertSame(10, $sources->sum('tickets'));
    }

    public function test_an_order_placed_inside_the_widget_is_marked(): void
    {
        // Whatever the processor would say, it is not asked: a test that reaches
        // the real Stripe passes or fails on the network, not on this code.
        Http::fake(['*' => Http::response([
            'id' => 'cs_test_insights',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_insights',
        ])]);

        $response = $this->postJson('/api/events/afro-fest/orders', [
            'items' => [['ticket_type_id' => $this->event->ticketTypes()->value('id'), 'quantity' => 1]],
            'buyer' => ['name' => 'Ada', 'email' => 'ada@example.com'],
            'embedded' => true,
            'accept_terms' => true,
        ]);

        // The gateway may not be configured here; the order is made either way.
        $this->assertContains($response->status(), [201, 502, 503]);
        $this->assertTrue((bool) Order::sole()->embedded);
    }

    // --- against last time ------------------------------------------------------

    public function test_this_night_is_set_beside_the_last_one(): void
    {
        $older = $this->night('long-ago', now()->subMonths(3));
        $last = $this->night('last-time', now()->subWeek());
        // Called off: not "last time".
        $this->night('called-off', now()->subDays(3))->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->sale($older, 1);
        $this->sale($last, 5, paidAt: now()->subWeeks(2), admitted: 4);

        $insights = $this->insights();

        $this->assertSame($last->id, $insights['previous']['id']);
        $this->assertSame(5, $insights['previous']['summary']['tickets']);
        $this->assertSame(0.8, $insights['previous']['summary']['attendance']);
    }

    public function test_pace_lines_the_two_nights_up_by_days_before(): void
    {
        $last = $this->night('last-time', now()->subDays(20));
        // A week before the last one, and the day before it.
        $this->sale($last, 3, paidAt: now()->subDays(27));
        $this->sale($last, 2, paidAt: now()->subDays(21));
        // Long before the window: counted from its first day.
        $this->sale($last, 4, paidAt: now()->subDays(100));

        // This one, ten days out (and an hour, so the clock moving during
        // the test cannot tip it to nine): four sold a fortnight early.
        $this->event->update(['starts_at' => now()->addDays(10)->addHour()]);
        $this->sale($this->event, 4, paidAt: now()->subDays(4));

        $pace = $this->insights()['pace'];
        $previous = collect($pace['previous'])->keyBy('days_before');
        $current = collect($pace['this'])->keyBy('days_before');

        $this->assertSame(4, $previous[60]['tickets']);
        $this->assertSame(7, $previous[7]['tickets']);
        $this->assertSame(9, $previous[0]['tickets']);

        // Up to today and no further: ten days out.
        $this->assertSame(10, $current->keys()->min());
        $this->assertSame(4, $current[14]['tickets']);
        $this->assertSame(0, $current[15]['tickets']);
    }
}
