<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Disputes\RefundPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Tests\Concerns\SellsTicketsForDisputes;
use Tests\TestCase;

/**
 * What Stripe's payment page is asked to say and do, so a dispute has an
 * answer: the night named on the bank statement, the refund policy by the pay
 * button, the card's bank asked to check it is the cardholder, and — once the
 * dashboard has the terms page — Stripe's own terms box.
 *
 * Read from the request the checkout actually sends, since the page is
 * Stripe's and nothing else of ours can show what was on it.
 */
class CheckoutSessionDisputeTest extends TestCase
{
    use RefreshDatabase, SellsTicketsForDisputes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcessors();

        config(['payments.stripe.statement_descriptor_prefix' => 'MYFIESTA']);
    }

    /** @return array<string, mixed> the body of the checkout session Stripe was asked to open */
    private function sessionFor(string $title = 'Afro Fest'): array
    {
        [$event, $type] = $this->night('CAD', ['title' => $title]);
        $this->buy($event, $type);

        $sent = collect($this->sentToProcessor)
            ->filter(fn (ClientRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v1/checkout/sessions'))
            ->last();

        $this->assertNotNull($sent, 'No checkout session was opened.');

        return $sent->data();
    }

    public function test_the_bank_statement_names_the_night(): void
    {
        $session = $this->sessionFor('Afro Fest');

        $this->assertSame('AFRO FEST', $session['payment_intent_data']['statement_descriptor_suffix']);
    }

    public function test_a_long_or_awkward_name_is_made_into_one_stripe_takes(): void
    {
        $session = $this->sessionFor('Ada\'s "Afrobeats" & Amapiano <Rooftop>');

        $suffix = $session['payment_intent_data']['statement_descriptor_suffix'];

        // Whole words would leave only ADAS, which names nothing.
        $this->assertSame('ADAS AFROBEA', $suffix);
        $this->assertLessThanOrEqual(22, strlen('MYFIESTA* '.$suffix));
    }

    public function test_a_name_with_nothing_latin_in_it_leaves_the_statement_to_the_prefix(): void
    {
        $session = $this->sessionFor('🎉🎉🎉');

        $this->assertArrayNotHasKey('payment_intent_data', $session);
    }

    public function test_the_pay_button_carries_the_refund_policy_in_the_words_of_our_own_checkout(): void
    {
        $session = $this->sessionFor();

        $summary = app(RefundPolicy::class)->summary();

        $this->assertNotNull($summary);
        $this->assertSame($summary, $session['custom_text']['submit']['message']);
        $this->assertSame('Refunds are up to the organizer, except that you are owed one if the event is cancelled.', $summary);
    }

    public function test_the_cards_bank_is_asked_as_stripe_judges_by_default(): void
    {
        $session = $this->sessionFor();

        $this->assertSame('automatic', $session['payment_method_options']['card']['request_three_d_secure']);
    }

    public function test_it_can_be_asked_on_every_card(): void
    {
        config(['payments.stripe.request_three_d_secure' => 'any']);

        $this->assertSame('any', $this->sessionFor()['payment_method_options']['card']['request_three_d_secure']);
    }

    public function test_a_mistyped_setting_falls_back_to_stripes_judgement_rather_than_to_no_sale(): void
    {
        config(['payments.stripe.request_three_d_secure' => 'always']);

        $this->assertSame('automatic', $this->sessionFor()['payment_method_options']['card']['request_three_d_secure']);
    }

    public function test_stripes_own_terms_box_is_off_until_it_is_switched_on(): void
    {
        $session = $this->sessionFor();

        $this->assertArrayNotHasKey('consent_collection', $session);
        $this->assertArrayNotHasKey('terms_of_service_acceptance', $session['custom_text']);
    }

    public function test_switched_on_it_is_required_and_names_our_three_pages(): void
    {
        config(['payments.stripe.collect_terms_consent' => true]);

        $session = $this->sessionFor();

        $this->assertSame('required', $session['consent_collection']['terms_of_service']);

        $wording = $session['custom_text']['terms_of_service_acceptance']['message'];

        foreach (['/terms', '/privacy', '/refunds'] as $page) {
            $this->assertStringContainsString('https://myfiesta.test'.$page, $wording);
        }
    }

    public function test_the_rest_of_the_session_is_as_it_was(): void
    {
        $session = $this->sessionFor();

        $this->assertSame('payment', $session['mode']);
        $this->assertSame('ada@example.com', $session['customer_email']);
        $this->assertSame((string) Order::sole()->total_amount, (string) $session['line_items'][0]['price_data']['unit_amount']);
    }

    public function test_paystack_is_asked_for_nothing_it_does_not_take(): void
    {
        [$event, $type] = $this->night('NGN');
        $this->buy($event, $type);

        $sent = collect($this->sentToProcessor)
            ->first(fn (ClientRequest $request) => str_ends_with($request->url(), '/transaction/initialize'));

        $this->assertEqualsCanonicalizing(
            ['email', 'amount', 'currency', 'reference', 'callback_url', 'channels', 'metadata'],
            array_keys($sent->data()),
        );
    }
}
