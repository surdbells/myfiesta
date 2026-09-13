<?php

namespace Tests\Feature;

use App\Models\Event;
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
}
