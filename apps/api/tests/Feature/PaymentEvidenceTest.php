<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentEvidence;
use App\Models\Ticket;
use App\Services\Checkout\Fulfiller;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\SellsTicketsForDisputes;
use Tests\TestCase;

/**
 * The processor's own record of a payment, kept for the dispute that may come.
 *
 * Asked for after the payment notice and never during it — a processor slow to
 * answer a second question must not be why somebody's tickets are late — then
 * kept as the processor said it, reduced to what a bank weighs, and fixed.
 */
class PaymentEvidenceTest extends TestCase
{
    use RefreshDatabase, SellsTicketsForDisputes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcessors();
    }

    /** A paid Stripe order, with Stripe ready to describe its payment. */
    private function paidByStripe(?array $intent = null): array
    {
        [$event, $type] = $this->night();
        $order = $this->buy($event, $type);

        $pi = 'pi_'.Str::random(24);
        $ch = 'ch_'.Str::random(24);

        $this->processor['api.stripe.com/v1/payment_intents/*'] = fn () => Http::response(
            $intent ?? $this->stripeIntent($pi, $ch, $order->total_amount),
        );
        $this->processor['api.stripe.com/v1/charges/*'] = fn () => Http::response(
            $this->stripeCharge($ch, $pi, $order->total_amount),
        );

        $this->stripePaid($order, $pi)->assertOk();

        return [$order->refresh(), $pi, $ch];
    }

    private function asked(string $path): int
    {
        return collect($this->sentToProcessor)
            ->filter(fn (ClientRequest $request) => str_contains($request->url(), $path))
            ->count();
    }

    public function test_stripes_record_of_the_payment_is_kept_in_the_words_a_bank_weighs(): void
    {
        [$order, $pi, $ch] = $this->paidByStripe();

        $this->artisan('disputes:collect-evidence')->assertSuccessful();

        $evidence = PaymentEvidence::where('order_id', $order->id)->sole();
        $facts = $evidence->facts;

        $this->assertSame(PaymentEvidence::CAPTURED, $evidence->status);
        $this->assertSame($pi, $evidence->payment_reference);
        $this->assertNotNull($evidence->captured_at);

        $this->assertSame('stripe', $facts['processor']);
        $this->assertSame($pi, $facts['payment_intent']['id']);
        $this->assertSame('succeeded', $facts['payment_intent']['status']);
        $this->assertSame($order->total_amount, $facts['charge']['amount']);
        $this->assertSame($ch, $facts['charge']['id']);
        $this->assertSame('CAD', $facts['charge']['currency']);
        $this->assertSame('2026-09-27T09:06:50+00:00', $facts['charge']['created']);
        $this->assertSame('MYFIESTA* AFRO FEST', $facts['charge']['statement_descriptor']);

        // Whether the card's bank checked it was the cardholder.
        $this->assertSame('authenticated', $facts['charge']['card']['three_d_secure']['result']);
        $this->assertSame('2.2.0', $facts['charge']['card']['three_d_secure']['version']);
        $this->assertSame('challenge', $facts['charge']['card']['three_d_secure']['authentication_flow']);
        $this->assertSame('05', $facts['charge']['card']['three_d_secure']['electronic_commerce_indicator']);

        // What the network and Stripe's fraud checks made of it.
        $this->assertSame('approved_by_network', $facts['charge']['outcome']['network_status']);
        $this->assertSame('authorized', $facts['charge']['outcome']['type']);
        $this->assertSame('normal', $facts['charge']['outcome']['risk_level']);
        $this->assertSame(32, $facts['charge']['outcome']['risk_score']);
        $this->assertSame('pass', $facts['charge']['card']['checks']['cvc_check']);
        $this->assertSame('pass', $facts['charge']['card']['checks']['address_postal_code_check']);
        $this->assertNull($facts['charge']['card']['checks']['address_line1_check']);

        // Which card, without its number.
        $this->assertSame('visa', $facts['charge']['card']['brand']);
        $this->assertSame('4242', $facts['charge']['card']['last4']);
        $this->assertSame('mToisGZ01V71BCos', $facts['charge']['card']['fingerprint']);
        $this->assertNull($facts['charge']['card']['wallet']);
        $this->assertSame('ada@example.com', $evidence->receipt_email);

        $this->assertFalse($facts['livemode']);
    }

    public function test_nothing_that_is_a_card_number_or_the_buyers_address_is_kept(): void
    {
        [$order] = $this->paidByStripe();

        $this->artisan('disputes:collect-evidence')->assertSuccessful();

        $row = (string) json_encode(DB::table('payment_evidence')->where('order_id', $order->id)->first());

        $this->assertStringNotContainsString(str_repeat('4242', 4), $row);
        $this->assertStringNotContainsString('authorization_code', $row);
        $this->assertStringNotContainsString('billing_details', $row);
        $this->assertStringNotContainsString('Queen St', $row);
        $this->assertStringNotContainsString('exp_year', $row);
    }

    public function test_an_older_stripe_api_version_that_lists_the_charge_on_the_payment_is_read_too(): void
    {
        [$event, $type] = $this->night();
        $order = $this->buy($event, $type);

        $pi = 'pi_'.Str::random(24);
        $ch = 'ch_'.Str::random(24);

        $intent = $this->stripeIntent($pi, $ch, $order->total_amount);
        unset($intent['latest_charge']);
        $intent['charges'] = ['object' => 'list', 'data' => [$this->stripeCharge($ch, $pi, $order->total_amount)]];

        $this->processor['api.stripe.com/v1/payment_intents/*'] = fn () => Http::response($intent);

        $this->stripePaid($order, $pi)->assertOk();
        $this->artisan('disputes:collect-evidence')->assertSuccessful();

        $evidence = PaymentEvidence::where('order_id', $order->id)->sole();

        $this->assertSame(PaymentEvidence::CAPTURED, $evidence->status);
        $this->assertSame($ch, $evidence->facts['charge']['id']);
        $this->assertSame(0, $this->asked('/v1/charges/'));
    }

    public function test_the_payment_notice_only_leaves_a_note_and_never_asks_the_processor_itself(): void
    {
        [$order] = $this->paidByStripe();

        // The tickets are out before anything is asked.
        $this->assertSame('paid', $order->status);
        $this->assertSame(2, Ticket::where('order_id', $order->id)->count());
        $this->assertSame(0, $this->asked('/v1/payment_intents/'));

        $note = PaymentEvidence::where('order_id', $order->id)->sole();
        $this->assertSame(PaymentEvidence::PENDING, $note->status);
        $this->assertSame(0, $note->attempts);
    }

    public function test_a_processor_that_cannot_answer_holds_up_nothing_and_is_asked_again_until_it_is_given_up_on(): void
    {
        [$event, $type] = $this->night();
        $order = $this->buy($event, $type);

        $this->processor['api.stripe.com/v1/payment_intents/*'] = fn () => Http::response(['error' => ['message' => 'Stripe is having a moment.']], 500);

        $this->stripePaid($order, 'pi_'.Str::random(24))->assertOk();

        // Fulfilment is exactly what it would have been.
        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame(2, Ticket::where('order_id', $order->id)->where('status', 'valid')->count());

        $this->artisan('disputes:collect-evidence')->assertSuccessful();

        $evidence = PaymentEvidence::where('order_id', $order->id)->sole();
        $this->assertSame(PaymentEvidence::PENDING, $evidence->status);
        $this->assertSame(1, $evidence->attempts);
        $this->assertStringContainsString('500', (string) $evidence->last_error);
        $this->assertTrue($evidence->next_attempt_at->between(now()->addMinutes(4), now()->addMinutes(6)));

        // Not due yet: nothing is asked.
        $this->artisan('disputes:collect-evidence')->assertSuccessful();
        $this->assertSame(1, $this->asked('/v1/payment_intents/'));

        // Every gap in turn, and then it is left for a person.
        foreach (config('disputes.evidence.retry_after_minutes') as $gap) {
            $this->travel($gap + 1)->minutes();
            $this->artisan('disputes:collect-evidence')->assertSuccessful();
        }

        $evidence->refresh();
        $this->assertSame(PaymentEvidence::GAVE_UP, $evidence->status);
        $this->assertSame(6, $evidence->attempts);
        $this->assertSame(6, $this->asked('/v1/payment_intents/'));

        $this->travel(2)->days();
        $this->artisan('disputes:collect-evidence')->assertSuccessful();
        $this->assertSame(6, $this->asked('/v1/payment_intents/'));

        // And still nothing about the order moved.
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(2, Ticket::where('order_id', $order->id)->where('status', 'valid')->count());
    }

    public function test_a_processor_that_answers_later_is_kept_then(): void
    {
        [$event, $type] = $this->night();
        $order = $this->buy($event, $type);

        $pi = 'pi_'.Str::random(24);
        $ch = 'ch_'.Str::random(24);
        $up = false;

        $this->processor['api.stripe.com/v1/payment_intents/*'] = function () use (&$up, $pi, $ch, $order) {
            return $up
                ? Http::response($this->stripeIntent($pi, $ch, $order->total_amount))
                : Http::response(['error' => ['message' => 'Timed out.']], 503);
        };
        $this->processor['api.stripe.com/v1/charges/*'] = fn () => Http::response($this->stripeCharge($ch, $pi, $order->total_amount));

        $this->stripePaid($order, $pi)->assertOk();
        $this->artisan('disputes:collect-evidence')->assertSuccessful();

        $up = true;
        $this->travel(6)->minutes();
        $this->artisan('disputes:collect-evidence')->assertSuccessful();

        $evidence = PaymentEvidence::where('order_id', $order->id)->sole();
        $this->assertSame(PaymentEvidence::CAPTURED, $evidence->status);
        $this->assertSame(2, $evidence->attempts);
        $this->assertNull($evidence->last_error);
    }

    public function test_once_kept_the_record_cannot_be_changed_or_deleted(): void
    {
        [$order] = $this->paidByStripe();
        $this->artisan('disputes:collect-evidence')->assertSuccessful();

        foreach ([
            fn () => DB::table('payment_evidence')->where('order_id', $order->id)->update(['facts' => json_encode(['result' => 'forged'])]),
            fn () => DB::table('payment_evidence')->where('order_id', $order->id)->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('The database let a kept payment record be changed.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('payment_evidence', $e->getMessage());
            }
        }

        $this->assertSame('authenticated', PaymentEvidence::where('order_id', $order->id)->sole()->facts['charge']['card']['three_d_secure']['result']);
    }

    public function test_stripes_own_terms_box_is_kept_as_its_signed_notice_said_it(): void
    {
        [$event, $type] = $this->night();
        $order = $this->buy($event, $type);

        $this->stripePaid($order, 'pi_'.Str::random(24), [
            'consent_collection' => ['terms_of_service' => 'required', 'promotions' => 'none'],
            'consent' => ['terms_of_service' => 'accepted', 'promotions' => null],
        ])->assertOk();

        $checkout = PaymentEvidence::where('order_id', $order->id)->sole()->checkout;

        $this->assertSame($order->gateway_reference, $checkout['session']);
        $this->assertSame('required', $checkout['terms_of_service_asked']);
        $this->assertSame('accepted', $checkout['terms_of_service']);
    }

    public function test_paystacks_record_of_the_payment_is_kept_without_anything_that_charges_the_card_again(): void
    {
        [$event, $type] = $this->night('NGN');
        $order = $this->buy($event, $type);
        $authorization = 'AUTH_'.Str::random(10);

        $this->processor['api.paystack.co/transaction/verify/*'] = fn () => Http::response([
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'id' => 4099260516,
                'domain' => 'test',
                'status' => 'success',
                'reference' => $order->gateway_reference,
                'receipt_number' => null,
                'amount' => $order->total_amount,
                'message' => null,
                'gateway_response' => 'Successful',
                'paid_at' => '2026-09-21T09:15:02.000Z',
                'created_at' => '2026-09-21T09:14:24.000Z',
                'channel' => 'card',
                'currency' => 'NGN',
                'ip_address' => '197.210.54.33',
                'metadata' => ['order_id' => $order->id, 'reference' => $order->reference],
                'log' => ['start_time' => 1790500000, 'time_spent' => 38, 'attempts' => 1, 'errors' => 0, 'success' => true, 'mobile' => true, 'input' => [], 'history' => []],
                'fees' => 10283,
                'fees_split' => null,
                'authorization' => [
                    'authorization_code' => $authorization,
                    'bin' => '408408',
                    'last4' => '4081',
                    'exp_month' => '12',
                    'exp_year' => '2030',
                    'channel' => 'card',
                    'card_type' => 'visa ',
                    'bank' => 'TEST BANK',
                    'country_code' => 'NG',
                    'brand' => 'visa',
                    'reusable' => true,
                    'signature' => 'SIG_yEXu7dLBeqG0kU7g95Ke',
                    'account_name' => 'ADA OKAFOR',
                ],
                'customer' => [
                    'id' => 181873746,
                    'first_name' => 'Ada',
                    'last_name' => 'Okafor',
                    'email' => 'ada@example.com',
                    'customer_code' => 'CUS_1rkzaqsv4rrhqo6',
                    'phone' => '+2348012345678',
                    'metadata' => null,
                    'risk_action' => 'default',
                ],
                'plan' => null,
                'paidAt' => '2026-09-21T09:15:02.000Z',
                'createdAt' => '2026-09-21T09:14:24.000Z',
                'requested_amount' => $order->total_amount,
            ],
        ]);

        $this->paystackPaid($order, 4099260516)->assertOk();
        $this->assertSame('paid', $order->fresh()->status);

        $this->artisan('disputes:collect-evidence')->assertSuccessful();

        $evidence = PaymentEvidence::where('order_id', $order->id)->sole();
        $facts = $evidence->facts;

        $this->assertSame(PaymentEvidence::CAPTURED, $evidence->status);
        $this->assertSame('paystack', $facts['processor']);
        $this->assertSame(4099260516, $facts['transaction']['id']);
        $this->assertSame($order->total_amount, $facts['transaction']['amount']);
        $this->assertSame('card', $facts['transaction']['channel']);
        $this->assertSame('197.210.54.33', $facts['transaction']['ip_address']);
        $this->assertSame(10283, $facts['transaction']['fees']);
        $this->assertSame('4081', $facts['authorization']['last4']);
        $this->assertSame('visa', $facts['authorization']['brand']);
        $this->assertSame('TEST BANK', $facts['authorization']['bank']);
        $this->assertTrue($facts['authorization']['reusable']);
        $this->assertSame('SIG_yEXu7dLBeqG0kU7g95Ke', $facts['authorization']['signature']);
        $this->assertSame('ada@example.com', $evidence->receipt_email);

        $row = (string) json_encode(DB::table('payment_evidence')->where('order_id', $order->id)->first());

        // What would charge the card again, and the start of its number.
        $this->assertStringNotContainsString($authorization, $row);
        $this->assertStringNotContainsString('408408', $row);
        $this->assertStringNotContainsString('ADA OKAFOR', $row);
        $this->assertStringNotContainsString('+2348012345678', $row);
    }

    public function test_a_paid_order_the_notice_left_no_note_for_is_found_by_the_sweep(): void
    {
        [$event, $type] = $this->night();
        $order = $this->buy($event, $type);

        $pi = 'pi_'.Str::random(24);
        $ch = 'ch_'.Str::random(24);
        $this->processor['api.stripe.com/v1/payment_intents/*'] = fn () => Http::response($this->stripeIntent($pi, $ch, $order->total_amount));
        $this->processor['api.stripe.com/v1/charges/*'] = fn () => Http::response($this->stripeCharge($ch, $pi, $order->total_amount));

        // Paid some other way than the notice that leaves the note.
        $order->forceFill(['gateway_payment_reference' => $pi])->save();
        app(Fulfiller::class)->fulfil($order);
        $this->assertSame(0, PaymentEvidence::count());

        $this->artisan('disputes:collect-evidence')->assertSuccessful();

        $this->assertSame(PaymentEvidence::CAPTURED, PaymentEvidence::where('order_id', $order->id)->sole()->status);

        // Asked once, however often the sweep runs after.
        $this->artisan('disputes:collect-evidence')->assertSuccessful();
        $this->assertSame(1, $this->asked('/v1/payment_intents/'));
    }

    public function test_a_payment_long_past_is_not_swept_up(): void
    {
        [$event, $type] = $this->night();
        $order = $this->buy($event, $type);
        $order->forceFill(['gateway_payment_reference' => 'pi_'.Str::random(24)])->save();
        app(Fulfiller::class)->fulfil($order);

        $this->travel(config('disputes.evidence.look_back_days') + 1)->days();
        $this->artisan('disputes:collect-evidence')->assertSuccessful();

        $this->assertSame(0, PaymentEvidence::count());
        $this->assertSame(0, $this->asked('/v1/payment_intents/'));
    }

    public function test_a_free_order_has_no_processor_to_ask(): void
    {
        [$event, $type] = $this->night();
        $type->update(['price_amount' => 0]);

        $this->buy($event, $type);
        $this->artisan('disputes:collect-evidence')->assertSuccessful();

        $this->assertSame('paid', Order::sole()->status);
        $this->assertSame(0, PaymentEvidence::count());
    }
}
