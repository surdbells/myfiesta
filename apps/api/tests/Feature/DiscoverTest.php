<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The front page.
 *
 * There was not one: the root rendered the same flat search list as /events, so
 * somebody arriving without a link had nothing to look at and no way to browse.
 *
 * The test that matters most is the last one. Invitation events are published
 * so their invited guests can reach them by token, never so strangers can, and
 * a wedding appearing on the front page is the one bug on this screen that
 * cannot be apologised for.
 */
class DiscoverTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
    }

    private function event(array $overrides = []): Event
    {
        $event = Event::create(array_merge([
            'organization_id' => $this->org->id,
            'slug' => 'e-'.Str::lower(Str::random(8)),
            'title' => 'A Night',
            'currency' => 'CAD',
            'starts_at' => now()->addWeeks(2),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'category' => 'Music',
            'status' => 'published',
        ], $overrides));

        TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => 2500,
            'status' => 'on_sale',
        ]);

        return $event;
    }

    // --- what it shows -------------------------------------------------------

    public function test_the_front_page_arrives_in_one_request(): void
    {
        $this->event();

        // Four sections from four endpoints would be four chances to look
        // broken on a phone, and this is the first screen a stranger sees.
        $this->getJson('/api/discover')
            ->assertOk()
            ->assertJsonStructure(['featured', 'upcoming', 'cities', 'categories']);
    }

    public function test_featured_events_lead(): void
    {
        $this->event(['title' => 'Ordinary Night']);
        $this->event(['title' => 'The Big One', 'is_featured' => true]);

        $this->getJson('/api/discover')
            ->assertOk()
            ->assertJsonPath('featured.0.title', 'The Big One');
    }

    public function test_nothing_flagged_still_fills_the_hero(): void
    {
        $this->event(['title' => 'Ordinary Night']);

        // An empty hero reads as a dead site, and a new market has no featured
        // events by definition.
        $this->getJson('/api/discover')
            ->assertOk()
            ->assertJsonCount(1, 'featured')
            ->assertJsonPath('featured.0.title', 'Ordinary Night');
    }

    public function test_cities_and_categories_come_back_with_counts(): void
    {
        $this->event(['city' => 'Toronto', 'category' => 'Music']);
        $this->event(['city' => 'Toronto', 'category' => 'Music']);
        $this->event(['city' => 'Lagos', 'category' => 'Comedy']);

        $response = $this->getJson('/api/discover')->assertOk();

        // The count is what tells somebody whether the tap is worth it.
        $this->assertSame(
            ['Toronto' => 2, 'Lagos' => 1],
            collect($response->json('cities'))->pluck('events', 'city')->all(),
        );
        $this->assertSame(
            ['Music' => 2, 'Comedy' => 1],
            collect($response->json('categories'))->pluck('events', 'category')->all(),
        );
    }

    public function test_a_city_with_nothing_on_is_not_offered(): void
    {
        $this->event(['city' => 'Toronto']);
        $this->event(['city' => 'Halifax', 'starts_at' => now()->subWeek()]);

        // Offering a filter that leads to an empty page is worse than not
        // offering it.
        $this->assertSame(
            ['Toronto'],
            collect($this->getJson('/api/discover')->json('cities'))->pluck('city')->all(),
        );
    }

    // --- what it must not show -----------------------------------------------

    public function test_an_event_that_has_already_happened_is_not_upcoming(): void
    {
        $this->event(['title' => 'Last Month', 'starts_at' => now()->subMonth()]);
        $this->event(['title' => 'Next Month']);

        $titles = collect($this->getJson('/api/discover')->json('upcoming'))->pluck('title');

        $this->assertContains('Next Month', $titles);
        $this->assertNotContains('Last Month', $titles);
    }

    public function test_a_draft_is_not_shown(): void
    {
        $this->event(['title' => 'Not Ready', 'status' => 'draft']);

        $this->getJson('/api/discover')
            ->assertOk()
            ->assertJsonCount(0, 'featured')
            ->assertJsonCount(0, 'upcoming');
    }

    public function test_a_wedding_never_reaches_the_front_page(): void
    {
        $this->event(['title' => 'Ada and Chidi', 'kind' => 'invitation']);
        $this->event(['title' => 'A Public Night']);

        $response = $this->getJson('/api/discover')->assertOk();

        /*
         * Invitation events are published so their invited guests can reach
         * them by token, never so strangers can. This is the one failure on
         * this screen that cannot be apologised for, and it is one missing
         * where() away in every section — which is why every section is
         * checked rather than just the first.
         */
        foreach (['featured', 'upcoming'] as $section) {
            $this->assertNotContains(
                'Ada and Chidi',
                collect($response->json($section))->pluck('title'),
                "a wedding surfaced in {$section}",
            );
        }

        $this->assertContains('A Public Night', collect($response->json('upcoming'))->pluck('title'));
    }

    public function test_a_wedding_does_not_leak_through_the_city_list_either(): void
    {
        $this->event(['title' => 'Ada and Chidi', 'kind' => 'invitation', 'city' => 'Niagara']);

        // The counts are a separate query, and a separate place to forget.
        $this->assertSame(
            [],
            collect($this->getJson('/api/discover')->json('cities'))->pluck('city')->all(),
        );
    }

    public function test_the_page_can_be_narrowed_to_one_city(): void
    {
        $this->event(['title' => 'In Toronto', 'city' => 'Toronto']);
        $this->event(['title' => 'In Lagos', 'city' => 'Lagos']);

        $titles = collect($this->getJson('/api/discover?city=Lagos')->json('upcoming'))->pluck('title');

        $this->assertSame(['In Lagos'], $titles->all());
    }

    public function test_it_holds_up_with_nothing_on_sale(): void
    {
        // A brand new deployment, or a market between seasons. Every section
        // has to be an empty array rather than null, or the front page throws
        // on its first render.
        $this->getJson('/api/discover')
            ->assertOk()
            ->assertJsonCount(0, 'featured')
            ->assertJsonCount(0, 'upcoming')
            ->assertJsonCount(0, 'cities')
            ->assertJsonCount(0, 'categories');
    }
}
