<?php

namespace Tests\Feature;

use App\Exceptions\CheckoutException;
use App\Models\Code;
use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketType;
use App\Services\Checkout\Pricer;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pricing, which is where the previous platform lost money.
 *
 * Its checkout built a payment session with `unit_amount => $data->total * 100`
 * taken from the request body, so a buyer could pay whatever they typed. These
 * tests exist to make sure a price can only ever come from the database.
 */
class PricingTest extends TestCase
{
    use RefreshDatabase;

    private Pricer $pricer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxRateSeeder::class);
        $this->pricer = app(Pricer::class);
    }

    private function event(string $currency = 'CAD', string $country = 'CA', ?string $sub = 'ON'): Event
    {
        return Event::create([
            'organization_id' => Organization::create(['name' => 'Nights', 'slug' => 'nights-'.uniqid()])->id,
            'slug' => 'e-'.uniqid(),
            'title' => 'Test Event',
            'currency' => $currency,
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => $sub,
            'country' => $country,
            'status' => 'published',
        ]);
    }

    private function ticket(Event $event, int $price, array $attrs = []): TicketType
    {
        return TicketType::create(array_merge([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => $price,
            'status' => 'on_sale',
        ], $attrs));
    }

    public function test_exclusive_tax_is_added_to_the_price(): void
    {
        $event = $this->event();
        $type = $this->ticket($event, 10000); // $100.00

        $quote = $this->pricer->quote($event, [$type->id => 2]);

        $this->assertSame(20000, $quote->subtotal->amount);
        $this->assertSame(2600, $quote->tax->amount, 'Ontario HST is 13%.');
        $this->assertSame(22600, $quote->total->amount, 'Canadian prices are advertised before tax.');
    }

    public function test_inclusive_tax_is_extracted_rather_than_added(): void
    {
        $event = $this->event('NGN', 'NG', null);
        $type = $this->ticket($event, 107500); // ₦1,075.00, VAT already inside

        $quote = $this->pricer->quote($event, [$type->id => 1]);

        // The trap: adding 7.5% here would charge ₦1,155.63 for a ticket
        // advertised at ₦1,075 — the tax twice.
        $this->assertSame(107500, $quote->total->amount, 'The buyer pays the advertised price.');
        $this->assertSame(7500, $quote->tax->amount, 'VAT inside 1075 is 75, not 80.');
    }

    public function test_commission_is_taken_on_revenue_net_of_tax(): void
    {
        $event = $this->event('NGN', 'NG', null);
        $type = $this->ticket($event, 107500);

        $quote = $this->pricer->quote($event, [$type->id => 1]);

        // Tax is never the organizer's money, so the platform does not take a
        // cut of it. 10% of 100000, not of 107500.
        $this->assertSame(10000, $quote->commission->amount);
    }

    public function test_tax_applies_to_the_discounted_amount(): void
    {
        $event = $this->event();
        $type = $this->ticket($event, 10000);

        Code::create([
            'organization_id' => $event->organization_id,
            'code' => 'HALF',
            'discount_type' => 'percentage',
            'discount_value' => 5000,
        ]);

        $quote = $this->pricer->quote($event, [$type->id => 1], 'HALF');

        $this->assertSame(5000, $quote->discount->amount);
        // 13% of 50.00, not of 100.00. Taxing the face value overcharges on
        // money nobody received.
        $this->assertSame(650, $quote->tax->amount);
        $this->assertSame(5650, $quote->total->amount);
    }

    public function test_commission_follows_the_discount_down(): void
    {
        $event = $this->event();
        $type = $this->ticket($event, 10000);

        Code::create([
            'organization_id' => $event->organization_id,
            'code' => 'HALF',
            'discount_type' => 'percentage',
            'discount_value' => 5000,
        ]);

        $quote = $this->pricer->quote($event, [$type->id => 1], 'HALF');

        // The discount is the organizer's cost, and the platform's cut shrinks
        // with what was actually collected.
        $this->assertSame(500, $quote->commission->amount);
    }

    public function test_a_fixed_code_cannot_cross_currencies(): void
    {
        $event = $this->event('CAD');
        $type = $this->ticket($event, 10000);

        Code::create([
            'organization_id' => $event->organization_id,
            'code' => 'NAIRA',
            'discount_type' => 'fixed',
            'discount_value' => 200000,
            'discount_currency' => 'NGN',
        ]);

        // ₦2,000 off a Canadian ticket is not a discount, it is a category
        // error. Percentage codes travel; fixed amounts do not.
        $this->expectException(CheckoutException::class);
        $this->pricer->quote($event, [$type->id => 1], 'NAIRA');
    }

    public function test_a_code_larger_than_the_order_does_not_hand_money_back(): void
    {
        $event = $this->event();
        $type = $this->ticket($event, 1000);

        Code::create([
            'organization_id' => $event->organization_id,
            'code' => 'BIG',
            'discount_type' => 'fixed',
            'discount_value' => 999999,
            'discount_currency' => 'CAD',
        ]);

        $quote = $this->pricer->quote($event, [$type->id => 1], 'BIG');

        $this->assertSame(1000, $quote->discount->amount, 'Capped at the subtotal.');
        $this->assertSame(0, $quote->total->amount);
        $this->assertFalse($quote->requiresPayment(), 'A free order has no gateway to talk to.');
    }

    public function test_a_ticket_from_another_event_is_refused(): void
    {
        $mine = $this->event();
        $theirs = $this->event();
        $cheap = $this->ticket($theirs, 100);

        // Trusting the id would let a cheap ticket type from an unrelated event
        // be bought against this one.
        $this->expectException(CheckoutException::class);
        $this->pricer->quote($mine, [$cheap->id => 1]);
    }

    public function test_per_order_limits_are_enforced(): void
    {
        $event = $this->event();
        $type = $this->ticket($event, 10000, ['max_per_order' => 4]);

        $this->expectException(CheckoutException::class);
        $this->pricer->quote($event, [$type->id => 5]);
    }

    public function test_a_ticket_that_is_not_on_sale_cannot_be_bought(): void
    {
        $event = $this->event();
        $type = $this->ticket($event, 10000, ['status' => 'hidden']);

        $this->expectException(CheckoutException::class);
        $this->pricer->quote($event, [$type->id => 1]);
    }

    public function test_an_untaxed_jurisdiction_charges_no_tax_rather_than_a_guess(): void
    {
        $event = $this->event('USD', 'US', 'NY');
        $type = $this->ticket($event, 10000);

        $quote = $this->pricer->quote($event, [$type->id => 1]);

        $this->assertSame(0, $quote->tax->amount);
        $this->assertSame(10000, $quote->total->amount);
    }
}
