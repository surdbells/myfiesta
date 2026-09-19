<?php

namespace Tests\Feature;

use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Contracts\Payments\RefundResult;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Checkout\Fulfiller;
use App\Services\Door\CheckInService;
use App\Services\Integrations\Webhooks;
use App\Services\Integrations\WebhookTarget;
use App\Services\Refunds\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Other systems: webhooks going out, keys reading in.
 *
 * What is being protected, in order. That a receiver being down never touches
 * the sale it is about. That nothing is ever sent somewhere inside our own
 * network because somebody typed its name into a form. That a delivery can be
 * proven to be ours. That a ticket code never leaves by either route. And that
 * only an owner can point the organization's data anywhere.
 */
class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $type;

    private User $owner;

    /** @var array<string, list<string>> */
    private array $dns = [
        'hooks.example.com' => ['93.184.216.34'],
        'sneaky.example.com' => ['93.184.216.34', '10.0.0.5'],
        'metadata.example.com' => ['169.254.169.254'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // What the DNS would have said, without the test depending on it.
        $dns = &$this->dns;
        $this->app->instance(WebhookTarget::class, new class($dns) extends WebhookTarget
        {
            public function __construct(private array &$answers) {}

            public function resolve(string $host): array
            {
                return $this->answers[$host] ?? [];
            }
        });

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
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

        $this->type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);

        $this->owner = $this->member(Role::Owner);
        $this->asOrganizer($this->owner);
    }

    private function member(Role $role, ?Organization $org = null): User
    {
        $user = User::factory()->create();

        ($org ?? $this->org)->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        return $user->fresh()->load('organizations');
    }

    private function asOrganizer(User $user): void
    {
        Sanctum::actingAs($user, [TokenAbility::Attendee->value, TokenAbility::Organizer->value]);
    }

    private function endpoint(array $events = WebhookEndpoint::EVENTS, string $url = 'https://hooks.example.com/in'): WebhookEndpoint
    {
        return WebhookEndpoint::create([
            'organization_id' => $this->org->id,
            'url' => $url,
            'secret' => 'whsec_test',
            'events' => $events,
        ]);
    }

    private function paidOrder(int $quantity = 1): Order
    {
        $order = Order::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada Okafor',
            'currency' => 'CAD',
            'subtotal_amount' => 5000 * $quantity,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'net_revenue_amount' => 5000 * $quantity,
            'service_charge_amount' => 0,
            'total_amount' => 5000 * $quantity,
            'gateway' => 'stripe',
            'gateway_reference' => 'pi_test',
            'status' => 'pending',
        ]);

        $order->lines()->create([
            'ticket_type_id' => $this->type->id,
            'name' => 'General',
            'unit_price_amount' => 5000,
            'quantity' => $quantity,
            'line_total_amount' => 5000 * $quantity,
        ]);

        return app(Fulfiller::class)->fulfil($order->refresh());
    }

    // --- going out --------------------------------------------------------------

    public function test_a_paid_order_is_delivered_signed(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);
        $this->endpoint();

        $order = $this->paidOrder();

        $delivery = WebhookDelivery::sole();
        $this->assertSame('order.paid', $delivery->event);
        $this->assertSame('succeeded', $delivery->status);
        $this->assertSame(1, $delivery->attempts);

        Http::assertSent(function (SentRequest $request) use ($order) {
            $body = $request->body();
            $data = json_decode($body, true);

            preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $request->header('X-MyFiesta-Signature')[0], $m);

            return $request->url() === 'https://hooks.example.com/in'
                && $data['event'] === 'order.paid'
                && $data['data']['reference'] === $order->reference
                && $m !== []
                // What a receiver does with the secret it was shown.
                && hash_equals(hash_hmac('sha256', $m[1].'.'.$body, 'whsec_test'), $m[2]);
        });
    }

    public function test_an_endpoint_hears_only_what_it_asked_for(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $this->endpoint(['ticket.checked_in']);

        $this->paidOrder();

        $this->assertSame(0, WebhookDelivery::count());
    }

    public function test_a_receiver_that_is_down_never_breaks_the_sale(): void
    {
        Http::fake(['*' => Http::response('nope', 503)]);
        $this->endpoint();

        $order = $this->paidOrder();

        $this->assertSame('paid', $order->status);
        $this->assertSame(1, Ticket::count());

        $delivery = WebhookDelivery::sole();
        $this->assertSame('pending', $delivery->status);
        $this->assertSame(503, $delivery->response_status);
        $this->assertEqualsWithDelta(now()->addSeconds(Webhooks::BACKOFF[0])->timestamp, $delivery->next_attempt_at->timestamp, 5);
    }

    public function test_a_receiver_that_throws_never_breaks_the_sale(): void
    {
        Http::fake(fn () => throw new \RuntimeException('connection refused'));
        $this->endpoint();

        $order = $this->paidOrder();

        $this->assertSame('paid', $order->status);
        $this->assertStringContainsString('connection refused', WebhookDelivery::sole()->response_excerpt);
    }

    public function test_retries_back_off_then_give_up_and_count_against_the_endpoint(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        $endpoint = $this->endpoint();
        $this->paidOrder();

        $delivery = WebhookDelivery::sole();

        // Not due yet: nothing happens.
        $this->artisan('webhooks:retry')->assertSuccessful();
        $this->assertSame(1, $delivery->fresh()->attempts);

        foreach (Webhooks::BACKOFF as $i => $wait) {
            $this->travel($wait + 1)->seconds();
            $this->artisan('webhooks:retry')->assertSuccessful();
            $this->assertSame($i + 2, $delivery->fresh()->attempts);
        }

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertSame(1, $endpoint->fresh()->consecutive_failures);

        // Spent means spent.
        $this->travel(1)->days();
        $this->artisan('webhooks:retry')->assertSuccessful();
        $this->assertSame(count(Webhooks::BACKOFF) + 1, $delivery->fresh()->attempts);
    }

    public function test_a_retry_that_works_resets_the_count(): void
    {
        Http::fakeSequence()->push('down', 500)->push('ok', 200);
        $endpoint = $this->endpoint();
        $endpoint->update(['consecutive_failures' => 7]);

        $this->paidOrder();
        $this->travel(61)->seconds();
        $this->artisan('webhooks:retry')->assertSuccessful();

        $this->assertSame('succeeded', WebhookDelivery::sole()->status);
        $this->assertSame(0, $endpoint->fresh()->consecutive_failures);
    }

    public function test_an_endpoint_that_keeps_failing_is_switched_off(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        $endpoint = $this->endpoint();
        $endpoint->update(['consecutive_failures' => WebhookEndpoint::DISABLE_AFTER - 1]);

        $delivery = app(Webhooks::class)->queue($endpoint, 'order.paid', ['x' => 1]);
        $delivery->update(['attempts' => count(Webhooks::BACKOFF)]);
        app(Webhooks::class)->attempt($delivery->fresh());

        $endpoint->refresh();
        $this->assertNotNull($endpoint->disabled_at);
        $this->assertStringContainsString((string) WebhookEndpoint::DISABLE_AFTER, $endpoint->disabled_reason);

        // And is no longer sent anything.
        $this->paidOrder();
        $this->assertSame(1, WebhookDelivery::count());
    }

    public function test_a_name_that_now_points_inside_is_refused_at_delivery(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $this->endpoint();

        // Public when saved; private by the time anything is sent.
        $this->dns['hooks.example.com'] = ['127.0.0.1'];

        $this->paidOrder();

        $delivery = WebhookDelivery::sole();
        $this->assertSame('failed', $delivery->status);
        $this->assertStringContainsString('not reachable', $delivery->response_excerpt);
        Http::assertNothingSent();
    }

    public function test_a_refund_is_announced(): void
    {
        Http::fake(['*' => Http::response('ok')]);

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('name')->andReturn('stripe');
        $gateway->shouldReceive('supports')->andReturn(true);
        $gateway->shouldReceive('refund')->andReturnUsing(
            fn (Order $order, int $amount) => new RefundResult(true, 're_test', $amount, $order->currency));
        $registry = new PaymentGatewayRegistry;
        $registry->register($gateway);
        $this->app->instance(PaymentGatewayRegistry::class, $registry);

        $order = $this->paidOrder(2);
        $this->endpoint(['order.refunded']);

        app(RefundService::class)->refund($order, reason: 'Could not come');

        $payload = WebhookDelivery::sole()->payload;
        $this->assertSame('order.refunded', $payload['event']);
        $this->assertSame(10000, $payload['data']['refund']['amount']['amount']);
        $this->assertSame(2, $payload['data']['refund']['tickets']);
        $this->assertSame('refunded', $payload['data']['status']);
    }

    public function test_a_check_in_is_announced_without_the_code(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $this->paidOrder();
        $this->endpoint(['ticket.checked_in']);

        $ticket = Ticket::sole();
        app(CheckInService::class)->scan($ticket->code, $this->event->id);

        $delivery = WebhookDelivery::sole();
        $this->assertSame('ticket.checked_in', $delivery->event);
        $this->assertSame($ticket->id, $delivery->payload['data']['id']);
        $this->assertSame(1, $delivery->payload['data']['admitted_now']);
        $this->assertStringNotContainsString($ticket->code, json_encode($delivery->payload));
    }

    public function test_no_ticket_code_is_ever_in_a_payload(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $this->endpoint();

        $this->paidOrder(3);

        $sent = json_encode(WebhookDelivery::pluck('payload'));

        foreach (Ticket::pluck('code') as $code) {
            $this->assertStringNotContainsString($code, $sent);
        }
    }

    public function test_old_deliveries_are_pruned(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $endpoint = $this->endpoint();

        app(Webhooks::class)->queue($endpoint, 'ping', []);
        $this->travel(WebhookDelivery::KEEP_DAYS + 1)->days();
        app(Webhooks::class)->queue($endpoint, 'ping', []);

        $this->artisan('webhooks:prune')->assertSuccessful();

        $this->assertSame(1, WebhookDelivery::count());
    }

    // --- managing them ------------------------------------------------------------

    public function test_an_owner_adds_an_endpoint_and_sees_the_secret_once(): void
    {
        $response = $this->postJson('/api/organizer/integrations/webhooks', [
            'url' => 'https://hooks.example.com/in',
            'events' => ['order.paid'],
            'description' => 'CRM',
        ])->assertCreated();

        $secret = $response->json('secret');
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertSame($secret, WebhookEndpoint::sole()->secret);

        // Encrypted at rest.
        $this->assertStringNotContainsString($secret, (string) \DB::table('webhook_endpoints')->value('secret'));

        $list = $this->getJson('/api/organizer/integrations')->assertOk();
        $this->assertStringNotContainsString($secret, $list->getContent());
        $list->assertJsonPath('endpoints.0.url', 'https://hooks.example.com/in');

        $this->assertTrue(AuditLog::where('action', 'webhook.created')->exists());
    }

    public function test_unsafe_addresses_are_refused_when_saved(): void
    {
        foreach ([
            'http://hooks.example.com/in',
            'https://localhost/in',
            'https://127.0.0.1/in',
            'https://10.1.2.3/in',
            'https://192.168.0.10/in',
            'https://[::1]/in',
            'https://metadata.example.com/latest',
            'https://sneaky.example.com/in',
            'https://user:pass@hooks.example.com/in',
            'https://nowhere.example.com/in',
            'not a url',
        ] as $url) {
            $this->postJson('/api/organizer/integrations/webhooks', ['url' => $url, 'events' => ['order.paid']])
                ->assertStatus(422);
        }

        $this->assertSame(0, WebhookEndpoint::count());
    }

    public function test_unknown_events_are_refused(): void
    {
        $this->postJson('/api/organizer/integrations/webhooks', [
            'url' => 'https://hooks.example.com/in',
            'events' => ['order.everything'],
        ])->assertStatus(422);
    }

    public function test_an_owner_can_switch_an_endpoint_off_and_back_on(): void
    {
        $endpoint = $this->endpoint();
        $endpoint->update(['disabled_at' => now(), 'disabled_reason' => 'Turned off after 25…', 'consecutive_failures' => 25]);

        $this->patchJson("/api/organizer/integrations/webhooks/{$endpoint->id}", ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.consecutive_failures', 0);

        $this->patchJson("/api/organizer/integrations/webhooks/{$endpoint->id}", ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);
    }

    public function test_a_test_delivery_can_be_sent_and_read_back(): void
    {
        Http::fake(['*' => Http::response('thanks', 202)]);
        $endpoint = $this->endpoint();

        $this->postJson("/api/organizer/integrations/webhooks/{$endpoint->id}/test")->assertCreated();

        $this->getJson("/api/organizer/integrations/webhooks/{$endpoint->id}/deliveries")
            ->assertOk()
            ->assertJsonPath('data.0.event', 'ping')
            ->assertJsonPath('data.0.status', 'succeeded')
            ->assertJsonPath('data.0.response_status', 202);
    }

    public function test_removing_an_endpoint_is_recorded(): void
    {
        $endpoint = $this->endpoint();

        $this->deleteJson("/api/organizer/integrations/webhooks/{$endpoint->id}")->assertOk();

        $this->assertSame(0, WebhookEndpoint::count());
        $this->assertTrue(AuditLog::where('action', 'webhook.removed')->exists());
    }

    public function test_only_an_owner_can_see_or_change_integrations(): void
    {
        $endpoint = $this->endpoint();

        foreach ([Role::Manager, Role::Finance, Role::Marketing] as $role) {
            $this->asOrganizer($this->member($role));

            $this->getJson('/api/organizer/integrations')->assertForbidden();
            $this->postJson('/api/organizer/integrations/keys', ['name' => 'x'])->assertForbidden();
            $this->deleteJson("/api/organizer/integrations/webhooks/{$endpoint->id}")->assertForbidden();
        }

        $this->assertSame(1, WebhookEndpoint::count());
        $this->assertSame(0, ApiKey::count());
    }

    public function test_another_organizations_endpoint_is_not_found(): void
    {
        $other = Organization::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $theirs = WebhookEndpoint::create([
            'organization_id' => $other->id,
            'url' => 'https://hooks.example.com/theirs',
            'secret' => 'whsec_x',
            'events' => ['order.paid'],
        ]);

        $this->deleteJson("/api/organizer/integrations/webhooks/{$theirs->id}")->assertNotFound();
        $this->getJson("/api/organizer/integrations/webhooks/{$theirs->id}/deliveries")->assertNotFound();
    }

    // --- keys ---------------------------------------------------------------------

    private function issueKey(): string
    {
        return $this->postJson('/api/organizer/integrations/keys', ['name' => 'Accounting'])
            ->assertCreated()
            ->json('key');
    }

    public function test_a_key_is_shown_once_and_stored_only_as_a_hash(): void
    {
        $plain = $this->issueKey();

        $this->assertStringStartsWith(ApiKey::PREFIX, $plain);

        $key = ApiKey::sole();
        $this->assertSame(substr($plain, -4), $key->last_four);
        $this->assertSame(hash('sha256', $plain), $key->token_hash);

        $list = $this->getJson('/api/organizer/integrations')->assertOk();
        $this->assertStringNotContainsString($plain, $list->getContent());
        $list->assertJsonPath('keys.0.last_four', $key->last_four);

        $this->assertTrue(AuditLog::where('action', 'api_key.created')->exists());
    }

    public function test_a_key_reads_its_own_organizations_events_orders_and_attendees(): void
    {
        $plain = $this->issueKey();
        $order = $this->paidOrder(2);
        $this->app['auth']->forgetGuards();

        $headers = ['Authorization' => "Bearer {$plain}"];

        $this->getJson('/api/v1/events', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->event->id);

        $orders = $this->getJson("/api/v1/events/{$this->event->id}/orders", $headers)
            ->assertOk()
            ->assertJsonPath('data.0.reference', $order->reference)
            ->assertJsonPath('next_cursor', null);

        $attendees = $this->getJson("/api/v1/events/{$this->event->id}/attendees", $headers)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        foreach (Ticket::pluck('code') as $code) {
            $this->assertStringNotContainsString($code, $orders->getContent());
            $this->assertStringNotContainsString($code, $attendees->getContent());
        }

        $this->assertNotNull(ApiKey::sole()->last_used_at);
    }

    public function test_since_returns_only_what_changed(): void
    {
        $plain = $this->issueKey();
        $this->paidOrder();
        $this->travel(1)->hours();
        $cutoff = now()->toIso8601String();
        $this->travel(1)->minutes();
        $later = $this->paidOrder();
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/events/{$this->event->id}/orders?since=".urlencode($cutoff), ['Authorization' => "Bearer {$plain}"])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $later->reference);
    }

    public function test_a_key_cannot_see_another_organizations_event(): void
    {
        $plain = $this->issueKey();
        $other = Organization::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $theirs = Event::create([
            'organization_id' => $other->id,
            'slug' => 'theirs',
            'city' => 'Toronto',
            'title' => 'Theirs',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);
        $this->app['auth']->forgetGuards();

        $headers = ['Authorization' => "Bearer {$plain}"];

        $this->getJson("/api/v1/events/{$theirs->id}/orders", $headers)->assertNotFound();
        $this->getJson('/api/v1/events/not-a-uuid/orders', $headers)->assertNotFound();
        $this->getJson('/api/v1/events', $headers)->assertJsonMissing(['id' => $theirs->id]);
    }

    public function test_a_revoked_or_wrong_key_is_refused(): void
    {
        $plain = $this->issueKey();
        $key = ApiKey::sole();

        $this->deleteJson("/api/organizer/integrations/keys/{$key->id}")->assertOk();
        $this->assertTrue(AuditLog::where('action', 'api_key.revoked')->exists());
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/events', ['Authorization' => "Bearer {$plain}"])->assertUnauthorized();
        $this->getJson('/api/v1/events', ['Authorization' => 'Bearer mf_live_nope'])->assertUnauthorized();
        $this->getJson('/api/v1/events')->assertUnauthorized();
    }

    public function test_a_persons_token_is_not_a_key(): void
    {
        $token = $this->owner->createToken('console', [TokenAbility::Organizer->value])->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/events', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_a_key_cannot_reach_the_console_api(): void
    {
        $plain = $this->issueKey();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/organizer/events', ['Authorization' => "Bearer {$plain}"])->assertUnauthorized();
    }
}
