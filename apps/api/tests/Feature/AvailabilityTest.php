<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Code;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\Stock;
use App\Services\Discovery\Availability;
use App\Services\Settings\PlatformSettings;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Almost sold out", "Only 4 left" and "Sold out", and whether they are true.
 *
 * Three things matter here more than the arithmetic. The count is checkout's
 * count, holds and all, so a badge never tells somebody a place is there that
 * checkout will refuse them. The rule is written twice — in PHP for the badge,
 * in SQL for the filters and the front page's shelves — and the two are run
 * over the same nights so they cannot drift. And no public payload carries an
 * organizer's sales: a state, and a number only once it is small.
 */
class AvailabilityTest extends TestCase
{
    use AvailabilityFixtures, RefreshDatabase;

    private function availability(): Availability
    {
        return app(Availability::class);
    }

    /** The PHP answer for the badge and the SQL answer for the filter, which must be the same. */
    private function stateOf(Event $event): string
    {
        $php = $this->availability()->event($event->fresh())->state;

        [$sql, $bindings] = $this->availability()->stateSql();
        $fromSql = Event::query()->whereKey($event->id)->selectRaw("({$sql}) as state", $bindings)->value('state');

        $this->assertSame($php, $fromSql, "The badge says {$php} and the filter says {$fromSql}.");

        return $php;
    }

    /** @return array{state: string, left: int|null} */
    private function tierShown(TicketType $type): array
    {
        return $this->availability()->tier($type->fresh())->toArray($this->availability()->scarcity());
    }

    // --- one tier ------------------------------------------------------------

    public function test_a_tier_with_no_limit_is_unlimited(): void
    {
        $night = $this->night();
        $type = $this->tier($night, null);
        $this->sell($type, 40);

        $this->assertSame(['state' => 'unlimited', 'left' => null], $this->tierShown($type));
        $this->assertSame('unlimited', $this->stateOf($night));
    }

    public function test_plenty_left_says_nothing_about_how_many(): void
    {
        $night = $this->night();
        $type = $this->tier($night, 100);
        $this->sell($type, 89);

        // Eleven left of a hundred is above both the share (10) and the
        // exact-count setting (10): no badge, and no number.
        $this->assertSame(['state' => 'available', 'left' => null], $this->tierShown($type));
        $this->assertSame('available', $this->stateOf($night));
    }

    public function test_ten_percent_left_is_almost_sold_out_and_named(): void
    {
        $night = $this->night();
        $type = $this->tier($night, 100);
        $this->sell($type, 90);

        $this->assertSame(['state' => 'almost_sold_out', 'left' => 10], $this->tierShown($type));
        $this->assertSame('almost_sold_out', $this->stateOf($night));
    }

    public function test_a_small_room_uses_the_floor_rather_than_the_share(): void
    {
        $night = $this->night();
        $type = $this->tier($night, 40);
        $this->sell($type, 35);

        // 10% of 40 is 4; the floor of 5 is larger, so five left is nearly gone.
        $this->assertSame(['state' => 'almost_sold_out', 'left' => 5], $this->tierShown($type));
    }

    public function test_a_small_room_nobody_has_bought_into_is_not_almost_sold_out(): void
    {
        $night = $this->night();
        $type = $this->tier($night, 5);

        // Five places is at the floor, but "almost" is about selling — nothing
        // has. The count is still true to say.
        $this->assertSame(['state' => 'available', 'left' => 5], $this->tierShown($type));
        $this->assertSame('available', $this->stateOf($night));
    }

    public function test_every_place_taken_is_sold_out(): void
    {
        $night = $this->night();
        $type = $this->tier($night, 10);
        $this->sell($type, 10);

        $this->assertSame(['state' => 'sold_out', 'left' => null], $this->tierShown($type));
        $this->assertSame('sold_out', $this->stateOf($night));
    }

    public function test_live_baskets_hold_places_the_way_checkout_counts_them(): void
    {
        $night = $this->night();
        $type = $this->tier($night, 10);
        $this->sell($type, 6);
        $this->hold($type, 4);

        // Four places in somebody's basket are not for sale to anybody else.
        $this->assertSame('sold_out', $this->tierShown($type)['state']);
        $this->assertSame(0, app(Stock::class)->ticketsLeft($type));
    }

