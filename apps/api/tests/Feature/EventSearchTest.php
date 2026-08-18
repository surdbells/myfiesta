<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketType;
use App\Services\Discovery\EventFilters;
use App\Services\Discovery\EventSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Discovery.
 *
 * The previous platform had none of this: no search endpoint, no filters, no
 * sort, and feeds that returned every published event unpaginated. All of it
 * comes from Postgres here, with no second service to run.
 */
class EventSearchTest extends TestCase
{
    use RefreshDatabase;

    private EventSearch $search;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->search = app(EventSearch::class);
        $this->org = Organization::create(['name' => 'Nights', 'slug' => 'nights']);
    }

    private function event(array $attrs = []): Event
    {
        $event = Event::create(array_merge([
            'organization_id' => $this->org->id,
            'slug' => 'e-'.uniqid(),
            'title' => 'Test Event',
            'currency' => 'CAD',
            'starts_at' => now()->addWeek(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
            'published_at' => now(),
        ], $attrs));

        TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => $attrs['_price'] ?? 5000,
            'status' => 'on_sale',
        ]);

        return $event;
    }

    private function find(array $filters = []): array
    {
        return $this->search
            ->query(new EventFilters(...$filters))
            ->items();
    }

    public function test_full_text_search_matches_the_title(): void
    {
        $this->event(['title' => 'Afrobeats All Night']);
        $this->event(['title' => 'Jazz Brunch']);

        $results = $this->find(['text' => 'afrobeats']);

        $this->assertCount(1, $results);
        $this->assertSame('Afrobeats All Night', $results[0]->title);
    }

    public function test_search_reaches_the_description_and_the_city(): void
    {
        $this->event(['title' => 'Untitled', 'description' => 'An evening of highlife and palm wine']);
        $this->event(['title' => 'Other', 'city' => 'Lagos', 'country' => 'NG', 'subdivision' => null]);

        $this->assertCount(1, $this->find(['text' => 'highlife']));
        $this->assertCount(1, $this->find(['text' => 'Lagos']));
    }

    public function test_punctuation_does_not_break_the_search_box(): void
    {
        $this->event(['title' => "Ada's Birthday"]);

        // to_tsquery throws on input like this. websearch_to_tsquery is used
        // precisely so a search box does not 500 on an apostrophe.
        $this->assertIsArray($this->find(['text' => "Ada's"]));
        $this->assertIsArray($this->find(['text' => 'rock & roll']));
        $this->assertIsArray($this->find(['text' => '"quoted phrase"']));
    }

    public function test_unpublished_events_are_never_returned(): void
    {
        $this->event(['title' => 'Draft Night', 'status' => 'draft']);
        $this->event(['title' => 'Live Night']);

        $results = $this->find();

        $this->assertCount(1, $results);
        $this->assertSame('Live Night', $results[0]->title);
    }

    public function test_events_that_have_already_started_are_excluded_by_default(): void
    {
        $this->event(['title' => 'Last Week', 'starts_at' => now()->subWeek()]);
        $this->event(['title' => 'Next Week']);

        // Someone browsing wants something they can still go to.
        $results = $this->find();

        $this->assertCount(1, $results);
        $this->assertSame('Next Week', $results[0]->title);
    }

    public function test_results_are_soonest_first(): void
    {
        $this->event(['title' => 'Later', 'starts_at' => now()->addMonth()]);
        $this->event(['title' => 'Sooner', 'starts_at' => now()->addDay()]);

        $titles = array_map(fn ($e) => $e->title, $this->find());

        $this->assertSame(['Sooner', 'Later'], $titles);
    }

    public function test_a_price_band_matches_the_cheapest_way_in(): void
    {
        $event = $this->event(['title' => 'Tiered', '_price' => 20000]);

        TicketType::create([
            'event_id' => $event->id,
            'name' => 'Early bird',
            'price_amount' => 2500,
            'status' => 'on_sale',
        ]);

        // "Under $50" means there is a way in under $50. Matching on the
        // dearest tier would hide affordable events behind their VIP option.
        $results = $this->find(['maxPrice' => 5000]);

        $this->assertCount(1, $results);
    }

    public function test_free_events_can_be_isolated(): void
    {
        $this->event(['title' => 'Paid', '_price' => 5000]);
        $this->event(['title' => 'Free', '_price' => 0]);

        $results = $this->find(['freeOnly' => true]);

        $this->assertCount(1, $results);
        $this->assertSame('Free', $results[0]->title);
    }

    public function test_place_filters_narrow_by_city_and_country(): void
    {
        $this->event(['title' => 'TO', 'city' => 'Toronto']);
        $this->event(['title' => 'Lagos', 'city' => 'Lagos', 'country' => 'NG', 'subdivision' => null]);

        $this->assertCount(1, $this->find(['country' => 'NG']));
        // Case-insensitive: nobody capitalises consistently in a search box.
        $this->assertCount(1, $this->find(['city' => 'toronto']));
    }

    public function test_results_are_paginated(): void
    {
        foreach (range(1, 30) as $i) {
            $this->event(['title' => "Night {$i}", 'starts_at' => now()->addDays($i)]);
        }

        $page = $this->search->query(new EventFilters(perPage: 10));

        $this->assertCount(10, $page->items());
        $this->assertTrue($page->hasMorePages());
    }

    public function test_page_size_is_capped(): void
    {
        $request = Request::create('/', 'GET', ['per_page' => 5000]);

        // Otherwise a crawler asks for the whole table in one response.
        $this->assertSame(50, EventFilters::fromRequest($request)->perPage);
    }
}
