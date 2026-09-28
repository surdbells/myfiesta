<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What the public reads say about being kept.
 *
 * Nothing, until this: Laravel answered "no-cache, private" for each, its
 * default for a response that sets no policy. The public site renders its
 * pages on the server from these, and Angular's transfer cache will not hand
 * a private or no-cache answer on to the browser — so every page fetched all
 * of its data a second time the moment it arrived, and a city's page flashed
 * back to skeletons while it did. Found by reading the page's transfer state,
 * which held nothing but hydration data.
 *
 * They are the same for everybody, so "public"; and still never served
 * stale, so "max-age=0, must-revalidate" — the event page's badges have to
 * agree with checkout.
 */
class PublicReadCachingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $event = Event::create([
            'organization_id' => $organization->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);

        TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => 2500,
            'status' => 'on_sale',
        ]);
    }

    /** @return array<string, array{string}> */
    public static function reads(): array
    {
        return [
            'the front page' => ['/api/discover'],
            'the listing filters' => ['/api/discover/facets'],
            'the listing' => ['/api/events'],
            'an event' => ['/api/events/afro-fest'],
            'an organizer' => ['/api/organizers/lagos-nights'],
        ];
    }

    #[DataProvider('reads')]
    public function test_a_public_read_may_be_handed_from_the_server_render_to_the_browser(string $url): void
    {
        $response = $this->getJson($url)->assertOk();

        foreach (['private', 'no-cache', 'no-store'] as $refused) {
            $this->assertFalse(
                $response->headers->hasCacheControlDirective($refused),
                "{$url} says {$refused}, which Angular's transfer cache refuses: ".$this->policy($response),
            );
        }

        $this->assertTrue($response->headers->hasCacheControlDirective('public'), $this->policy($response));
    }

    #[DataProvider('reads')]
    public function test_a_public_read_is_still_never_served_stale(string $url): void
    {
        $response = $this->getJson($url)->assertOk();

        $this->assertSame('0', (string) $response->headers->getCacheControlDirective('max-age'), $this->policy($response));
        $this->assertTrue($response->headers->hasCacheControlDirective('must-revalidate'), $this->policy($response));
    }

    public function test_a_read_that_sets_its_own_policy_keeps_it(): void
    {
        $response = $this->getJson('/api/event-categories')->assertOk();

        $this->assertSame('3600', (string) $response->headers->getCacheControlDirective('max-age'));
    }

    private function policy(TestResponse $response): string
    {
        return (string) $response->headers->get('Cache-Control');
    }
}
