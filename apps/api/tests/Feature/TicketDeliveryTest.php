<?php

namespace Tests\Feature;

use App\Mail\TicketsIssued;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\TicketType;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\Fulfiller;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Getting tickets to the buyer, and letting them back in later.
 */
class TicketDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxRateSeeder::class);
        Mail::fake();
    }

    private function paidOrder(): Order
    {
        $org = Organization::create(['name' => 'N', 'slug' => 'n-'.uniqid()]);

        $event = Event::create([
            'organization_id' => $org->id,
            'slug' => 'e-'.uniqid(),
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $type = TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);

        $order = app(CheckoutService::class)
            ->reserve($event, [$type->id => 2], 'buyer@example.com', 'Ada Buyer');

        return app(Fulfiller::class)->fulfil($order);
    }

    public function test_tickets_are_emailed_once_payment_lands(): void
    {
        $order = $this->paidOrder();

        Mail::assertQueued(TicketsIssued::class, fn ($mail) => $mail->hasTo('buyer@example.com')
            && $mail->order->is($order));
    }

    public function test_a_retried_webhook_does_not_email_twice(): void
    {
        $order = $this->paidOrder();

        app(Fulfiller::class)->fulfil($order);
        app(Fulfiller::class)->fulfil($order);

        // Three tickets in an inbox for one purchase reads as three purchases.
        Mail::assertQueuedCount(1);
    }

    public function test_a_signed_link_shows_the_order(): void
    {
        $order = $this->paidOrder();

        $url = URL::temporarySignedRoute('orders.show', now()->addDays(90), ['order' => $order->id]);

        $this->get($url)
            ->assertOk()
            ->assertJsonPath('reference', $order->reference)
            ->assertJsonCount(2, 'tickets');
    }

    public function test_an_unsigned_link_is_refused(): void
    {
        $order = $this->paidOrder();

        // The previous platform served this at /tickets/{sale_id} with no
        // signature, so anyone could read anyone else's tickets by counting.
        $this->get("/orders/{$order->id}")->assertForbidden();
    }

    public function test_a_tampered_link_is_refused(): void
    {
        $order = $this->paidOrder();
        $other = $this->paidOrder();

        $url = URL::temporarySignedRoute('orders.show', now()->addDays(90), ['order' => $order->id]);
        $swapped = str_replace($order->id, $other->id, $url);

        $this->get($swapped)->assertForbidden();
    }

    public function test_an_expired_link_is_refused(): void
    {
        $order = $this->paidOrder();

        $url = URL::temporarySignedRoute('orders.show', now()->addDay(), ['order' => $order->id]);

        $this->travel(2)->days();

        $this->get($url)->assertForbidden();
    }
}
