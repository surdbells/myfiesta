<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\InventoryHold;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\PaymentEvidence;
use App\Models\Refund;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\AbandonedCheckouts;
use App\Services\Events\Copying\PayLater as CarriedPayLater;
use App\Services\Events\EventCanceller;
use App\Services\Impersonation\Impersonation;
use App\Services\Impersonation\WhileImpersonating;
use App\Services\Payments\GatewayFee;
use App\Services\Refunds\RefundRefused;
use App\Services\Refunds\RefundService;
use App\Services\Resale\Resale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\SellsTicketsForDisputes;
use Tests\TestCase;

/**
 * Buy now, pay later: Klarna and Affirm on Stripe's page, for a night whose
 * organizer opted in, and payments that settle after the page has closed.
 *
 * Bought through the real checkout and paid by signed notices, with Stripe
 * answering from Http::fake, so what is checked is what Stripe would be
 * asked and what its answers would do.
 */
class PayLaterTest extends TestCase
{
    use RefreshDatabase, SellsTicketsForDisputes;

    private const STANDARD = 'pmc_standard_cards_wallets_link';

    private const PAY_LATER = 'pmc_pay_later_klarna_affirm';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcessors();

        Mail::fake();

        config([
            'payments.stripe.payment_method_configurations' => [
                'standard' => self::STANDARD,
                'pay_later' => self::PAY_LATER,
            ],
            'payments.pay_later.enabled' => true,
            'payments.pay_later.max_days_before_event' => 110,
        ]);

