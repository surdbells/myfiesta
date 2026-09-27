<?php

namespace Tests\Concerns;

use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\TicketType;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A night on sale, bought through the real checkout and paid by a signed
 * notice, with the processors answering from Http::fake and never for real.
 *
 * The keys are made up per test rather than written down: a key-shaped string
 * in the repository is one a secret scanner has to be argued with.
 */
trait SellsTicketsForDisputes
{
    protected string $stripeWebhookSecret;

    protected string $paystackSecret;

    /** @var array<string, callable(ClientRequest): mixed> what each processor URL answers, by pattern */
    protected array $processor = [];

    /** @var list<ClientRequest> */
    protected array $sentToProcessor = [];

    protected function setUpProcessors(): void
    {
        $this->seed(TaxRateSeeder::class);

        $this->stripeWebhookSecret = 'whsec_'.Str::random(32);
        $this->paystackSecret = implode('_', ['sk', 'test', Str::random(32)]);

        config([
            'payments.stripe.secret_key' => implode('_', ['sk', 'test', Str::random(32)]),
            'payments.stripe.webhook_secret' => $this->stripeWebhookSecret,
            'payments.paystack.secret_key' => $this->paystackSecret,
            'app.public_url' => 'https://myfiesta.test',
        ]);

        $this->app->forgetInstance(PaymentGatewayRegistry::class);

        // One fake for every test, answering from $processor so each test
        // can say what the processor answers without stacking fakes.
        $this->processor = [
            'api.stripe.com/v1/checkout/sessions' => fn (ClientRequest $request) => $request->method() === 'POST'
                ? Http::response([
                    'id' => 'cs_test_'.Str::random(24),
                    'object' => 'checkout.session',
                    'url' => 'https://checkout.stripe.com/c/pay/'.Str::random(16),
                    'expires_at' => now()->addMinutes(30)->getTimestamp(),
                ])
                : Http::response(['error' => ['message' => 'Not faked.']], 404),
            'api.paystack.co/transaction/initialize' => fn () => Http::response([
                'status' => true,
                'data' => [
                    'reference' => 'PSK'.Str::upper(Str::random(12)),
                    'authorization_url' => 'https://checkout.paystack.com/'.Str::random(12),
                ],
            ]),
        ];

        Http::fake(function (ClientRequest $request) {
            $this->sentToProcessor[] = $request;

            $path = (string) preg_replace('#^https?://#', '', explode('?', $request->url())[0]);

            foreach ($this->processor as $pattern => $answer) {
                if (Str::is($pattern, $path)) {
                    return $answer($request);
                }
            }

            return Http::response(['error' => ['message' => "Nothing is faked for {$path}."]], 500);
        });
    }

    /**
     * An organizer's night on sale, and its one kind of ticket.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{0: Event, 1: TicketType}
     */
    protected function night(string $currency = 'CAD', array $overrides = []): array
    {
        $org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights-'.Str::lower(Str::random(5))]);

        $event = Event::create(array_merge([
            'organization_id' => $org->id,
            'slug' => 'afro-fest-'.Str::lower(Str::random(5)),
            'title' => 'Afro Fest',
            'currency' => $currency,
            'starts_at' => now()->addMonth(),
            'timezone' => $currency === 'NGN' ? 'Africa/Lagos' : 'America/Toronto',
            'city' => $currency === 'NGN' ? 'Lagos' : 'Toronto',
            'subdivision' => $currency === 'NGN' ? null : 'ON',
            'country' => $currency === 'NGN' ? 'NG' : 'CA',
            'status' => 'published',
            'published_at' => now(),
        ], $overrides));

        $type = TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);

