<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\HelpVideo;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The organizer page's socials and paging, and the how-to videos, as
 * packages/contract declares them.
 *
 * Beside ContractConformanceTest rather than in it, which checks the page
 * itself: this checks the shapes inside it and the two lists that are new,
 * field by field, with every field present even when it is empty.
 */
class OrganizerPageContractTest extends TestCase
{
    use RefreshDatabase;

    private array $spec;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spec = Yaml::parseFile(base_path('../../packages/contract/openapi.yaml'));

        $this->org = Organization::create([
            'name' => 'Lagos Nights',
            'slug' => 'lagos-nights',
            'instagram' => 'lagosnights',
            'website' => 'https://lagosnights.com',
        ]);

        Event::factory()->published()->create([
            'organization_id' => $this->org->id,
            'slug' => 'last-month',
            'starts_at' => now()->subMonth(),
        ]);
    }

    public function test_a_social_link_carries_what_the_contract_promises(): void
    {
        $socials = $this->getJson('/api/organizers/lagos-nights')->assertOk()->json('data.socials');

        $this->assertCount(2, $socials);

        foreach ($socials as $link) {
            $this->assertSame($this->propertiesOf('SocialLink'), array_keys($link));
            $this->assertContains($link['network'], $this->spec['components']['schemas']['SocialLink']['properties']['network']['enum']);
            $this->assertStringStartsWith('https://', $link['url']);
        }
    }

    public function test_a_page_of_nights_carries_what_the_contract_promises(): void
    {
        $body = $this->getJson('/api/organizers/lagos-nights/events?when=past&page=1')->assertOk()->json();

        $this->assertSame($this->propertiesOf('OrganizerEvents'), array_keys($body));
        $this->assertSame(
            array_keys($this->spec['components']['schemas']['OrganizerEvents']['properties']['meta']['properties']),
            array_keys($body['meta']),
        );

        foreach ($this->propertiesOf('EventSummary') as $field) {
            $this->assertArrayHasKey($field, $body['data'][0], "EventSummary declares '{$field}' and the page of nights does not return it.");
        }
    }

    public function test_a_help_video_carries_what_the_contract_promises(): void
    {
        // No description: still there, as null.
        HelpVideo::create(['title' => 'Buying a ticket', 'youtube_id' => 'abcDEF12345', 'audience' => 'buyers', 'published' => true]);

        $video = $this->getJson('/api/help/videos')->assertOk()->json('data.0');

        $this->assertSame($this->propertiesOf('HelpVideo'), array_keys($video));
        $this->assertNull($video['description']);
        $this->assertMatchesRegularExpression(
            '/'.$this->spec['components']['schemas']['HelpVideo']['properties']['youtube_id']['pattern'].'/',
            $video['youtube_id'],
        );
    }

    /** @return list<string> */
    private function propertiesOf(string $schema): array
    {
        return array_keys($this->spec['components']['schemas'][$schema]['properties']);
    }
}
