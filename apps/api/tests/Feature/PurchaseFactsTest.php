<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Order;
use App\Models\User;
use App\Services\PersonalData\Eraser;
use App\Services\PersonalData\Exporter;
use App\Services\PersonalData\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SellsTicketsForDisputes;
use Tests\TestCase;

/**
 * Where an online order came from.
 *
 * The one thing added to what is kept about a buyer: the address the order
 * came from and the browser that sent it, on the order, to catch fraud and to
 * answer a bank. Believed only as far as the proxies in front are trusted —
 * an address the client wrote into a header is the client's claim, and a
 * bank shown it would be shown whatever a fraudster chose.
 */
class PurchaseFactsTest extends TestCase
{
    use RefreshDatabase, SellsTicketsForDisputes;

    private const BALANCER = '10.0.0.5';

    private const BUYER = '198.51.100.23';

    private const STRANGER = '203.0.113.7';

    private const BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcessors();

        config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
    }

    public function test_an_online_order_keeps_the_buyers_address_as_the_balancer_reports_it_and_their_browser(): void
    {
        [$event, $type] = $this->night();

        $order = $this->buy($event, $type,
            server: ['REMOTE_ADDR' => self::BALANCER],
            headers: ['X-Forwarded-For' => self::BUYER, 'User-Agent' => self::BROWSER],
        );

        $this->assertSame(self::BUYER, $order->purchase_ip);
        $this->assertSame(self::BROWSER, $order->purchase_user_agent);
    }

    public function test_an_address_the_buyer_wrote_into_a_header_is_not_believed(): void
    {
        [$event, $type] = $this->night();

        // Not from the balancer, so the header is only the client's say-so.
        $order = $this->buy($event, $type,
            server: ['REMOTE_ADDR' => self::STRANGER],
            headers: ['X-Forwarded-For' => '192.0.2.99', 'User-Agent' => self::BROWSER],
        );

        $this->assertSame(self::STRANGER, $order->purchase_ip);

        // And an entry the client put in front of the balancer's own is not
        // the client either: the last hop the balancer saw is.
        $second = $this->buy($event, $type,
            server: ['REMOTE_ADDR' => self::BALANCER],
            headers: ['X-Forwarded-For' => '192.0.2.99, '.self::BUYER],
        );

        $this->assertSame(self::BUYER, $second->purchase_ip);
    }

    public function test_a_naira_order_keeps_them_too(): void
    {
        [$event, $type] = $this->night('NGN');

        $order = $this->buy($event, $type,
            server: ['REMOTE_ADDR' => self::BALANCER],
            headers: ['X-Forwarded-For' => self::BUYER, 'User-Agent' => self::BROWSER],
        );

        $this->assertSame('paystack', $order->gateway);
        $this->assertSame(self::BUYER, $order->purchase_ip);
    }

    public function test_a_browser_that_writes_an_essay_is_cut_to_what_the_column_keeps(): void
    {
        [$event, $type] = $this->night();

        $order = $this->buy($event, $type, headers: ['User-Agent' => str_repeat('Mozilla/5.0 ', 100)]);

        $this->assertSame(512, mb_strlen((string) $order->purchase_user_agent));
    }

    public function test_a_door_sale_keeps_neither(): void
    {
        [$event, $type] = $this->night();

        $owner = User::factory()->create();
        $event->organization->members()->attach($owner->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Owner->value,
            'accepted_at' => now(),
        ]);
        Sanctum::actingAs($owner->fresh(), [TokenAbility::Attendee->value, TokenAbility::Organizer->value]);

        // The request is the organizer's phone at the door, and says nothing
        // about the person handing over cash.
        $reference = $this->withServerVariables(['REMOTE_ADDR' => self::BALANCER])
            ->withHeaders(['X-Forwarded-For' => self::BUYER, 'User-Agent' => self::BROWSER])
            ->postJson("/api/events/{$event->id}/door-sales", [
                'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
                'method' => 'cash',
            ])
            ->assertCreated()
            ->json('reference');

        $order = Order::where('reference', $reference)->firstOrFail();

        $this->assertSame('door', $order->channel);
        $this->assertNull($order->purchase_ip);
        $this->assertNull($order->purchase_user_agent);
    }

    public function test_a_privacy_export_shows_them_and_an_erasure_clears_them(): void
    {
        [$event, $type] = $this->night();

        $order = $this->buy($event, $type,
            server: ['REMOTE_ADDR' => self::BALANCER],
            headers: ['X-Forwarded-For' => self::BUYER, 'User-Agent' => self::BROWSER],
        );

        $export = app(Exporter::class)->build(Subject::forEmail('ada@example.com'));
        $this->assertSame(self::BUYER, $export['data']['orders'][0]['purchase_ip']);
        $this->assertSame(self::BROWSER, $export['data']['orders'][0]['purchase_user_agent']);

        app(Eraser::class)->erase(Subject::forEmail('ada@example.com'));

        // Blanked the way the rest of the buyer is: null where the row takes
        // it, or the eraser's placeholder where the row insists on an address.
        $order->refresh();
        $this->assertContains($order->purchase_ip, [null, 'Erased']);
        $this->assertContains($order->purchase_user_agent, [null, 'Erased']);
        // The order itself stays: it is a financial record.
        $this->assertSame(5000 * 2, $order->subtotal_amount);
    }
}