    public function test_a_basket_that_ran_out_puts_its_places_back(): void
    {
        $night = $this->night();
        $type = $this->tier($night, 10);
        $this->sell($type, 6);
        $this->hold($type, 4, live: false);

        $this->assertSame(['state' => 'almost_sold_out', 'left' => 4], $this->tierShown($type));
        $this->assertSame(4, app(Stock::class)->ticketsLeft($type));
    }

    public function test_a_ticket_handed_back_puts_its_place_back(): void
    {
        $night = $this->night();
        $type = $this->tier($night, 10);
        $this->sell($type, 9, 'checked_in');
        $this->sell($type, 1, 'listed');

        $this->assertSame(['state' => 'almost_sold_out', 'left' => 1], $this->tierShown($type));
    }

    public function test_marked_sold_out_is_sold_out_whatever_the_count(): void
    {
        $night = $this->night();
        $type = $this->tier($night, null, ['status' => 'sold_out']);

        $this->assertSame('sold_out', $this->tierShown($type)['state']);
        $this->assertSame('sold_out', $this->stateOf($night));
    }

    public function test_the_badge_counts_exactly_what_checkout_counts(): void
    {
        $night = $this->night();
        $type = $this->tier($night, 30);
        $this->sell($type, 12);
        $this->sell($type, 3, 'refunded');
        $this->hold($type, 5);
        $this->hold($type, 7, live: false);

        $stock = app(Stock::class);
        $loaded = $stock->withTaken(TicketType::query()->whereKey($type->id))->first();

        $this->assertSame(17, $stock->ticketsTaken($type->fresh()));
        $this->assertSame(17, $stock->ticketsTaken($loaded), 'Counted in the loading query.');
        $this->assertSame(30 - 17, $stock->ticketsLeft($type));
        $this->assertSame(13, $this->availability()->tier($loaded)->remaining);
    }

    // --- a whole night -------------------------------------------------------

    public function test_the_cheapest_way_in_nearly_gone_makes_the_night_almost_sold_out(): void
    {
        $night = $this->night();
        $early = $this->tier($night, 50, ['name' => 'Early', 'price_amount' => 1500]);
        $this->tier($night, 500, ['name' => 'General', 'price_amount' => 3000]);
        $this->sell($early, 47);

        $this->assertSame('almost_sold_out', $this->stateOf($night));
    }

    public function test_little_left_across_every_tier_is_almost_sold_out(): void
    {
        $night = $this->night();
        $general = $this->tier($night, 100, ['name' => 'General', 'price_amount' => 2000]);
        $vip = $this->tier($night, 100, ['name' => 'VIP', 'price_amount' => 8000]);
        $this->sell($general, 86);
        $this->sell($vip, 95);

        // General, the cheapest, has 14 left: not nearly gone on its own.
        // Across both, 19 of 200 left is under 10% of 200.
        $this->assertSame('available', $this->availability()->tier($general->fresh())->state);
        $this->assertSame('almost_sold_out', $this->stateOf($night));
    }

    public function test_a_sold_out_cheap_tier_hands_the_price_to_the_next_one(): void
    {
        $night = $this->night();
        $early = $this->tier($night, 20, ['name' => 'Early', 'price_amount' => 1500]);
        $this->tier($night, 300, ['name' => 'General', 'price_amount' => 3000]);
        $this->sell($early, 20);

        $this->getJson('/api/events?q=')
            ->assertOk()
            ->assertJsonPath('data.0.from_price.amount', 3000)
            ->assertJsonPath('data.0.availability.state', 'available')
            ->assertJsonPath('data.0.is_sold_out', false);
    }

    public function test_every_tier_shown_gone_is_sold_out(): void
    {
        $night = $this->night();
        $this->sell($this->tier($night, 10), 10);
        // Sold out before its sales ended: still sold out once they have.
        $this->sell($this->tier($night, 20, ['name' => 'Early', 'sales_end_at' => now()->subDay()]), 20);
        $this->tier($night, null, ['name' => 'Door', 'status' => 'sold_out']);
        $this->tier($night, 50, ['name' => 'Presale', 'status' => 'hidden']);
        $this->tier($night, 50, ['name' => 'Closed', 'status' => 'closed']);

        // Every tier a stranger is shown went. A presale nobody without the
        // code can see, and one the organizer closed, are not shown at all.
        $this->assertSame('sold_out', $this->stateOf($night));
    }

