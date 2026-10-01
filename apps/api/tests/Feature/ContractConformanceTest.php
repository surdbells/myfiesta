<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Http\Requests\CreateOrderRequest;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
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

    /**
     * A ticket, the two places it is shown: the buyer's link and the phone's
     * list. Each carries every field the features added since declare on it,
     * as null or empty when they have nothing to say, so a client never
     * mistakes "nothing" for "an older server".
     */
    public function test_a_ticket_carries_what_the_contract_promises_on_the_link_and_the_phone(): void
    {
        $holder = User::factory()->create(['email' => 'ada@example.com']);

        $order = Order::create([
            'reference' => 'MF'.strtoupper(Str::random(8)),
            'access_token' => Str::random(44),
            'organization_id' => $this->event->organization_id,
            'event_id' => $this->event->id,
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada Okafor',
            'currency' => 'CAD',
            'subtotal_amount' => 10000,
            'net_revenue_amount' => 10000,
            'total_amount' => 10000,
            'status' => 'paid',
        ]);

        Ticket::create([
            'event_id' => $this->event->id,
            'order_id' => $order->id,
            'ticket_type_id' => $this->type->id,
            'code' => Ticket::generateCode(),
            'owner_user_id' => $holder->id,
            'owner_email' => 'ada@example.com',
            'holder_name' => 'Ada Okafor',
            'status' => 'valid',
        ]);

        $held = $this->getJson('/api/tickets/'.$order->access_token)->assertOk()->json('tickets.0');

        foreach ($this->spec['components']['schemas']['HeldTicket']['required'] as $field) {
            $this->assertArrayHasKey($field, $held, "HeldTicket requires '{$field}' and the ticket link does not return it.");
        }

        Sanctum::actingAs($holder, [TokenAbility::Attendee->value]);
        $mine = $this->getJson('/api/me/tickets')->assertOk()->json('data.0');

        foreach ($this->spec['components']['schemas']['Ticket']['required'] as $field) {
            $this->assertArrayHasKey($field, $mine, "Ticket requires '{$field}' and the phone's list does not return it.");
        }

        // Declared as always there on both, so a generated client never
        // makes them optional.
        foreach (['perks', 'share_link', 'transferable'] as $field) {
            $this->assertContains($field, $this->spec['components']['schemas']['HeldTicket']['required']);
            $this->assertContains($field, $this->spec['components']['schemas']['Ticket']['required']);
        }
    }

    public function test_an_organizer_page_carries_what_the_contract_promises(): void
    {
        $slug = $this->event->organization->slug;

        $body = $this->getJson("/api/organizers/{$slug}")->assertOk()->json('data');

        // Also an allOf, over OrganizerBrand — the same block the event page
        // carries, so the two cannot drift into two different organizers.
        foreach ($this->propertiesOf('OrganizerPage') as $field) {
            $this->assertArrayHasKey($field, $body,
                "OrganizerPage declares '{$field}' and the API does not return it.");
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

    /**
     * The other direction, which nothing checked.
     *
     * Everything above asks whether a response carries what the spec promises.
     * A request body is the same contract read backwards, and it had already
     * drifted: the spec declared `buyer_email`, `buyer_name` and `buyer_phone`
     * as flat fields, and the API has only ever accepted a nested `buyer`
     * object. A generated client would have encoded a body the server
     * discards, and the failure would have arrived as a validation error in
     * front of somebody trying to pay.
     */
    public function test_the_order_request_is_the_one_the_api_takes(): void
    {
        $declared = $this->propertiesOf('CreateOrderRequest');

        // The root of each validation rule: `buyer.email` is the `buyer` the
        // spec has to be describing.
        $accepted = collect(array_keys((new CreateOrderRequest)->rules()))
            ->map(fn (string $rule) => explode('.', $rule)[0])
            ->unique()
            ->all();

        foreach ($declared as $field) {
            $this->assertContains($field, $accepted,
                "CreateOrderRequest declares '{$field}' and the API does not accept it.");
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

    public function test_the_readiness_answer_carries_what_the_contract_promises(): void
    {
        // Disks of the test's own: every check writes a file to each.
        Storage::fake('private');
        Storage::fake('public');

        // No heartbeat has been written here, so it answers 503. The shape is
        // the same either way, and the contract declares both.
        $response = $this->getJson('/api/health/ready');
        $this->assertContains($response->status(), [200, 503]);

        foreach ($this->propertiesOf('Readiness') as $field) {
            $this->assertArrayHasKey($field, $response->json(), "Readiness declares '{$field}' and the API does not return it.");
        }

        // Every part, in the order it is asked.
        $this->assertSame(
            array_keys($this->spec['components']['schemas']['Readiness']['properties']['checks']['properties']),
            array_keys($response->json('checks')),
        );

        foreach ($response->json('checks') as $name => $check) {
            $this->assertIsBool($check['ok'] ?? null, "{$name} has no ok.");
        }
    }

    public function test_the_contact_details_carry_what_the_contract_promises(): void
    {
        $body = $this->getJson('/api/contact')->assertOk()->json('data');

        foreach ($this->propertiesOf('Contact') as $field) {
            $this->assertArrayHasKey($field, $body, "Contact declares '{$field}' and the API does not return it.");
        }
    }

    /**
     * A sign-up built from nothing but what the spec declares is one the API
     * takes, the box on the terms included — and without the box it is
     * refused, as the spec says.
     */
    public function test_a_sign_up_built_from_the_contract_is_taken(): void
    {
        Mail::fake();

        $body = [
            'name' => 'Ada Contract',
            'email' => 'ada@contract.test',
            'password' => 'contract-password-42',
            'password_confirmation' => 'contract-password-42',
            'organization' => 'Ada Nights',
            'accept_terms' => true,
        ];

        $this->assertSame([], array_values(array_diff(array_keys($body), $this->propertiesOf('SignUpRequest'))), 'The body sends something the spec does not declare.');
        $this->assertSame([], array_values(array_diff($this->spec['components']['schemas']['SignUpRequest']['required'], array_keys($body))), 'The body leaves out something the spec requires.');

        $answer = $this->postJson('/api/auth/register', $body)->assertStatus(202)->json();

        foreach ($this->propertiesOf('SignUpPending') as $field) {
            $this->assertArrayHasKey($field, $answer, "SignUpPending declares '{$field}' and the API does not return it.");
        }

        $this->postJson('/api/auth/register', [...$body, 'email' => 'grace@contract.test', 'accept_terms' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('accept_terms');
    }

    public function test_the_signed_in_account_and_its_organization_answer_as_the_contract_says(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email_verified_at' => null]);
        $this->event->organization->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => 'owner', 'accepted_at' => now()]);
        Sanctum::actingAs($user->fresh(), [TokenAbility::Attendee->value, TokenAbility::Organizer->value]);

        foreach ([
            ['GET', '/api/auth/terms', 'TermsStanding'],
            ['GET', '/api/auth/erasure', 'AccountErasurePreview'],
            ['POST', '/api/auth/email/verification', 'EmailVerificationAnswer'],
            ['GET', '/api/organizer/standing', 'OrganizationStanding'],
        ] as [$method, $path, $schema]) {
            $body = $this->json($method, $path)->assertSuccessful()->json();

            foreach ($this->propertiesOf($schema) as $field) {
                $this->assertArrayHasKey($field, $body, "{$schema} declares '{$field}' and {$method} {$path} does not return it.");
            }

            if ($schema === 'AccountErasurePreview') {
                foreach (array_keys($this->spec['components']['schemas'][$schema]['properties']['organizations']['items']['properties']) as $field) {
                    $this->assertArrayHasKey($field, $body['organizations'][0], "{$schema}.organizations declares '{$field}' and the API does not return it.");
                }
            }
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
