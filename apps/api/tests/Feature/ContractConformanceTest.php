<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketType;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Keeps packages/contract honest.
 *
 * Both client languages generate from that spec, so drift between it and the
 * API is not a documentation problem — it is a compile-time lie. The Dart and
 * TypeScript clients would encode a contract the server does not honour, and
 * the failure would surface at runtime in a phone at a door.
 *
 * This checks the two things a generator actually depends on: that every path
 * it declares exists, and that the response bodies carry the fields it says
 * they do. It is not a full schema validator, deliberately — that would be a
 * second implementation of OpenAPI to maintain.
 */
class ContractConformanceTest extends TestCase
{
    use RefreshDatabase;

    private array $spec;

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxRateSeeder::class);

        $this->spec = Yaml::parseFile(base_path('../../packages/contract/openapi.yaml'));

        $this->event = Event::create([
            'organization_id' => Organization::create(['name' => 'N', 'slug' => 'n-'.uniqid()])->id,
            'slug' => 'contract-test',
            'title' => 'Contract Test',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 10000,
            'status' => 'on_sale',
        ]);
    }

    public function test_every_declared_path_is_routable(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->map(fn ($r) => '/'.ltrim($r->uri(), '/'))
            // Route parameters are named for the model they bind; the spec
            // names them for the reader. Both become {} for comparison.
            ->map(fn ($uri) => preg_replace('/\{[^}]+\}/', '{}', $uri))
            ->unique();

        $missing = [];

        foreach (array_keys($this->spec['paths']) as $path) {
            $normalised = preg_replace('/\{[^}]+\}/', '{}', $path);

            if (! $routes->contains($normalised)) {
                $missing[] = $path;
            }
        }

        $this->assertSame([], $missing,
            'The contract declares paths the API does not serve: '.implode(', ', $missing));
    }

    public function test_the_event_list_carries_what_the_contract_promises(): void
    {
        $body = $this->getJson('/api/events')->assertOk()->json();

        $this->assertArrayHasKey('data', $body);

        $declared = $this->propertiesOf('EventSummary');

        foreach ($declared as $field) {
            $this->assertArrayHasKey($field, $body['data'][0],
                "EventSummary declares '{$field}' and the API does not return it.");
        }
    }

    public function test_the_event_detail_carries_what_the_contract_promises(): void
    {
        $body = $this->getJson('/api/events/contract-test')->assertOk()->json('data');

        // Event is modelled as an allOf over EventSummary, mirroring how the
        // resource extends it, so both halves have to be flattened.
        $declared = $this->propertiesOf('Event');

        foreach ($declared as $field) {
            $this->assertArrayHasKey($field, $body,
                "Event declares '{$field}' and the API does not return it.");
        }
    }

    public function test_a_quote_carries_what_the_contract_promises(): void
    {
        $body = $this->postJson('/api/events/contract-test/quote', [
            'items' => [['ticket_type_id' => $this->type->id, 'quantity' => 1]],
        ])->assertOk()->json();

        $declared = $this->propertiesOf('Quote');

        foreach ($declared as $field) {
            $this->assertArrayHasKey($field, $body,
                "Quote declares '{$field}' and the API does not return it.");
        }
    }

    public function test_money_is_always_an_amount_with_a_currency(): void
    {
        $body = $this->postJson('/api/events/contract-test/quote', [
            'items' => [['ticket_type_id' => $this->type->id, 'quantity' => 1]],
        ])->assertOk()->json();

        // The single most important shape in the API. A bare number would make
        // every client guess, and the guess would be wrong the moment a Lagos
        // event appeared next to a Toronto one.
        foreach (['subtotal', 'discount', 'tax', 'total'] as $field) {
            $this->assertArrayHasKey('amount', $body[$field], "{$field} has no amount.");
            $this->assertArrayHasKey('currency', $body[$field], "{$field} has no currency.");
            $this->assertIsInt($body[$field]['amount'], "{$field} amount must be minor units, not a float.");
        }
    }

    public function test_the_spec_declares_the_currencies_the_platform_actually_takes(): void
    {
        $declared = $this->spec['components']['schemas']['Money']['properties']['currency']['enum'] ?? [];

        // Both launch markets. A currency missing here generates a Dart enum
        // that cannot represent a real price.
        $this->assertContains('CAD', $declared);
        $this->assertContains('NGN', $declared);
    }

    /**
     * Flatten a schema to its property names, following allOf.
     *
     * @return list<string>
     */
    private function propertiesOf(string $schema): array
    {
        $node = $this->spec['components']['schemas'][$schema];

        if (isset($node['properties'])) {
            return array_keys($node['properties']);
        }

        $names = [];

        foreach ($node['allOf'] ?? [] as $part) {
            if (isset($part['$ref'])) {
                $names = array_merge($names, $this->propertiesOf(basename($part['$ref'])));
            }
            if (isset($part['properties'])) {
                $names = array_merge($names, array_keys($part['properties']));
            }
        }

        return array_values(array_unique($names));
    }
}
