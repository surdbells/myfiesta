<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\HelpVideo;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sitemap lists public event pages, and nothing that is not one.
 */
class SitemapTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.public_url' => 'https://myfiesta.test/']);

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
    }

    private function event(string $slug, array $attributes = []): Event
    {
        return Event::create([
            'organization_id' => $this->org->id,
            'slug' => $slug,
            'title' => ucfirst($slug),
            'currency' => 'CAD',
            'starts_at' => now()->addWeek(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
            ...$attributes,
        ]);
    }

    public function test_it_lists_public_event_pages_and_leaves_out_the_rest(): void
    {
        $this->event('afro-fest');
        $this->event('last-month', ['starts_at' => now()->subDays(30)]);
        $this->event('last-year', ['starts_at' => now()->subYear()]);
        $this->event('still-a-draft', ['status' => 'draft']);
        $this->event('called-off', ['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->event('ada-and-tunde', ['kind' => 'invitation']);

        $response = $this->get('/api/sitemap.xml')->assertOk();

        $this->assertStringStartsWith('application/xml', $response->headers->get('Content-Type'));

        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml, 'The sitemap must be well-formed XML.');

        $locations = array_map('strval', $xml->xpath('//*[local-name()="loc"]'));

        $this->assertContains('https://myfiesta.test/', $locations);
        $this->assertContains('https://myfiesta.test/events', $locations);
        $this->assertContains('https://myfiesta.test/afro-fest', $locations);
        // Recent past events keep their page: the gallery sells the next one.
        $this->assertContains('https://myfiesta.test/last-month', $locations);

        foreach (['last-year', 'still-a-draft', 'called-off', 'ada-and-tunde'] as $hidden) {
            $this->assertNotContains("https://myfiesta.test/{$hidden}", $locations);
        }
    }

    public function test_it_lists_organizers_who_have_published_a_night(): void
    {
        $this->event('afro-fest');

        // An organizer page is linked from their events and from nowhere else,
        // so without this a crawler that never reaches an event never learns
        // the page exists.
        $locations = $this->locations();

        $this->assertContains('https://myfiesta.test/o/lagos-nights', $locations);
    }

    public function test_an_organizer_with_nothing_public_is_left_out(): void
    {
        $this->event('afro-fest');

        Organization::create(['name' => 'Signed Up Yesterday', 'slug' => 'signed-up-yesterday']);

        $wedding = Organization::create(['name' => 'Caterer', 'slug' => 'caterer']);
        Event::create([
            'organization_id' => $wedding->id,
            'slug' => 'ada-and-tunde-again',
            'title' => 'Ada and Tunde',
            'currency' => 'CAD',
            'starts_at' => now()->addWeek(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
            'kind' => 'invitation',
        ]);

        $locations = $this->locations();

        // Both would answer 404: the page applies the same rule. A sitemap
        // pointing at 404s is worse than one that is short.
        $this->assertNotContains('https://myfiesta.test/o/signed-up-yesterday', $locations);
        $this->assertNotContains('https://myfiesta.test/o/caterer', $locations);
    }

    public function test_the_how_to_videos_are_listed_once_there_is_one_to_watch(): void
    {
        // An empty list is a page a crawler is told to index for nothing.
        HelpVideo::create(['title' => 'Draft', 'youtube_id' => 'abcDEF12345', 'audience' => 'buyers', 'published' => false]);

        $this->assertNotContains('https://myfiesta.test/help/videos', $this->locations());
        $this->assertContains('https://myfiesta.test/help', $this->locations());

        HelpVideo::create(['title' => 'Buying a ticket', 'youtube_id' => 'abcDEF12346', 'audience' => 'buyers', 'published' => true]);

        $this->assertContains('https://myfiesta.test/help/videos', $this->locations());
    }

    /** @return list<string> */
    private function locations(): array
    {
        $xml = simplexml_load_string($this->get('/api/sitemap.xml')->assertOk()->getContent());

        $this->assertNotFalse($xml, 'The sitemap must be well-formed XML.');

        return array_map('strval', $xml->xpath('//*[local-name()="loc"]'));
    }
}