    public function test_sales_that_stopped_with_places_left_are_closed_not_sold_out(): void
    {
        // Online sales closed an hour ago, five hours before the doors, with
        // 490 of 500 places unsold.
        $door = $this->night(['slug' => 'door-only', 'starts_at' => now()->addHours(5)]);
        $general = $this->tier($door, 500, ['sales_end_at' => now()->subHour()]);
        $this->sell($general, 10);

        $this->assertSame('closed', $this->stateOf($door));
        $this->assertSame(['state' => 'closed', 'left' => null], $this->tierShown($general));

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonPath('data.0.availability', ['state' => 'closed', 'left' => null])
            ->assertJsonPath('data.0.is_sold_out', false)
            // Nobody is asked to wait for tickets that are not coming back.
            ->assertJsonPath('data.0.waitlist', false);

        $this->getJson('/api/events/door-only')
            ->assertOk()
            ->assertJsonPath('data.availability.state', 'closed')
            ->assertJsonPath('data.ticket_types.0.availability', ['state' => 'closed', 'left' => null])
            ->assertJsonPath('data.ticket_types.0.sold_out', false);

        $this->assertSame([], collect($this->getJson('/api/events?availability=sold_out')->json('data'))->pluck('slug')->all());
        $this->assertSame(['door-only'], collect($this->getJson('/api/events?availability=closed')->json('data'))->pluck('slug')->all());
        $this->assertSame([], collect($this->getJson('/api/events?availability=on_sale')->json('data'))->pluck('slug')->all());
    }

    public function test_one_tier_gone_and_one_ended_with_places_left_is_closed(): void
    {
        $night = $this->night();
        $this->sell($this->tier($night, 50, ['name' => 'Early', 'price_amount' => 1500]), 50);
        $late = $this->tier($night, 500, ['name' => 'General', 'price_amount' => 3000, 'sales_end_at' => now()->subDay()]);
        $this->sell($late, 100);

        // 400 places never sold. "Sold out" would be a claim about all of
        // them; "Sales closed" is true of the night.
        $this->assertSame('closed', $this->stateOf($night));
    }

    public function test_a_tier_waiting_on_the_ladder_keeps_the_night_on_sale(): void
    {
        $night = $this->night();
        $early = $this->tier($night, 10, ['name' => 'Early', 'price_amount' => 1500]);
        $this->tier($night, 100, ['name' => 'General', 'price_amount' => 3000, 'opens_after_id' => $early->id]);
        $this->sell($early, 10);

        $this->assertSame('available', $this->stateOf($night));
    }

    public function test_tickets_not_on_sale_yet_are_not_sold_out(): void
    {
        $night = $this->night();
        $this->tier($night, 100, ['sales_start_at' => now()->addWeek()]);

        $this->assertSame('available', $this->stateOf($night));
    }

    public function test_every_tier_without_a_limit_is_unlimited_and_one_with_a_limit_is_not(): void
    {
        $unlimited = $this->night();
        $this->tier($unlimited, null);
        $this->tier($unlimited, null, ['name' => 'VIP', 'price_amount' => 9000]);

        $mixed = $this->night();
        $this->tier($mixed, null);
        $this->tier($mixed, 100, ['name' => 'VIP', 'price_amount' => 9000]);

        $this->assertSame('unlimited', $this->stateOf($unlimited));
        $this->assertSame('available', $this->stateOf($mixed));
    }

    public function test_a_night_with_no_tiers_shown_is_not_on_sale(): void
    {
        $night = $this->night();
        $this->tier($night, 50, ['name' => 'Presale', 'status' => 'hidden']);

        // Nothing to buy, and nothing sold out: a presale only its code opens.
        $this->assertSame('closed', $this->stateOf($night));
        $this->assertSame('closed', $this->stateOf($this->night()));
    }

    // --- what leaves the API -------------------------------------------------

    public function test_the_event_page_never_carries_the_capacity_or_the_sales(): void
    {
        $night = $this->night(['slug' => 'big-room']);
        $type = $this->tier($night, 500);
        $this->sell($type, 188);

        $response = $this->getJson('/api/events/big-room')->assertOk();

        $response
            ->assertJsonPath('data.ticket_types.0.availability', ['state' => 'available', 'left' => null])
            ->assertJsonPath('data.ticket_types.0.remaining', null)
            ->assertJsonPath('data.ticket_types.0.sold_out', false)
            ->assertJsonPath('data.availability.state', 'available')
            ->assertJsonMissingPath('data.ticket_types.0.quantity_available')
            ->assertJsonMissingPath('data.ticket_types.0.sold');

        // Nowhere in the body, in any field: 312 left, 188 sold, 500 made.
        foreach (['312', '188', '500'] as $count) {
            $this->assertStringNotContainsString(":{$count},", $response->getContent());
        }
    }