        return [$event, $type];
    }

    /**
     * Buy through the public checkout, as a browser would.
     *
     * @param  array<string, string>  $server  what the connection says, REMOTE_ADDR among it
     * @param  array<string, string>  $headers
     */
    protected function checkout(Event $event, TicketType $type, array $server = [], array $headers = [], int $quantity = 2): TestResponse
    {
        return $this->withServerVariables($server)
            ->withHeaders($headers)
            ->postJson("/api/events/{$event->slug}/orders", [
                'items' => [['ticket_type_id' => $type->id, 'quantity' => $quantity]],
                'buyer' => ['name' => 'Ada Okafor', 'email' => 'ada@example.com'],
                'accept_terms' => true,
            ]);
    }

    protected function buy(Event $event, TicketType $type, array $server = [], array $headers = [], int $quantity = 2): Order
    {
        $reference = $this->checkout($event, $type, $server, $headers, $quantity)->assertCreated()->json('reference');

        return Order::where('reference', $reference)->firstOrFail();
    }

    /**
     * Stripe saying the checkout was paid, signed as Stripe signs it.
     *
     * @param  array<string, mixed>  $session  anything else the session says
     */
    protected function stripePaid(Order $order, string $intent, array $session = []): TestResponse
    {
        $payload = json_encode([
            'id' => 'evt_'.Str::random(24),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => array_merge([
                'id' => $order->gateway_reference,
                'object' => 'checkout.session',
                'payment_status' => 'paid',
                'status' => 'complete',
                'amount_total' => $order->total_amount,
                'currency' => strtolower($order->currency),
                'payment_intent' => $intent,
                'consent' => null,
                'consent_collection' => null,
            ], $session)],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $this->stripeWebhookSecret);

        return $this->call('POST', '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        );
    }

    /** Paystack saying the transaction succeeded, signed as Paystack signs it. */
    protected function paystackPaid(Order $order, int $transactionId): TestResponse
    {
        $payload = json_encode([
            'event' => 'charge.success',
            'data' => [
                'id' => $transactionId,
                'domain' => 'test',
                'status' => 'success',
                'reference' => $order->gateway_reference,
                'amount' => $order->total_amount,
                'currency' => 'NGN',
            ],
        ]);

        return $this->call('POST', '/webhooks/payments/paystack',
            server: [
                'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, $this->paystackSecret),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $payload,
        );
    }

    /**
     * A PaymentIntent as Stripe's API reference shows one, on an API version
     * that names its charge rather than listing it.
     *
     * @return array<string, mixed>
     */
    protected function stripeIntent(string $id, string $charge, int $amount, string $currency = 'cad'): array
    {
        return [
            'id' => $id,
            'object' => 'payment_intent',
            'amount' => $amount,
            'amount_capturable' => 0,
            'amount_received' => $amount,
            'capture_method' => 'automatic_async',
            'confirmation_method' => 'automatic',
            'created' => 1790500000,
            'currency' => $currency,
            'customer' => null,
            'latest_charge' => $charge,
            'livemode' => false,
            'metadata' => [],
            'payment_method' => 'pm_'.Str::random(24),
            'payment_method_types' => ['card'],
            'receipt_email' => null,
            'status' => 'succeeded',
        ];
    }

    /**
     * A Charge as Stripe's API reference shows one, paid by a card whose bank
     * checked it was the cardholder.
     *
     * With two things Stripe never sends, to show they would not be kept if
     * it did: the card's whole number, and nothing else that is one.
     *
     * @return array<string, mixed>
     */
    protected function stripeCharge(string $id, string $intent, int $amount, string $currency = 'cad'): array
    {
        return [
            'id' => $id,
            'object' => 'charge',
            'amount' => $amount,
            'amount_captured' => $amount,
            'amount_refunded' => 0,
            'balance_transaction' => 'txn_'.Str::random(24),
            'billing_details' => [
                'address' => ['city' => 'Toronto', 'country' => 'CA', 'line1' => '1 Queen St W', 'line2' => null, 'postal_code' => 'M5H 2N2', 'state' => 'ON'],
                'email' => 'ada@example.com',
                'name' => 'Ada Okafor',
                'phone' => null,
            ],
            'calculated_statement_descriptor' => 'MYFIESTA* AFRO FEST',
            'captured' => true,
            'created' => 1790500010,
            'currency' => $currency,
            'disputed' => false,
            'livemode' => false,
            'outcome' => [
                'network_status' => 'approved_by_network',
                'reason' => null,
                'risk_level' => 'normal',
                'risk_score' => 32,
                'seller_message' => 'Payment complete.',
                'type' => 'authorized',
            ],
            'paid' => true,
            'payment_intent' => $intent,
            'payment_method' => 'pm_'.Str::random(24),
            'payment_method_details' => [
                'card' => [
                    'amount_authorized' => $amount,
                    'authorization_code' => '123456',
                    'brand' => 'visa',
                    'checks' => [
                        'address_line1_check' => null,
                        'address_postal_code_check' => 'pass',
                        'cvc_check' => 'pass',
                    ],
                    'country' => 'CA',
                    'exp_month' => 3,
                    'exp_year' => 2030,
                    'fingerprint' => 'mToisGZ01V71BCos',
                    'funding' => 'credit',
                    'last4' => '4242',
                    'network' => 'visa',
                    'number' => str_repeat('4242', 4),
                    'three_d_secure' => [
                        'authentication_flow' => 'challenge',
                        'electronic_commerce_indicator' => '05',
                        'exemption_indicator' => null,
                        'result' => 'authenticated',
                        'result_reason' => null,
                        'transaction_id' => (string) Str::uuid(),
                        'version' => '2.2.0',
                    ],
                    'wallet' => null,
                ],
                'type' => 'card',
            ],
            'receipt_email' => 'ada@example.com',
            'receipt_number' => null,
            'receipt_url' => 'https://pay.stripe.com/receipts/payment/'.Str::random(40),
            'refunded' => false,
            'status' => 'succeeded',
        ];
    }
}
