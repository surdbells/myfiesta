<?php

namespace Tests\Feature;

use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Enums\Role;
use App\Mail\RefundMadeElsewhere;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessedWebhook;
use App\Models\Refund;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\CheckoutService;
use App\Services\Refunds\RefundService;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Refunds the processor tells us about.
 *
 * Every refund on the account is announced: the ones we asked for, and the ones
 * somebody made in the Stripe or Paystack dashboard. The announcement used to
 * set the order to refunded and stop there — a partial refund marked the whole
 * order refunded, and a dashboard refund left every ticket opening the door and
 * the organizer's balance counting money that had gone back.
 *
 * Driven through the signed webhooks, with payloads in the shapes the
 * processors send them, and the processors' APIs faked at the HTTP edge.
 */
class ProcessorRefundsTest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_SECRET = 'whsec_test_secret';

    private const PAYSTACK_SECRET = 'sk_test_paystack';

    private Organization $org;

    private Event $event;

    private TicketType $type;

    /** Whether the next refund requests of ours get no answer. */
    private bool $processorSilent = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxRateSeeder::class);

        config([
            'payments.stripe.webhook_secret' => self::STRIPE_SECRET,
            'payments.stripe.secret_key' => 'sk_test_stripe',
            'payments.paystack.secret_key' => self::PAYSTACK_SECRET,
        ]);

        $this->app->forgetInstance(PaymentGatewayRegistry::class);

        Mail::fake();
        Sleep::fake();

        Http::fake([
            'api.stripe.com/v1/refunds*' => fn (Request $request) => $this->processorSilent
                ? (Http::failedConnection())($request)
                : Http::response([
                    'id' => 're_'.Str::random(8),
                    'object' => 'refund',
                    'amount' => (int) $request['amount'],
                    'currency' => 'cad',
                    'status' => 'succeeded',
                ]),
            'api.paystack.co/refund*' => fn (Request $request) => $this->processorSilent
                ? (Http::failedConnection())($request)
                : Http::response([
                    'status' => true,
                    'message' => 'Refund has been queued for processing',
                    'data' => ['id' => 3018284, 'amount' => (int) $request['amount'], 'currency' => 'NGN', 'status' => 'pending'],
                ]),
            // Which checkout a payment was made on. Every order here opens
            // cs_<reference> and is paid as pi_<reference>.
            'api.stripe.com/v1/checkout/sessions*' => fn (Request $request) => Http::response([
                'object' => 'list',
                'data' => Order::where('reference', Str::after((string) $request['payment_intent'], 'pi_'))->exists()
                    ? [['id' => 'cs_'.Str::after((string) $request['payment_intent'], 'pi_'), 'object' => 'checkout.session']]
                    : [],
                'has_more' => false,
            ]),
        ]);

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
            'price_amount' => 10000,
            'status' => 'on_sale',
        ]);
    }

    private function member(Role $role, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    // --- Stripe ----------------------------------------------------------------------

    private function stripe(string $type, array $object, ?string $eventId = null): TestResponse
    {
        $payload = json_encode([
            'id' => $eventId ?? 'evt_'.Str::random(12),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, self::STRIPE_SECRET);

        return $this->call('POST', '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        );
    }

    /** Two tickets, paid for on Stripe's page. */
    private function stripeOrder(int $quantity = 2): Order
    {
        $order = app(CheckoutService::class)->reserve($this->event, [$this->type->id => $quantity], 'ada@example.com', 'Ada Okafor');
        $order->update(['gateway' => 'stripe', 'gateway_reference' => 'cs_'.$order->reference]);

        $this->stripe('checkout.session.completed', [
            'id' => 'cs_'.$order->reference,
            'object' => 'checkout.session',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_'.$order->reference,
            'amount_total' => $order->total_amount,
            'currency' => 'cad',
        ])->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->status);

        return $order;
    }

    /** Stripe's charge.refunded: everything refunded on the payment so far. */
    private function chargeRefunded(Order $order, int $refundedSoFar, ?string $eventId = null): TestResponse
    {
        return $this->stripe('charge.refunded', [
            'id' => 'ch_'.$order->reference,
            'object' => 'charge',
            'amount' => $order->total_amount,
            'amount_captured' => $order->total_amount,
            'amount_refunded' => $refundedSoFar,
            'currency' => 'cad',
            'payment_intent' => 'pi_'.$order->reference,
            'refunded' => $refundedSoFar >= $order->total_amount,
        ], $eventId);
    }

    /** One of Stripe's refund.* events, about a single refund. */
    private function stripeRefundEvent(string $type, Order $order, int $amount, string $status, array $metadata = []): TestResponse
    {
        return $this->stripe($type, [
            'id' => 're_'.Str::random(8),
            'object' => 'refund',
            'amount' => $amount,
            'charge' => 'ch_'.$order->reference,
            'currency' => 'cad',
            'metadata' => $metadata,
            'payment_intent' => 'pi_'.$order->reference,
            'reason' => null,
            'status' => $status,
        ]);
    }

    public function test_a_whole_order_refunded_in_the_stripe_dashboard_is_recorded_and_its_tickets_stop(): void
    {
        $manager = $this->member(Role::Manager, 'manager@example.com');
        $this->member(Role::Door, 'door@example.com');
        $order = $this->stripeOrder();

        $this->chargeRefunded($order, $order->total_amount)->assertOk();

        $order->refresh();
        $refund = $order->refunds()->sole();

        $this->assertSame(Refund::FROM_PROCESSOR, $refund->source);
        $this->assertSame('succeeded', $refund->status);
        $this->assertSame($order->total_amount, (int) $refund->amount);
        $this->assertSame($order->tax_amount, (int) $refund->tax_amount);
        $this->assertSame($order->service_charge_amount, (int) $refund->service_charge_amount);
        $this->assertNull($refund->issued_by);

        // The buyer has every penny back, so the door must stop letting them in.
        $this->assertSame('refunded', $order->status);
        $this->assertNotNull($order->refunded_at);
        $this->assertSame(2, $order->tickets()->where('status', 'refunded')->count());
        $this->assertSame(2, $refund->tickets()->count());

        // And the organizer's balance stops counting the sale.
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));

        $this->assertTrue(AuditLog::where('action', 'refund.made_elsewhere')->where('subject_id', $order->id)->exists());

        // Told: the people who can refund, not the door staff.
        Mail::assertQueued(RefundMadeElsewhere::class, fn (RefundMadeElsewhere $mail) => $mail->hasTo($manager->email)
            && $mail->wholeOrder
            && $mail->processor === 'Stripe');
        Mail::assertNotQueued(RefundMadeElsewhere::class, fn (RefundMadeElsewhere $mail) => $mail->hasTo('door@example.com'));
    }

    public function test_part_of_an_order_refunded_in_the_dashboard_is_recorded_without_guessing_tickets(): void
    {
        $this->member(Role::Finance, 'finance@example.com');
        $order = $this->stripeOrder();

        // A partial refund used to mark the whole order refunded while every
        // ticket still worked.
        $this->chargeRefunded($order, 5000)->assertOk();

        $order->refresh();
        $refund = $order->refunds()->sole();

        $this->assertSame(5000, (int) $refund->amount);
        $this->assertSame('partially_refunded', $order->status);

        // Stripe does not say which tickets it was for. A wrong guess turns
        // away somebody who paid, so none is touched and the organizer says.
        $this->assertSame(2, $order->tickets()->where('status', 'valid')->count());
        $this->assertSame(0, $refund->tickets()->count());

        // The money is off the balance all the same, in its proportions.
        $this->assertSame(
            $order->net_revenue_amount - (5000 - (int) $refund->service_charge_amount) + (int) $refund->tax_amount,
            (int) LedgerEntry::where('order_id', $order->id)->sum('amount'),
        );
        $this->assertGreaterThan(0, (int) $refund->tax_amount);

        Mail::assertQueued(RefundMadeElsewhere::class, fn (RefundMadeElsewhere $mail) => $mail->hasTo('finance@example.com')
            && ! $mail->wholeOrder);

        $html = (new RefundMadeElsewhere($order, $refund, false, 'Stripe'))->render();
        $this->assertStringContainsString('every ticket on it still works', $html);
        $this->assertStringContainsString('reply to this email', $html);
        $this->assertStringContainsString('do not', $html);
    }

    public function test_the_same_running_total_heard_twice_is_counted_once(): void
    {
        $order = $this->stripeOrder();

        $this->chargeRefunded($order, 5000, 'evt_first')->assertOk();
        // The same delivery again, and the same news under a new event id.
        $this->chargeRefunded($order, 5000, 'evt_first')->assertOk();
        $this->chargeRefunded($order, 5000, 'evt_second')->assertOk();

        $this->assertSame(1, $order->refunds()->count());
        $this->assertSame(5000, (int) $order->refunds()->sum('amount'));
        Mail::assertQueued(RefundMadeElsewhere::class, 0);
    }

    public function test_a_second_dashboard_refund_adds_only_the_difference(): void
    {
        $order = $this->stripeOrder();

        $this->chargeRefunded($order, 5000)->assertOk();
        $this->chargeRefunded($order, 12000)->assertOk();

        $this->assertSame([5000, 7000], $order->refunds()->orderBy('created_at')->orderBy('amount')->pluck('amount')->map(fn ($a) => (int) $a)->all());
        $this->assertSame('partially_refunded', $order->fresh()->status);

        // Then the rest, which is the whole of what was left: the tickets stop
        // and the ledger for the order comes to exactly nothing.
        $this->chargeRefunded($order, $order->total_amount)->assertOk();

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame($order->total_amount, (int) $order->refunds()->sum('amount'));
        $this->assertSame(2, $order->tickets()->where('status', 'refunded')->count());
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));
    }

    public function test_our_own_refund_announced_by_stripe_is_not_counted_twice(): void
    {
        $order = $this->stripeOrder();

        $ours = app(RefundService::class)->refund($order);
        $this->assertSame('succeeded', $ours->status);

        // Stripe announces it every way it has.
        $this->stripeRefundEvent('refund.created', $order, (int) $ours->amount, 'succeeded', ['refund_id' => $ours->id])->assertOk();
        $this->chargeRefunded($order, (int) $ours->amount)->assertOk();

        $this->assertSame(1, Refund::count());
        $this->assertNotNull($ours->fresh()->confirmed_at);
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));
        Mail::assertQueued(RefundMadeElsewhere::class, 0);
    }

    public function test_our_refund_still_being_sent_is_not_mistaken_for_one_made_elsewhere(): void
    {
        $order = $this->stripeOrder();

        // Our request reached Stripe; its answer did not reach us.
        $this->processorSilent = true;
        $ours = app(RefundService::class)->refund($order);
        $this->assertSame('pending', $ours->status);

        // The running total arrives first. The refund is ours and unfinished.
        $this->chargeRefunded($order, (int) $ours->amount)->assertOk();
        $this->assertSame(1, Refund::count());
        $this->assertSame('paid', $order->fresh()->status);

        // Then Stripe's word on the refund itself settles it.
        $this->stripeRefundEvent('refund.updated', $order, (int) $ours->amount, 'succeeded', ['refund_id' => $ours->id])->assertOk();

        $ours->refresh();
        $this->assertSame('succeeded', $ours->status);
        $this->assertNull($ours->failure_reason, 'Why it was waiting is cleared once it is done.');
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame(2, $order->tickets()->where('status', 'refunded')->count());
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));
        $this->assertTrue(AuditLog::where('action', 'refund.processed')->where('subject_id', $order->id)->exists());
    }

    public function test_stripe_saying_a_finished_refund_failed_is_raised_not_undone(): void
    {
        $order = $this->stripeOrder();
        $ours = app(RefundService::class)->refund($order);

        $this->stripeRefundEvent('refund.failed', $order, (int) $ours->amount, 'failed', ['refund_id' => $ours->id])->assertOk();

        // Un-voiding tickets and rewriting a ledger is a person's decision.
        $this->assertSame('succeeded', $ours->fresh()->status);
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'refund.failed_at_processor')->where('subject_id', $order->id)->exists());
    }

    public function test_a_single_refund_event_from_the_dashboard_is_left_to_the_running_total(): void
    {
        $order = $this->stripeOrder();

        // No tag of ours: counting it here and again from charge.refunded
        // would count it twice.
        $this->stripeRefundEvent('refund.created', $order, 5000, 'succeeded')->assertStatus(202);

        $this->assertSame(0, Refund::count());
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_a_refund_announced_before_the_payment_is_heard_again_after_it(): void
    {
        $order = app(CheckoutService::class)->reserve($this->event, [$this->type->id => 2], 'ada@example.com', 'Ada Okafor');
        $order->update(['gateway' => 'stripe', 'gateway_reference' => 'cs_'.$order->reference]);

        // The payment notice failed on its first delivery and Stripe will not
        // try it again for an hour. Meanwhile support refunds the buyer in the
        // dashboard, and that is announced first — naming a payment the order
        // has not been told of yet.
        $this->chargeRefunded($order, $order->total_amount, 'evt_refund')->assertStatus(409);

        // Not acknowledged, so not remembered: Stripe will send it again.
        $this->assertSame(0, Refund::count());
        $this->assertFalse(ProcessedWebhook::where('event_id', 'evt_refund')->exists());

        $this->stripe('checkout.session.completed', [
            'id' => 'cs_'.$order->reference,
            'object' => 'checkout.session',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_'.$order->reference,
            'amount_total' => $order->total_amount,
            'currency' => 'cad',
        ])->assertOk();

        // And when it does, there is a sale to read it against. It used to be
        // dropped as a notice for nobody, and the buyer kept their money back
        // and two tickets that opened the door.
        $this->chargeRefunded($order, $order->total_amount, 'evt_refund')->assertOk();

        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertSame(2, $order->tickets()->where('status', 'refunded')->count());
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));
    }

    public function test_a_refund_for_a_payment_that_is_not_ours_is_still_only_acknowledged(): void
    {
        // Stripe knows no checkout here for it: another product on the same
        // account, or the platform before this one.
        $this->stripe('charge.refunded', [
            'id' => 'ch_elsewhere',
            'object' => 'charge',
            'amount' => 5000,
            'amount_refunded' => 5000,
            'currency' => 'cad',
            'payment_intent' => 'pi_elsewhere',
        ])->assertStatus(202);

        $this->assertSame(0, Refund::count());
    }

    public function test_money_back_on_a_disputed_order_is_left_for_a_person(): void
    {
        $order = $this->stripeOrder();

        $this->stripe('charge.dispute.created', [
            'id' => 'dp_'.$order->reference,
            'object' => 'dispute',
            'amount' => $order->total_amount,
            'charge' => 'ch_'.$order->reference,
            'currency' => 'cad',
            'payment_intent' => 'pi_'.$order->reference,
            'reason' => 'fraudulent',
            'status' => 'needs_response',
        ])->assertOk();

        $this->chargeRefunded($order, $order->total_amount)->assertOk();

        // The dispute may already account for it; taking it off the balance
        // here as well could take it off twice.
        $this->assertSame(0, Refund::count());
        $this->assertSame(2, $order->tickets()->where('status', 'valid')->count());
        $this->assertTrue(AuditLog::where('action', 'refund.made_elsewhere_while_disputed')->where('subject_id', $order->id)->exists());
    }

    // --- Paystack --------------------------------------------------------------------

    private function paystack(string $event, array $data): TestResponse
    {
        $payload = json_encode(['event' => $event, 'data' => $data]);

        return $this->call('POST', '/webhooks/payments/paystack',
            server: ['HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, self::PAYSTACK_SECRET), 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        );
    }

    /** Two tickets in Lagos, paid through Paystack. */
    private function paystackOrder(): Order
    {
        $this->event->update(['currency' => 'NGN', 'country' => 'NG', 'subdivision' => null, 'timezone' => 'Africa/Lagos']);

        $order = app(CheckoutService::class)->reserve($this->event, [$this->type->id => 2], 'chidi@example.com', 'Chidi Obi');
        $order->update(['gateway' => 'paystack', 'gateway_reference' => 'PSK-'.$order->reference]);

        $this->paystack('charge.success', [
            'id' => 5550001,
            'domain' => 'live',
            'status' => 'success',
            'reference' => 'PSK-'.$order->reference,
            'amount' => $order->total_amount,
            'currency' => 'NGN',
            'channel' => 'bank_transfer',
        ])->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->status);

        return $order;
    }

    /** Paystack's refund.processed, as it sends it: the transaction by reference, no id of ours. */
    private function refundProcessed(Order $order, int $amount, string $refundReference = '132013318360', string $event = 'refund.processed'): TestResponse
    {
        return $this->paystack($event, [
            'status' => $event === 'refund.failed' ? 'failed' : 'processed',
            'transaction_reference' => $order->gateway_reference,
            'refund_reference' => $refundReference,
            'amount' => $amount,
            'currency' => 'NGN',
            'processor' => 'mastercard',
            'customer' => ['first_name' => 'Chidi', 'last_name' => 'Obi', 'email' => 'chidi@example.com'],
            'integration' => 463433,
            'domain' => 'live',
        ]);
    }

    public function test_a_paystack_dashboard_refund_is_found_by_its_transaction_and_recorded(): void
    {
        $this->member(Role::Owner, 'owner@example.com');
        $order = $this->paystackOrder();

        $this->refundProcessed($order, $order->total_amount)->assertOk();

        $order->refresh();
        $refund = $order->refunds()->sole();

        $this->assertSame(Refund::FROM_PROCESSOR, $refund->source);
        $this->assertSame('132013318360', $refund->gateway_reference);
        $this->assertSame('refunded', $order->status);
        $this->assertSame(2, $order->tickets()->where('status', 'refunded')->count());
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));

        Mail::assertQueued(RefundMadeElsewhere::class, fn (RefundMadeElsewhere $mail) => $mail->hasTo('owner@example.com')
            && $mail->processor === 'Paystack');
    }

    public function test_a_paystack_refund_notice_heard_twice_is_one_refund(): void
    {
        $order = $this->paystackOrder();

        $this->refundProcessed($order, 20000)->assertOk();
        $this->refundProcessed($order, 20000)->assertOk();

        // And once more after the record of deliveries has been swept: the
        // refund's own reference still finds the row it made.
        ProcessedWebhook::query()->delete();
        $this->refundProcessed($order, 20000)->assertOk();

        $this->assertSame(1, $order->refunds()->count());
        $this->assertSame(20000, (int) $order->refunds()->sum('amount'));
    }

    public function test_a_paystack_refund_announced_before_the_payment_waits_for_it(): void
    {
        $this->event->update(['currency' => 'NGN', 'country' => 'NG', 'subdivision' => null, 'timezone' => 'Africa/Lagos']);

        $order = app(CheckoutService::class)->reserve($this->event, [$this->type->id => 2], 'chidi@example.com', 'Chidi Obi');
        $order->update(['gateway' => 'paystack', 'gateway_reference' => 'PSK-'.$order->reference]);

        // Found by its transaction, but nothing here has been paid yet to
        // give back. Read now, it would be money back on a sale that has not
        // happened, and the payment arriving after it would issue tickets.
        $this->refundProcessed($order->refresh(), $order->total_amount)->assertStatus(409);
        $this->assertSame(0, Refund::count());
        $this->assertSame('pending', $order->fresh()->status);

        $this->paystack('charge.success', [
            'id' => 5550001,
            'status' => 'success',
            'reference' => 'PSK-'.$order->reference,
            'amount' => $order->total_amount,
            'currency' => 'NGN',
        ])->assertOk();

        $this->refundProcessed($order, $order->total_amount)->assertOk();

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame(2, $order->tickets()->where('status', 'refunded')->count());
    }

    public function test_two_different_paystack_refunds_of_the_same_amount_are_two(): void
    {
        $order = $this->paystackOrder();

        $this->refundProcessed($order, 20000, 'RR-1')->assertOk();
        $this->refundProcessed($order, 20000, 'RR-2')->assertOk();

        $this->assertSame(2, $order->refunds()->count());
    }

    public function test_our_own_paystack_refund_is_matched_not_recorded_again(): void
    {
        $order = $this->paystackOrder();

        $ours = app(RefundService::class)->refund($order);
        $this->assertSame('succeeded', $ours->status);

        // Paystack's notice names neither our row nor the id it answered with.
        $this->refundProcessed($order, (int) $ours->amount)->assertOk();

        $this->assertSame(1, Refund::count());
        $this->assertNotNull($ours->fresh()->confirmed_at);
        Mail::assertQueued(RefundMadeElsewhere::class, 0);
    }

    public function test_a_paystack_refund_of_ours_that_got_no_answer_is_settled_by_its_notice(): void
    {
        $order = $this->paystackOrder();

        $this->processorSilent = true;
        $ours = app(RefundService::class)->refund($order);
        $this->assertSame('pending', $ours->status);

        $this->refundProcessed($order, (int) $ours->amount)->assertOk();

        $this->assertSame('succeeded', $ours->fresh()->status);
        $this->assertSame(1, Refund::count());
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame(2, $order->tickets()->where('status', 'refunded')->count());
    }

    public function test_a_paystack_failure_that_names_nothing_of_ours_does_not_fail_ours(): void
    {
        $order = $this->paystackOrder();

        $this->processorSilent = true;
        $ours = app(RefundService::class)->refund($order);

        // A failed refund for the same amount could be anybody's. Read as
        // ours, it would say "failed" about a refund that may have paid.
        $this->refundProcessed($order, (int) $ours->amount, event: 'refund.failed')->assertOk();

        $this->assertSame('pending', $ours->fresh()->status);
        $this->assertSame(1, Refund::count());
    }

    public function test_a_paystack_failure_that_could_be_a_refund_we_recorded_as_done_is_raised(): void
    {
        $order = $this->paystackOrder();

        // Paystack says yes when it queues a refund, so ours is done as far
        // as anything here knows: tickets stopped, the balance reduced.
        $ours = app(RefundService::class)->refund($order);
        $this->assertSame('succeeded', $ours->status);

        // Then the bank turns it down. The notice names neither our row nor
        // the id Paystack answered us with.
        $this->refundProcessed($order, (int) $ours->amount, 'RR-bounced', 'refund.failed')->assertOk();

        // Not undone on a guess by amount — but no longer dropped without a
        // word, which left a buyer unpaid and nobody knowing.
        $this->assertSame('succeeded', $ours->fresh()->status);
        $this->assertSame('refunded', $order->fresh()->status);

        $audit = AuditLog::where('action', 'refund.failed_at_processor')->where('subject_id', $order->id)->sole();
        $this->assertSame($ours->id, $audit->metadata['refund_id']);
        $this->assertSame('amount', $audit->metadata['matched_by']);
        $this->assertSame('RR-bounced', $audit->metadata['processor_reference']);
    }

    public function test_a_paystack_failure_that_matches_nothing_of_ours_is_only_logged(): void
    {
        $order = $this->paystackOrder();

        // A refund somebody tried in the dashboard, which moved no money.
        $this->refundProcessed($order, 5000, 'RR-dashboard', 'refund.failed')->assertOk();

        $this->assertSame(0, Refund::count());
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertFalse(AuditLog::where('action', 'refund.failed_at_processor')->exists());
    }

    public function test_a_refund_notice_for_another_processors_order_finds_nothing(): void
    {
        $order = $this->stripeOrder();

        // Signed by Paystack, naming a Stripe order's reference.
        $this->paystack('refund.processed', [
            'status' => 'processed',
            'transaction_reference' => 'pi_'.$order->reference,
            'refund_reference' => 'RR-x',
            'amount' => 5000,
            'currency' => 'CAD',
        ])->assertStatus(202);

        $this->assertSame(0, Refund::count());
        $this->assertSame('paid', $order->fresh()->status);
    }
}
