<?php

namespace Tests\Feature;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The front page's shelves, the listing's filters, and the pages each
 * category and city has.
 *
 * The clock is fixed at 8pm UTC on Friday 2 October 2026: 4pm in Toronto,
 * 9pm in Lagos. Most of what can go wrong here is a night filed under the
 * wrong day because somebody's zone was used instead of the event's.
 */
class DiscoverSectionsTest extends TestCase
{
    use AvailabilityFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-02 20:00:00', 'UTC'));

        // Every request here is asked fresh. Caching has its own test.
        config(['discovery.cache_seconds' => 0]);
    }

    /** @return list<string> */
    private function titles(string $url, string $path = 'data'): array
    {
        return collect($this->getJson($url)->assertOk()->json($path))->pluck('title')->all();
    }

    private function at(string $utc, array $overrides = []): Event
    {
        $night = $this->night(['starts_at' => CarbonImmutable::parse($utc, 'UTC'), ...$overrides]);
        $this->tier($night, null);

        return $night;
    }

    // --- today and this weekend, where the event is --------------------------

    public function test_today_is_the_events_own_today(): void
    {
        // 10pm tonight in Toronto — Saturday already in UTC.
        $this->at('2026-10-03 02:00:00', ['title' => 'Tonight in Toronto']);
        // Half past midnight in Lagos: tomorrow there, though today in UTC.
        $this->at('2026-10-02 23:30:00', ['title' => 'After midnight in Lagos', 'timezone' => 'Africa/Lagos', 'city' => 'Lagos', 'country' => 'NG', 'currency' => 'NGN']);
        // Opened at noon, on until eleven: still on, so still today.
        $this->at('2026-10-02 16:00:00', ['title' => 'Day festival', 'ends_at' => CarbonImmutable::parse('2026-10-03 03:00:00', 'UTC')]);
        // Over already.
        $this->at('2026-10-02 12:00:00', ['title' => 'Brunch', 'ends_at' => CarbonImmutable::parse('2026-10-02 15:00:00', 'UTC')]);

        $today = collect($this->getJson('/api/discover')->assertOk()->json('today'))->pluck('title')->all();

        $this->assertSame(['Day festival', 'Tonight in Toronto'], $today);
        $this->assertSame(['Day festival', 'Tonight in Toronto'], $this->titles('/api/events?when=today'));
    }

    public function test_this_weekend_is_friday_to_sunday_where_the_event_is(): void
    {
        $this->at('2026-10-03 01:00:00', ['title' => 'Friday night']);
        $this->at('2026-10-03 20:00:00', ['title' => 'Saturday']);
        $this->at('2026-10-04 22:00:00', ['title' => 'Sunday night']);
        // Monday in Lagos at 12:30am, though still Sunday in UTC.
        $this->at('2026-10-04 23:30:00', ['title' => 'Monday in Lagos', 'timezone' => 'Africa/Lagos', 'city' => 'Lagos', 'country' => 'NG', 'currency' => 'NGN']);
        $this->at('2026-10-10 20:00:00', ['title' => 'Next Saturday']);

        $this->assertSame(
            ['Friday night', 'Saturday', 'Sunday night'],
            collect($this->getJson('/api/discover')->json('weekend'))->pluck('title')->all(),
        );
        $this->assertSame(['Friday night', 'Saturday', 'Sunday night'], $this->titles('/api/events?when=weekend'));
    }

    public function test_on_a_sunday_the_weekend_is_the_one_underway(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04 15:00:00', 'UTC'));

        $this->at('2026-10-04 22:00:00', ['title' => 'Sunday night']);
        $this->at('2026-10-09 22:00:00', ['title' => 'Next Friday']);

        $this->assertSame(['Sunday night'], $this->titles('/api/events?when=weekend'));
    }

    public function test_this_month_and_a_range_of_days_are_the_events_own_calendar(): void
    {
        $this->at('2026-10-20 23:00:00', ['title' => 'October']);
        $this->at('2026-11-01 02:00:00', ['title' => 'Halloween night in Toronto']);
        // 11:30pm UTC on the 11th is the 12th in Lagos.
        $this->at('2026-10-11 23:30:00', ['title' => 'Lagos, the 12th', 'timezone' => 'Africa/Lagos', 'city' => 'Lagos', 'country' => 'NG', 'currency' => 'NGN']);

        $this->assertSame(['Lagos, the 12th', 'October', 'Halloween night in Toronto'], $this->titles('/api/events?when=month'));
        $this->assertSame(['Lagos, the 12th'], $this->titles('/api/events?date_from=2026-10-12&date_to=2026-10-12'));
        $this->assertSame([], $this->titles('/api/events?date_from=2026-10-11&date_to=2026-10-11'));
    }

    public function test_coming_up_is_what_today_and_this_weekend_have_not_shown(): void
    {
        $this->at('2026-10-03 00:00:00', ['title' => 'Friday night']);
        $this->at('2026-10-03 20:00:00', ['title' => 'Saturday']);
        $this->at('2026-10-06 23:00:00', ['title' => 'Tuesday']);
        $this->at('2026-10-10 20:00:00', ['title' => 'Next Saturday']);

        $this->assertSame(['Tuesday', 'Next Saturday'], collect($this->getJson('/api/discover')->json('later'))->pluck('title')->all());

        // Early in the week, the nights before Friday are neither today nor
        // the weekend, and must not fall between the two shelves.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 15:00:00', 'UTC'));
        $this->at('2026-10-05 23:00:00', ['title' => 'Monday night']);
        $this->at('2026-10-07 23:00:00', ['title' => 'Wednesday']);
        $this->at('2026-10-09 23:00:00', ['title' => 'This Friday']);

        // Saturday the 10th is this weekend's now, and has a shelf of its own.
        $this->assertSame(
            ['Tuesday', 'Wednesday'],
            collect($this->getJson('/api/discover')->json('later'))->pluck('title')->all(),
        );
    }

    public function test_past_is_what_has_ended_most_recent_first(): void
    {
        $this->at('2026-09-01 20:00:00', ['title' => 'September']);
        $this->at('2026-09-26 20:00:00', ['title' => 'Last Saturday']);
        $this->at('2026-05-01 20:00:00', ['title' => 'May']);
        $this->at('2026-10-09 20:00:00', ['title' => 'Next week']);

        $this->assertSame(['Last Saturday', 'September'], collect($this->getJson('/api/discover')->json('past'))->pluck('title')->all());
        // The listing goes back further: it pages.
        $this->assertSame(['Last Saturday', 'September', 'May'], $this->titles('/api/events?when=past'));
    }

    // --- how full ------------------------------------------------------------

    public function test_the_almost_and_sold_out_shelves_are_chosen_by_what_is_left(): void
    {
        $almost = $this->night(['title' => 'Nearly full']);
        $this->sell($this->tier($almost, 100), 95);

        $plenty = $this->night(['title' => 'Plenty left']);
        $this->sell($this->tier($plenty, 100), 10);

        $packed = $this->night(['title' => 'Packed', 'starts_at' => now()->addDays(3)]);
        $this->sell($this->tier($packed, 20), 20);

        $happened = $this->night(['title' => 'Packed last week', 'starts_at' => now()->subWeek()]);
        $this->sell($this->tier($happened, 20), 20);

        $presale = $this->night(['title' => 'Presale only']);
        $this->tier($presale, 50, ['status' => 'hidden']);

        // Sold two, and stopped selling when the doors opened three days ago.
        $quiet = $this->night(['title' => 'Quiet night', 'starts_at' => now()->subDays(3)]);
        $this->sell($this->tier($quiet, 500, ['sales_end_at' => now()->subDays(3)]), 2);

        // Online sales closed an hour ago, with most of the room unsold.
        $door = $this->night(['title' => 'Pay at the door', 'starts_at' => now()->addHours(5)]);
        $this->sell($this->tier($door, 500, ['sales_end_at' => now()->subHour()]), 10);

        $page = $this->getJson('/api/discover')->assertOk();

        $this->assertSame(['Nearly full'], collect($page->json('almost_sold_out'))->pluck('title')->all());

        // Still to come first, taking names; then the proof that nights here
        // sell out. A presale nobody can see is not sold out, and nor is a
        // night that simply stopped selling.
        $this->assertSame(['Packed', 'Packed last week'], collect($page->json('sold_out'))->pluck('title')->all());
        $this->assertSame([true, false], collect($page->json('sold_out'))->pluck('waitlist')->all());

        $this->assertSame(['Nearly full'], $this->titles('/api/events?availability=almost_sold_out'));
        $this->assertSame(['Packed'], $this->titles('/api/events?availability=sold_out'));
        $this->assertSame(['Pay at the door', 'Presale only'], $this->titles('/api/events?availability=closed'));
        $this->assertSame(['Nearly full', 'Plenty left'], $this->titles('/api/events?availability=on_sale'));
    }

    // --- categories and cities -----------------------------------------------

    public function test_categories_and_cities_carry_a_real_poster_and_a_slug(): void
    {
        $lead = $this->at('2026-10-05 20:00:00', ['title' => 'Food fair', 'category' => 'Food & drink', 'city' => 'Montréal', 'subdivision' => 'QC']);
        $this->poster($lead);
        $this->at('2026-10-06 20:00:00', ['title' => 'Supper club', 'category' => 'Food & drink', 'city' => 'Montréal', 'subdivision' => 'QC']);
        $this->at('2026-10-07 20:00:00', ['title' => 'Stand-up', 'category' => 'Comedy']);

        $page = $this->getJson('/api/discover')->assertOk();

        $food = collect($page->json('categories'))->firstWhere('category', 'Food & drink');
        $this->assertSame('food-and-drink', $food['slug']);
        $this->assertSame(2, $food['events']);
        $this->assertStringContainsString($lead->id, (string) $food['cover_url']);

        // Nothing with a poster: the site draws its own rather than borrowing one.
        $this->assertNull(collect($page->json('categories'))->firstWhere('category', 'Comedy')['cover_url']);

        $montreal = collect($page->json('cities'))->firstWhere('city', 'Montréal');
        $this->assertSame('montreal', $montreal['slug']);
        $this->assertNotNull($montreal['cover_url']);

        $this->getJson('/api/discover/facets')
            ->assertOk()
            ->assertJsonPath('categories.0.slug', 'food-and-drink')
            ->assertJsonPath('cities.0.slug', 'montreal');
    }

    public function test_a_category_and_a_city_each_have_a_page_and_a_stranger_gets_a_404(): void
    {
        $this->at('2026-10-05 20:00:00', ['category' => 'Food & drink', 'city' => 'Montréal', 'subdivision' => 'QC']);

        $this->getJson('/api/discover/categories/food-and-drink')
            ->assertOk()
            ->assertJsonPath('data.category', 'Food & drink')
            ->assertJsonPath('data.events', 1);

        // On the list, nothing on: still a page, which says so.
        $this->getJson('/api/discover/categories/sports')
            ->assertOk()
            ->assertJsonPath('data.category', 'Sports')
            ->assertJsonPath('data.events', 0);

        $this->getJson('/api/discover/categories/knitting')->assertNotFound();

        $this->getJson('/api/discover/cities/montreal')
            ->assertOk()
            ->assertJsonPath('data.city', 'Montréal')
            ->assertJsonPath('data.country', 'CA')
            ->assertJsonPath('data.events', 1);

        $this->getJson('/api/discover/cities/atlantis')->assertNotFound();
    }

    public function test_a_city_spelled_two_ways_is_one_place_and_its_page_lists_every_night_it_counts(): void
    {
        foreach (range(3, 5) as $n) {
            $this->at("2026-10-0{$n} 23:00:00", ['title' => "Montréal {$n}", 'city' => 'Montréal', 'subdivision' => 'QC']);
        }
        foreach (range(6, 7) as $n) {
            $this->at("2026-10-0{$n} 23:00:00", ['title' => "Montreal {$n}", 'city' => 'Montreal', 'subdivision' => 'QC']);
        }
        $this->at('2026-10-08 20:00:00', ['title' => 'Toronto']);

        $this->getJson('/api/discover/cities/montreal')
            ->assertOk()
            ->assertJsonPath('data.city', 'Montréal')
            ->assertJsonPath('data.events', 5);

        // The listing under the page, which asks by the name the page gave it,
        // holds the five it promised — and asking by the other spelling, or
        // in capitals, finds the same five.
        foreach (['Montréal', 'Montreal', 'MONTREAL'] as $asked) {
            $this->assertCount(5, $this->titles('/api/events?city='.urlencode($asked)), "Asked for {$asked}.");
        }

        $page = $this->getJson('/api/discover')->assertOk();

        // One card, counting both spellings, and one city in the hero's count.
        $this->assertSame([['montreal', 5], ['toronto', 1]], collect($page->json('cities'))->map(fn ($c) => [$c['slug'], $c['events']])->all());
        $this->assertSame(2, $page->json('totals.cities'));

        // The front page narrowed to the city finds both spellings too.
        $this->assertCount(5, collect($this->getJson('/api/discover?city=Montreal')->json('upcoming')));
    }

    public function test_the_sitemap_lists_each_category_and_city_with_something_on(): void
    {
        config(['app.public_url' => 'https://myfiesta.test']);
        $this->at('2026-10-05 20:00:00', ['category' => 'Food & drink', 'city' => 'Montréal', 'subdivision' => 'QC']);

        $xml = $this->get('/api/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<loc>https://myfiesta.test/events/category/food-and-drink</loc>', $xml);
        $this->assertStringContainsString('<loc>https://myfiesta.test/events/city/montreal</loc>', $xml);
        $this->assertStringNotContainsString('/events/category/sports', $xml);
    }

    // --- what else the front page says ---------------------------------------

    public function test_the_front_page_states_only_counted_figures_and_the_real_service_charge(): void
    {
        $this->at('2026-10-05 20:00:00');
        $this->at('2026-10-06 20:00:00', ['city' => 'Lagos', 'timezone' => 'Africa/Lagos', 'country' => 'NG', 'currency' => 'NGN']);
        $this->at('2026-09-01 20:00:00');

        $this->getJson('/api/discover')
            ->assertOk()
            ->assertJsonPath('totals.upcoming', 2)
            ->assertJsonPath('totals.cities', 2)
            ->assertJsonPath('fees.service_charge.CAD', '8')
            ->assertJsonPath('fees.service_charge.NGN', '8');
    }

    public function test_no_shelf_ever_shows_a_wedding(): void
    {
        $wedding = $this->night(['title' => 'Ada and Chidi', 'kind' => 'invitation', 'starts_at' => CarbonImmutable::parse('2026-10-03 01:00:00', 'UTC')]);
        $this->sell($this->tier($wedding, 10), 10);

        $page = $this->getJson('/api/discover')->assertOk();

        foreach (['featured', 'upcoming', 'today', 'weekend', 'almost_sold_out', 'sold_out', 'past'] as $shelf) {
            $this->assertNotContains('Ada and Chidi', collect($page->json($shelf))->pluck('title'), "a wedding surfaced in {$shelf}");
        }

        $this->assertSame(0, $page->json('totals.upcoming'));
    }

    public function test_the_front_page_is_kept_for_a_minute(): void
    {
        config(['discovery.cache_seconds' => 60]);
        Cache::flush();

        $this->at('2026-10-05 20:00:00', ['title' => 'First']);
        $this->getJson('/api/discover')->assertJsonCount(1, 'upcoming');

        $this->at('2026-10-06 20:00:00', ['title' => 'Second']);
        $this->getJson('/api/discover')->assertJsonCount(1, 'upcoming');

        $this->travel(61)->seconds();
        $this->getJson('/api/discover')->assertJsonCount(2, 'upcoming');
    }
}