    public function test_the_event_page_names_a_small_count(): void
    {
        $night = $this->night(['slug' => 'small-room']);
        $this->sell($this->tier($night, 40), 36);

        $this->getJson('/api/events/small-room')
            ->assertOk()
            ->assertJsonPath('data.ticket_types.0.availability', ['state' => 'almost_sold_out', 'left' => 4])
            ->assertJsonPath('data.ticket_types.0.remaining', 4)
            ->assertJsonPath('data.availability', ['state' => 'almost_sold_out', 'left' => 4]);
    }

    public function test_a_sold_out_night_says_so_and_takes_names(): void
    {
        $night = $this->night(['slug' => 'packed']);
        $this->sell($this->tier($night, 10), 10);

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonPath('data.0.is_sold_out', true)
            ->assertJsonPath('data.0.availability', ['state' => 'sold_out', 'left' => null])
            ->assertJsonPath('data.0.waitlist', true);
    }

    public function test_the_organizer_console_still_sees_its_own_numbers(): void
    {
        $night = $this->night();
        $type = $this->tier($night, 500);
        $this->sell($type, 188);

        $owner = User::factory()->create();
        $night->organization->members()->attach($owner->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Owner->value,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($owner->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        $this->getJson("/api/organizer/events/{$night->id}/ticket-types")
            ->assertOk()
            ->assertJsonPath('data.0.quantity_available', 500)
            ->assertJsonPath('data.0.sold', 188);
    }

    public function test_the_admin_setting_changes_what_is_nearly_gone_and_what_is_named(): void
    {
        $night = $this->night(['slug' => 'tuned']);
        $this->sell($this->tier($night, 100), 80);

        $this->getJson('/api/events/tuned')->assertJsonPath('data.ticket_types.0.availability.state', 'available');

        $admin = User::factory()->create(['platform_role' => PlatformRole::Admin, 'email_verified_at' => now()]);

        app(PlatformSettings::class)->update([
            'almost_sold_out_percent' => 25,
            'only_left_under' => 0,
        ], $admin);

        // 20 left of 100 is now under a quarter — and no number is ever named.
        $this->getJson('/api/events/tuned')
            ->assertJsonPath('data.ticket_types.0.availability', ['state' => 'almost_sold_out', 'left' => null])
            ->assertJsonPath('data.ticket_types.0.remaining', null);
    }

    public function test_the_quote_says_a_tier_sold_out_while_somebody_was_choosing(): void
    {
        $this->seed(TaxRateSeeder::class);

        $night = $this->night(['slug' => 'last-two']);
        $type = $this->tier($night, 10);
        $this->sell($type, 8);

        // The last two go into somebody else's basket.
        $this->hold($type, 2);

        $this->postJson('/api/events/last-two/quote', ['items' => [['ticket_type_id' => $type->id, 'quantity' => 2]]])
            ->assertOk()
            ->assertJsonPath('availability.0.ticket_type_id', $type->id)
            ->assertJsonPath('availability.0.state', 'sold_out')
            ->assertJsonPath('availability.0.left', null);
    }

    public function test_a_refused_quote_still_says_which_tier_went(): void
    {
        $this->seed(TaxRateSeeder::class);

        $night = $this->night(['slug' => 'closed-early']);
        $general = $this->tier($night, 100);
        $early = $this->tier($night, 50, ['name' => 'Early bird', 'price_amount' => 1500]);

        // Closed by the organizer while somebody had it in their basket.
        $early->update(['status' => 'closed']);

        $response = $this->postJson('/api/events/closed-early/quote', ['items' => [
            ['ticket_type_id' => $early->id, 'quantity' => 2],
            ['ticket_type_id' => $general->id, 'quantity' => 1],
        ]])->assertUnprocessable();

        $shown = collect($response->json('availability'))->keyBy('ticket_type_id');
        $this->assertSame(['ticket_type_id' => $early->id, 'state' => 'closed', 'left' => null], $shown[$early->id]);
        $this->assertSame('available', $shown[$general->id]['state']);
    }

    public function test_the_quote_and_the_event_page_both_say_an_ended_tier_closed(): void
    {
        $this->seed(TaxRateSeeder::class);

        $night = $this->night(['slug' => 'ended-tier']);
        $general = $this->tier($night, 100);
        $early = $this->tier($night, 100, ['name' => 'Early', 'price_amount' => 1500, 'sales_end_at' => now()->subDay()]);

        $page = collect($this->getJson('/api/events/ended-tier')->assertOk()->json('data.ticket_types'))->keyBy('id');
        $this->assertSame(['state' => 'closed', 'left' => null], $page[$early->id]['availability']);
        $this->assertFalse($page[$early->id]['sold_out']);

        // Not in the basket, and not sold out: nobody bought a place in it.
        $response = $this->postJson('/api/events/ended-tier/quote', ['items' => [
            ['ticket_type_id' => $general->id, 'quantity' => 1],
        ]])->assertOk();

        $shown = collect($response->json('availability'))->keyBy('ticket_type_id');
        $this->assertSame('closed', $shown[$early->id]['state']);
        $this->assertSame('available', $shown[$general->id]['state']);
    }

    public function test_a_quote_says_nothing_of_a_hidden_tier_to_somebody_without_its_code(): void
    {
        $this->seed(TaxRateSeeder::class);

        $night = $this->night(['slug' => 'with-presale']);
        $general = $this->tier($night, 100);
        $presale = $this->tier($night, 20, ['name' => 'Presale', 'price_amount' => 1500, 'status' => 'hidden']);
        $this->sell($presale, 17);

        $basket = ['items' => [['ticket_type_id' => $presale->id, 'quantity' => 1]]];

        // Refused exactly as an id that does not exist is, and the answer
        // says nothing of the tier: not that it is there, not how it sells.
        $refused = $this->postJson('/api/events/with-presale/quote', $basket)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'That ticket is not on sale for this event.');

        $this->assertSame([$general->id], collect($refused->json('availability'))->pluck('ticket_type_id')->all());
        $this->assertStringNotContainsString($presale->id, $refused->getContent());

        // A code that opens something else does not open this.
        $other = $this->tier($night, 20, ['name' => 'Other list', 'status' => 'hidden']);
        Code::create([
            'organization_id' => $night->organization_id,
            'event_id' => $night->id,
            'code' => 'OTHERLIST',
            'unlocks_tickets' => true,
            'is_active' => true,
        ])->unlocks()->sync([$other->id]);

        $wrongCode = $this->postJson('/api/events/with-presale/quote', [...$basket, 'access_code' => 'OTHERLIST'])
            ->assertUnprocessable();
        $this->assertStringNotContainsString($presale->id, $wrongCode->getContent());

        // With its code, the buyer holding it is told how it stands.
        Code::create([
            'organization_id' => $night->organization_id,
            'event_id' => $night->id,
            'code' => 'ONTHELIST',
            'unlocks_tickets' => true,
            'is_active' => true,
        ])->unlocks()->sync([$presale->id]);

        $opened = $this->postJson('/api/events/with-presale/quote', [...$basket, 'access_code' => 'ONTHELIST'])->assertOk();
        $shown = collect($opened->json('availability'))->keyBy('ticket_type_id');

        $this->assertSame(['ticket_type_id' => $presale->id, 'state' => 'almost_sold_out', 'left' => 3], $shown[$presale->id]);
    }

    public function test_a_page_of_cards_costs_the_same_queries_however_many_cards(): void
    {
        $add = function (int $nights): void {
            foreach (range(1, $nights) as $n) {
                $night = $this->night(['title' => "Night {$n}"]);
                $this->sell($this->tier($night, 50), 3);
                $this->tier($night, null, ['name' => 'VIP', 'price_amount' => 9000]);
            }
        };

        $queriesFor = function (int $cards): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->getJson('/api/events')->assertOk()->assertJsonCount($cards, 'data');

            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $add(2);
        // Once first, so the settings are cached for both of the counts below.
        $queriesFor(2);
        $few = $queriesFor(2);

        $add(6);
        $many = $queriesFor(8);

        $this->assertSame($few, $many, 'Each card asked the database again: a page of forty would be forty more queries.');
    }
}