        // Stripe takes a refund for what it was asked; asked what it has
        // refunded, it has refunded nothing.
        $this->processor['api.stripe.com/v1/refunds'] = fn (ClientRequest $request) => $request->method() === 'GET'
            ? Http::response(['object' => 'list', 'data' => [], 'has_more' => false])
            : Http::response(['id' => 're_'.Str::random(14), 'amount' => (int) $request['amount'], 'currency' => 'cad', 'status' => 'succeeded']);
    }

    /**
     * A night its organizer opted in, a month away.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{0: Event, 1: TicketType}
     */
    private function optedIn(array $overrides = []): array
    {
        return $this->night('CAD', ['pay_later_enabled' => true] + $overrides);
    }

    /** @return array<string, mixed> the body of the last checkout session Stripe was asked to open */
    private function lastSession(): array
    {
        $sent = collect($this->sentToProcessor)
            ->filter(fn (ClientRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v1/checkout/sessions'))
            ->last();

        $this->assertNotNull($sent, 'No checkout session was opened.');

        return $sent->data();
    }

    /**
     * Stripe saying something about a checkout session, signed as it signs.
     *
     * @param  array<string, mixed>  $session
     */
    private function stripeSays(string $type, Order $order, array $session = []): TestResponse
    {
        $payload = json_encode([
            'id' => 'evt_'.Str::random(24),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => array_merge([
                'id' => $order->gateway_reference,
                'object' => 'checkout.session',
                'status' => 'complete',
                'amount_total' => $order->total_amount,
                'currency' => strtolower($order->currency),
                'payment_intent' => 'pi_'.$order->reference,
                'metadata' => ['order_id' => $order->id, 'reference' => $order->reference],
            ], $session)],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $this->stripeWebhookSecret);

        return $this->call('POST', '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        );
    }

    private function actAs(Event $event, Role $role): User
    {
        $user = User::factory()->create();
        $event->organization->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => $role->value, 'accepted_at' => now()]);
        Sanctum::actingAs($user->fresh()->load('organizations'), [TokenAbility::Organizer->value]);

        return $user;
    }

    // --- which page the buyer is sent to ------------------------------------

    public function test_a_night_the_organizer_opted_in_is_paid_on_the_page_with_klarna_and_affirm(): void
    {
        [$event, $type] = $this->optedIn();
        $this->buy($event, $type);

        $session = $this->lastSession();

        $this->assertSame(self::PAY_LATER, $session['payment_method_configuration']);
        $this->assertSame('offered', $session['metadata']['pay_later']);
    }

    public function test_a_night_not_opted_in_gets_cards_wallets_and_link(): void
    {
        [$event, $type] = $this->night();
        $this->buy($event, $type);

        $session = $this->lastSession();

        $this->assertSame(self::STANDARD, $session['payment_method_configuration']);
        $this->assertArrayNotHasKey('pay_later', $session['metadata']);
    }

    public function test_a_night_further_off_than_the_refund_window_allows_is_not_offered_it(): void
    {
        [$event, $type] = $this->optedIn(['starts_at' => now()->addDays(111)]);
        $this->buy($event, $type);

        $this->assertSame(self::STANDARD, $this->lastSession()['payment_method_configuration']);

        // And a night just inside it is.
        [$near, $nearType] = $this->optedIn(['starts_at' => now()->addDays(109)]);
        $this->buy($near, $nearType);

        $this->assertSame(self::PAY_LATER, $this->lastSession()['payment_method_configuration']);
    }

    public function test_a_night_in_another_currency_is_not_offered_it(): void
    {
        [$event, $type] = $this->optedIn(['currency' => 'USD']);
        $this->buy($event, $type);

        $this->assertSame(self::STANDARD, $this->lastSession()['payment_method_configuration']);
    }

    public function test_nobody_is_offered_it_while_myfiesta_has_it_switched_off(): void
    {
        config(['payments.pay_later.enabled' => false]);

        [$event, $type] = $this->optedIn();
        $this->buy($event, $type);

        $this->assertSame(self::STANDARD, $this->lastSession()['payment_method_configuration']);
    }

    public function test_with_no_configurations_set_the_page_is_what_it_always_was(): void
    {
        config(['payments.stripe.payment_method_configurations' => ['standard' => null, 'pay_later' => null]]);

        [$event, $type] = $this->optedIn();
        $this->buy($event, $type);

        $session = $this->lastSession();

        $this->assertArrayNotHasKey('payment_method_configuration', $session);
        $this->assertArrayNotHasKey('pay_later', $session['metadata']);
    }

    /**
     * With only the standard configuration made, Stripe's page offers cards,
     * and nothing before it may say Klarna or Affirm.
     */
    public function test_without_its_configuration_nobody_is_told_they_can_pay_later(): void
    {
        config(['payments.stripe.payment_method_configurations.pay_later' => null]);

        [$event, $type] = $this->optedIn();
        $this->actAs($event, Role::Manager);

        $this->getJson("/api/events/{$event->slug}")->assertOk()->assertJsonPath('data.pay_later', null);
        $this->postJson("/api/events/{$event->slug}/quote", [
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 2]],
        ])->assertOk()->assertJsonPath('pay_later', null);

        $this->getJson("/api/organizer/events/{$event->id}/pay-later")->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('offered_now', false);
        $this->putJson("/api/organizer/events/{$event->id}/pay-later", ['enabled' => true])->assertStatus(422);

        $this->buy($event, $type);
        $this->assertSame(self::STANDARD, $this->lastSession()['payment_method_configuration']);
    }

    // --- payments that clear after the page has closed ----------------------

    public function test_a_payment_that_clears_later_issues_the_tickets_when_it_does(): void
    {
        [$event, $type] = $this->optedIn();
        $order = $this->buy($event, $type);

        // The page completed with nothing taken yet: nothing is issued.
        $this->stripeSays('checkout.session.completed', $order, ['payment_status' => 'unpaid'])->assertStatus(202);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $order->tickets()->count());

        $this->stripeSays('checkout.session.async_payment_succeeded', $order, ['payment_status' => 'paid'])->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame(2, $order->tickets()->count());
        $this->assertSame('pi_'.$order->reference, $order->gateway_payment_reference);
        $this->assertSame(0, InventoryHold::where('order_id', $order->id)->count());
        $this->assertSame(
            $order->net_revenue_amount,
            (int) LedgerEntry::where('order_id', $order->id)->sum('amount'),
        );
    }

    public function test_a_payment_that_fails_later_closes_the_order_and_lets_its_places_go(): void
    {
        [$event, $type] = $this->optedIn();
        $order = $this->buy($event, $type);

        $this->assertGreaterThan(0, InventoryHold::where('order_id', $order->id)->count());

        $this->stripeSays('checkout.session.async_payment_failed', $order, ['payment_status' => 'unpaid'])->assertOk();

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(0, $order->tickets()->count());
        $this->assertSame(0, InventoryHold::where('order_id', $order->id)->count());

        // A payment that clears after all is for a person to look at, not
        // tickets issued over the failure.
        $this->stripeSays('checkout.session.async_payment_succeeded', $order, ['payment_status' => 'paid'])->assertOk();
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(0, $order->tickets()->count());
    }

    public function test_a_payment_that_clears_after_its_hold_ran_out_goes_through_the_late_path(): void
    {
        [$event, $type] = $this->optedIn();
        $order = $this->buy($event, $type);

        $this->stripeSays('checkout.session.completed', $order, ['payment_status' => 'unpaid'])->assertStatus(202);

        // Long enough for the basket to be closed as abandoned.
        $this->travel(3)->hours();
        app(AbandonedCheckouts::class)->expire();
        $this->assertSame('cancelled', $order->fresh()->status);

        $this->stripeSays('checkout.session.async_payment_succeeded', $order, ['payment_status' => 'paid'])->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(2, $order->tickets()->count());
        $this->assertSame(0, Refund::where('order_id', $order->id)->count());
    }

    public function test_a_payment_that_clears_after_its_places_were_sold_is_sent_back(): void
    {
        [$event, $type] = $this->optedIn();
        $type->update(['quantity_available' => 2]);
        $order = $this->buy($event, $type);

        $this->stripeSays('checkout.session.completed', $order, ['payment_status' => 'unpaid'])->assertStatus(202);

        $this->travel(3)->hours();
        app(AbandonedCheckouts::class)->expire();

        // Somebody else bought the two places meanwhile, and paid on time.
        $other = $this->buy($event, $type);
        $this->stripePaid($other, 'pi_'.$other->reference)->assertOk();
        $this->assertSame('paid', $other->fresh()->status);

        $this->stripeSays('checkout.session.async_payment_succeeded', $order, ['payment_status' => 'paid'])->assertOk();

        $this->assertSame(0, $order->tickets()->count());
        $this->assertSame(1, Refund::where('order_id', $order->id)->where('status', 'succeeded')->count());
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)->count());
    }

    // --- what it cost, and who pays for it -----------------------------------

    /**
     * An order paid on Stripe's page with this method, Stripe ready to say so
     * with the fee it took, and the processor's record of it collected.
     */
    private function paidWith(Event $event, TicketType $type, string $method, ?int $fee, bool $offered = true): Order
    {
        $order = $this->buy($event, $type);
        $pi = 'pi_'.$order->reference;
        $ch = 'ch_'.Str::random(24);

        $charge = $this->stripeCharge($ch, $pi, $order->total_amount);
        $charge['payment_method_details'] = $method === 'card'
            ? $charge['payment_method_details']
            : ['type' => $method, $method => ['payment_method_category' => 'pay_in_installments']];
        $charge['balance_transaction'] = $fee === null
            ? 'txn_'.Str::random(24)
            : ['id' => 'txn_'.Str::random(24), 'object' => 'balance_transaction', 'amount' => $order->total_amount, 'fee' => $fee, 'currency' => 'cad'];

        $this->processor['api.stripe.com/v1/payment_intents/*'] = fn () => Http::response($this->stripeIntent($pi, $ch, $order->total_amount));
        $this->processor['api.stripe.com/v1/charges/*'] = fn () => Http::response($charge);

        $metadata = ['order_id' => $order->id, 'reference' => $order->reference] + ($offered ? ['pay_later' => 'offered'] : []);
        $this->stripeSays('checkout.session.completed', $order, ['payment_status' => 'paid', 'metadata' => $metadata])->assertOk();

        $this->artisan('disputes:collect-evidence')->assertSuccessful();

        return $order->refresh();
    }

    private function cardFeeOn(Order $order): int
    {
        return GatewayFee::on($order->total, 'stripe')->amount;
    }

    public function test_how_it_was_paid_and_what_stripe_took_are_recorded_and_the_organizer_pays_the_difference(): void
    {
        [$event, $type] = $this->optedIn();
        $order = $this->paidWith($event, $type, 'klarna', 755);

        $evidence = PaymentEvidence::where('order_id', $order->id)->sole();
        $this->assertSame('klarna', $evidence->method_type);
        $this->assertSame(755, (int) $evidence->fee_amount);

        $premium = 755 - $this->cardFeeOn($order);
        $this->assertGreaterThan(0, $premium);

        // The order keeps the platform's part, which is what its reports
        // set against the service charge; the organizer paid the rest.
        $this->assertSame(755 - $premium, $order->gateway_fee_amount);
        $this->assertSame($this->cardFeeOn($order), $order->gateway_fee_amount);

        $adjustment = LedgerEntry::where('order_id', $order->id)->where('type', 'adjustment')->sole();
        $this->assertSame(-$premium, (int) $adjustment->amount);
        $this->assertSame('CAD', $adjustment->currency);
        $this->assertStringContainsString('Klarna', (string) $adjustment->reason);

        // Asked of Stripe with the fee in the answer.
        $this->assertTrue(collect($this->sentToProcessor)->contains(
            fn (ClientRequest $request) => str_contains($request->url(), '/v1/charges/')
                && str_contains(urldecode($request->url()), 'expand[0]=balance_transaction'),
        ));

        // Collected again, nothing is written twice.
        $this->artisan('disputes:collect-evidence')->assertSuccessful();
        $this->assertSame(1, LedgerEntry::where('order_id', $order->id)->where('type', 'adjustment')->count());
    }

    public function test_without_stripes_figure_the_lenders_published_rate_stands_in(): void
    {
        [$event, $type] = $this->optedIn();
        $order = $this->paidWith($event, $type, 'affirm', null);

        $expected = GatewayFee::on($order->total, 'stripe:affirm')->amount;

        $this->assertSame('affirm', PaymentEvidence::where('order_id', $order->id)->sole()->method_type);
        $this->assertSame($expected, (int) PaymentEvidence::where('order_id', $order->id)->sole()->fee_amount);
        $this->assertSame($this->cardFeeOn($order), $order->gateway_fee_amount);
        $this->assertSame(
            -($expected - $this->cardFeeOn($order)),
            (int) LedgerEntry::where('order_id', $order->id)->where('type', 'adjustment')->sole()->amount,
        );
    }

    public function test_a_card_costs_the_organizer_nothing_and_its_fee_becomes_stripes_own(): void
    {
        [$event, $type] = $this->optedIn();
        $order = $this->paidWith($event, $type, 'card', 401);

        $this->assertSame('card', PaymentEvidence::where('order_id', $order->id)->sole()->method_type);
        $this->assertSame(401, $order->gateway_fee_amount);
        $this->assertSame(0, LedgerEntry::where('order_id', $order->id)->where('type', 'adjustment')->count());
        $this->assertSame($order->net_revenue_amount, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));
    }

    public function test_a_lender_on_a_page_that_did_not_offer_it_is_not_the_organizers_to_pay_for(): void
    {
        [$event, $type] = $this->night();
        $order = $this->paidWith($event, $type, 'klarna', 755, offered: false);

        $this->assertSame(755, $order->gateway_fee_amount);
        $this->assertSame(0, LedgerEntry::where('order_id', $order->id)->where('type', 'adjustment')->count());
    }

    // --- refunds, and how long the lenders take them --------------------------

    public function test_a_refund_after_the_lenders_window_is_refused_with_what_to_do_instead(): void
    {
        [$event, $type] = $this->optedIn();
        $order = $this->paidWith($event, $type, 'klarna', 755);

        $this->travel(181)->days();

        try {
            app(RefundService::class)->refund($order->fresh());
            $this->fail('A Klarna payment 181 days old was refunded.');
        } catch (RefundRefused $e) {
            $this->assertStringContainsString('Klarna takes money back only within 180 days', $e->getMessage());
            $this->assertStringContainsString('myFiesta support', $e->getMessage());
        }

        $this->assertSame(0, Refund::where('order_id', $order->id)->count());
        $this->assertFalse(collect($this->sentToProcessor)->contains(
            fn (ClientRequest $request) => $request->method() === 'POST' && str_contains($request->url(), '/v1/refunds'),
        ));
    }

    public function test_the_console_shows_the_refusal_in_its_own_words(): void
    {
        [$event, $type] = $this->optedIn();
        $order = $this->paidWith($event, $type, 'affirm', 760);

        $this->travel(121)->days();
        $this->actAs($event, Role::Finance);

        $this->postJson("/api/organizer/events/{$event->id}/orders/{$order->id}/refunds", [])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Affirm takes money back only within 120 days'));
    }

    public function test_within_the_window_a_lenders_payment_is_refunded_as_any_other(): void
    {
        [$event, $type] = $this->optedIn();
        $order = $this->paidWith($event, $type, 'klarna', 755);

        // Past Affirm's window, still inside Klarna's.
        $this->travel(121)->days();

        $refund = app(RefundService::class)->refund($order->fresh());

        $this->assertSame('succeeded', $refund->status);
        $this->assertSame('refunded', $order->fresh()->status);
    }

    public function test_cancelling_says_beforehand_how_many_orders_support_must_return_another_way(): void
    {
        [$event, $type] = $this->optedIn(['starts_at' => now()->addMonths(8)]);
        $old = $this->paidWith($event, $type, 'affirm', 760);

        $this->travel(125)->days();
        $this->paidWith($event, $type, 'affirm', 760);
        $this->paidWith($event, $type, 'card', 401);

        $preview = app(EventCanceller::class)->preview($event->fresh());

        $this->assertSame(3, $preview['orders_to_refund']);
        $this->assertSame(1, $preview['orders_to_refund_elsewhere']);
        $this->assertNotNull($old->paid_at);
    }

    public function test_cancelling_leaves_a_payment_past_the_lenders_window_for_support_and_says_so(): void
    {
        [$event, $type] = $this->optedIn(['starts_at' => now()->addMonths(8)]);
        $old = $this->paidWith($event, $type, 'affirm', 760);

        $this->travel(125)->days();
        $card = $this->paidWith($event, $type, 'card', 401);

        $owner = $this->actAs($event, Role::Owner);

        $response = $this->postJson("/api/organizer/events/{$event->id}/cancel", [
            'reason' => 'The venue flooded and cannot open.',
        ])->assertOk();

        // Its own count, not with the refunds the organizer could send by
        // hand: one by hand would be refused the same way.
        $this->assertSame(1, $response->json('refunded'));
        $this->assertSame(0, $response->json('failed'));
        $this->assertSame(1, $response->json('left_for_support'));
        $this->assertStringContainsString('1 paid with Klarna or Affirm too long ago', (string) $response->json('message'));
        $this->assertStringContainsString('write to myFiesta support', (string) $response->json('message'));
        $this->assertStringNotContainsString('refund those by hand', (string) $response->json('message'));

        $this->assertSame('refunded', $card->fresh()->status);
        $this->assertSame('paid', $old->fresh()->status);
        $this->assertSame(0, Refund::where('order_id', $old->id)->count());

        // On the order's own trail, for support to find.
        $marker = AuditLog::where('action', 'refund.left_for_support')->sole();
        $this->assertSame($old->id, $marker->subject_id);
        $this->assertSame($owner->id, $marker->actor_id);
        $this->assertSame('affirm', $marker->metadata['method']);
    }

    /** Stripe saying something about anything, signed as it signs. */
    private function stripeEvent(string $type, array $object): TestResponse
    {
        $payload = json_encode(['id' => 'evt_'.Str::random(24), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $this->stripeWebhookSecret);

        return $this->call('POST', '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        );
    }

    public function test_the_organizer_keeps_paying_the_lenders_fee_whether_the_money_left_by_refund_or_chargeback(): void
    {
        [$event, $type] = $this->optedIn();
        $refunded = $this->paidWith($event, $type, 'klarna', 755);
        $disputed = $this->paidWith($event, $type, 'klarna', 755);
        $premium = 755 - $this->cardFeeOn($disputed);

        app(RefundService::class)->refund($refunded->fresh());
        $this->assertSame(-$premium, (int) LedgerEntry::where('order_id', $refunded->id)->sum('amount'));

        $dispute = [
            'id' => 'dp_'.Str::random(14),
            'payment_intent' => $disputed->gateway_payment_reference,
            'amount' => $disputed->total_amount,
            'currency' => 'cad',
            'reason' => 'fraudulent',
            'evidence_details' => ['due_by' => now()->addDays(7)->getTimestamp()],
        ];
        $this->stripeEvent('charge.dispute.created', $dispute)->assertOk();
        $this->stripeEvent('charge.dispute.closed', ['status' => 'lost'] + $dispute)->assertOk();

        $this->assertSame(1, LedgerEntry::where('order_id', $disputed->id)->where('type', 'chargeback')->count());
        $this->assertSame(-$premium, (int) LedgerEntry::where('order_id', $disputed->id)->sum('amount'));
    }

    public function test_the_money_summary_shows_what_paying_later_cost_beside_what_is_owed(): void
    {
        [$event, $type] = $this->optedIn();
        $order = $this->paidWith($event, $type, 'klarna', 755);
        $premium = 755 - $this->cardFeeOn($order);
        $this->actAs($event, Role::Owner);

        $summary = $this->getJson("/api/organizer/events/{$event->id}/summary")->assertOk()
            ->assertJsonPath('adjustments', ['amount' => -$premium, 'currency' => 'CAD']);

        // Every line that makes up what is owed is on the page.
        $this->assertSame(
            $summary->json('net.amount'),
            $summary->json('gross.amount') - $summary->json('discounts.amount') - $summary->json('tax.amount')
                - $summary->json('refunds.amount') + $summary->json('adjustments.amount'),
        );
    }

    public function test_a_ticket_paid_later_is_not_handed_back_when_the_lender_closes_before_the_night(): void
    {
        [$event, $type] = $this->optedIn(['starts_at' => now()->addDays(100), 'resale_enabled' => true]);
        $order = $this->paidWith($event, $type, 'affirm', 760);
        $ticket = $order->tickets()->firstOrFail();

        $this->assertNull(app(Resale::class)->refusal($ticket, $event->fresh()));

        // Moved further out than Affirm's 120 days after it sold.
        $event->update(['starts_at' => now()->addDays(300), 'ends_at' => now()->addDays(300)->addHours(5)]);

        $this->assertStringContainsString(
            'paid for with Affirm, which can no longer take the money back before this event',
            (string) app(Resale::class)->refusal($ticket->fresh(), $event->fresh()),
        );
    }

    public function test_staff_acting_as_the_organization_can_turn_paying_later_off_but_not_on(): void
    {
        [$event] = $this->night();
        $support = User::factory()->create(['platform_role' => PlatformRole::Support, 'email_verified_at' => now()]);
        $code = app(Impersonation::class)->start($event->organization, $support, 'Ticket #12: checkout questions')['code'];
        $token = $this->postJson('/api/impersonation/exchange', ['code' => $code])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();
        $as = fn () => $this->withToken($token)->withHeader('X-Organization', $event->organization_id);

        $as()->putJson("/api/organizer/events/{$event->id}/pay-later", ['enabled' => true])
            ->assertForbidden()
            ->assertJsonPath('message', WhileImpersonating::PAY_LATER);
        $this->assertFalse((bool) $event->fresh()->pay_later_enabled);

        $as()->putJson("/api/organizer/events/{$event->id}/pay-later", ['enabled' => false])->assertOk();
    }

    public function test_a_refused_refund_is_written_on_the_orders_trail_whoever_asked(): void
    {
        [$event, $type] = $this->optedIn();
        $order = $this->paidWith($event, $type, 'klarna', 755);

        $this->travel(181)->days();
        $finance = $this->actAs($event, Role::Finance);

        $this->postJson("/api/organizer/events/{$event->id}/orders/{$order->id}/refunds", [])->assertStatus(422);

        $marker = AuditLog::where('action', 'refund.left_for_support')->sole();
        $this->assertSame($order->id, $marker->subject_id);
        $this->assertSame($finance->id, $marker->actor_id);
        $this->assertSame('klarna', $marker->metadata['method']);
    }

    // --- what the buyer is told before the payment page ------------------------

    public function test_the_event_page_names_the_lenders_only_where_paying_later_is_offered(): void
    {
        [$event] = $this->optedIn();
        [$plain] = $this->night();
        [$far] = $this->optedIn(['starts_at' => now()->addDays(111)]);

        $this->getJson("/api/events/{$event->slug}")->assertOk()
            ->assertJsonPath('data.pay_later', ['providers' => ['klarna', 'affirm']]);
        $this->getJson("/api/events/{$plain->slug}")->assertOk()->assertJsonPath('data.pay_later', null);
        $this->getJson("/api/events/{$far->slug}")->assertOk()->assertJsonPath('data.pay_later', null);
    }

    public function test_a_quote_says_whether_this_basket_can_be_paid_later_and_with_whom(): void
    {
        [$event, $type] = $this->optedIn();
        $quote = fn (int $quantity) => $this->postJson("/api/events/{$event->slug}/quote", [
            'items' => [['ticket_type_id' => $type->id, 'quantity' => $quantity]],
        ])->assertOk();

        // C$100 and change: both lenders take it.
        $quote(2)->assertJsonPath('pay_later', ['eligible' => true, 'providers' => ['klarna', 'affirm']]);

        // Over Klarna's C$1,500: Affirm alone.
        $quote(40)->assertJsonPath('pay_later', ['eligible' => true, 'providers' => ['affirm']]);

        // Under Affirm's C$50: Klarna alone.
        $type->update(['price_amount' => 2000]);
        $quote(1)->assertJsonPath('pay_later', ['eligible' => true, 'providers' => ['klarna']]);

        // A night not opted in says nothing about it.
        [$plain, $plainType] = $this->night();
        $this->postJson("/api/events/{$plain->slug}/quote", [
            'items' => [['ticket_type_id' => $plainType->id, 'quantity' => 2]],
        ])->assertOk()->assertJsonPath('pay_later', null);
    }

    public function test_no_quote_offers_it_while_myfiesta_has_it_switched_off(): void
    {
        config(['payments.pay_later.enabled' => false]);
        [$event, $type] = $this->optedIn();

        $this->postJson("/api/events/{$event->slug}/quote", [
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 2]],
        ])->assertOk()->assertJsonPath('pay_later', null);
    }

    // --- the organizer's opt-in ---------------------------------------------

    public function test_the_console_reads_the_opt_in_with_what_each_lender_charges_over_a_card(): void
    {
        [$event] = $this->night();
        $this->actAs($event, Role::Manager);

        $this->getJson("/api/organizer/events/{$event->id}/pay-later")->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('available', true)
            ->assertJsonPath('offered_now', false)
            ->assertJsonPath('max_days_before_event', 110)
            ->assertJsonPath('currency', 'CAD')
            ->assertJsonPath('fees.card', ['bps' => 290, 'flat' => 30])
            ->assertJsonPath('fees.klarna', ['bps' => 599, 'flat' => 30])
            ->assertJsonPath('fees.affirm', ['bps' => 600, 'flat' => 30]);

        $this->getJson("/api/organizer/events/{$event->id}")->assertOk()->assertJsonPath('pay_later_enabled', false);
    }

    public function test_an_organizer_opts_a_night_in_and_out_and_both_are_recorded(): void
    {
        [$event] = $this->night();
        $user = $this->actAs($event, Role::Manager);

        $this->putJson("/api/organizer/events/{$event->id}/pay-later", ['enabled' => true])->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('offered_now', true);

        $this->assertTrue((bool) $event->fresh()->pay_later_enabled);
        $this->getJson("/api/organizer/events/{$event->id}")->assertOk()->assertJsonPath('pay_later_enabled', true);
        $this->assertTrue(AuditLog::where('action', 'event.pay_later_on')->where('actor_id', $user->id)->exists());

        // Saying it again changes nothing and records nothing.
        $this->putJson("/api/organizer/events/{$event->id}/pay-later", ['enabled' => true])->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'event.pay_later_on')->count());

        $this->putJson("/api/organizer/events/{$event->id}/pay-later", ['enabled' => false])->assertOk()
            ->assertJsonPath('enabled', false);

        $this->assertFalse((bool) $event->fresh()->pay_later_enabled);
        $this->assertTrue(AuditLog::where('action', 'event.pay_later_off')->exists());
    }

    public function test_it_cannot_be_turned_on_where_myfiesta_does_not_offer_it_but_can_always_be_turned_off(): void
    {
        [$dollars] = $this->night('USD');
        $this->actAs($dollars, Role::Owner);

        $this->putJson("/api/organizer/events/{$dollars->id}/pay-later", ['enabled' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Paying later is only for nights priced in Canadian dollars.');

        [$event] = $this->optedIn();
        $this->actAs($event, Role::Owner);
        config(['payments.pay_later.enabled' => false]);

        $this->putJson("/api/organizer/events/{$event->id}/pay-later", ['enabled' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Paying later is switched off on myFiesta at the moment.');

        $this->putJson("/api/organizer/events/{$event->id}/pay-later", ['enabled' => false])->assertOk();
        $this->assertFalse((bool) $event->fresh()->pay_later_enabled);

        $this->putJson("/api/organizer/events/{$event->id}/pay-later", [])->assertStatus(422)->assertJsonValidationErrors('enabled');
    }

    public function test_only_somebody_who_can_edit_the_night_can_change_it(): void
    {
        [$event] = $this->night();

        foreach ([Role::Finance, Role::Marketing, Role::Door] as $role) {
            $this->actAs($event, $role);
            $this->putJson("/api/organizer/events/{$event->id}/pay-later", ['enabled' => true])->assertForbidden();
        }

        // Nor anybody from another organization.
        [$theirs] = $this->night();
        $this->actAs($theirs, Role::Owner);
        $this->putJson("/api/organizer/events/{$event->id}/pay-later", ['enabled' => true])->assertForbidden();
        $this->getJson("/api/organizer/events/{$event->id}/pay-later")->assertForbidden();

        $this->assertFalse((bool) $event->fresh()->pay_later_enabled);
    }

    public function test_a_copy_or_the_next_date_keeps_the_opt_in(): void
    {
        [$source] = $this->optedIn();
        [$copy] = $this->night();

        app(CarriedPayLater::class)->carry($source->fresh(), $copy);
        $this->assertTrue((bool) $copy->fresh()->pay_later_enabled);

        [$plain] = $this->night();
        app(CarriedPayLater::class)->carry($plain->fresh(), $copy);
        $this->assertFalse((bool) $copy->fresh()->pay_later_enabled);
    }

    // --- the contract -----------------------------------------------------------

    public function test_what_is_said_about_paying_later_is_what_the_contract_promises(): void
    {
        $schemas = Yaml::parseFile(base_path('../../packages/contract/openapi.yaml'))['components']['schemas'];
        $declared = fn (string $schema) => array_keys($schemas[$schema]['properties']);

        [$event, $type] = $this->optedIn();
        $this->actAs($event, Role::Owner);

        $setting = $this->getJson("/api/organizer/events/{$event->id}/pay-later")->assertOk()->json();
        $this->assertSame($declared('PayLaterSetting'), array_keys($setting));
        $this->assertSame($schemas['PayLaterSetting']['properties']['fees']['required'], array_keys($setting['fees']));
        $this->assertSame($declared('PayLaterRate'), array_keys($setting['fees']['card']));

        $onPage = $this->getJson("/api/events/{$event->slug}")->assertOk()->json('data.pay_later');
        $this->assertSame($declared('PayLater'), array_keys($onPage));

        $onQuote = $this->postJson("/api/events/{$event->slug}/quote", [
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 2]],
        ])->assertOk()->json('pay_later');
        $this->assertSame($declared('PayLaterQuote'), array_keys($onQuote));

        foreach (array_merge($onPage['providers'], $onQuote['providers']) as $provider) {
            $this->assertContains($provider, $schemas['PayLaterProvider']['enum']);
        }
    }
}
