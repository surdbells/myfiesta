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
        $this->assertSame(1600, $quote->serviceCharge->amount, '8% of the 20000 earned.');
        $this->assertSame(20000, $quote->netRevenue->amount, 'The organizer is owed the ticket price.');
        $this->assertSame(24200, $quote->total->amount, 'Price, then tax, then the service charge.');
    }

    public function test_inclusive_tax_is_extracted_rather_than_added(): void
    {
        $event = $this->event('NGN', 'NG', null);
        $type = $this->ticket($event, 107500); // ₦1,075.00, VAT already inside

        $quote = $this->pricer->quote($event, [$type->id => 1]);

        // The trap: adding 7.5% here would charge ₦1,155.63 of tax on a ticket
        // advertised at ₦1,075 — the tax twice.
        $this->assertSame(7500, $quote->tax->amount, 'VAT inside 1075 is 75, not 80.');
        $this->assertSame(100000, $quote->netRevenue->amount, 'The ticket, VAT removed.');

        // The advertised price is what the ticket costs. The service charge is
        // a separate thing the buyer is told about, not a second tax hidden
        // inside a price that was quoted as final.
        $this->assertSame(8000, $quote->serviceCharge->amount);
        $this->assertSame(107500 + 8000, $quote->total->amount);
    }

    public function test_the_service_charge_is_added_for_the_buyer_not_taken_from_the_organizer(): void
    {
        $event = $this->event('NGN', 'NG', null);
        $type = $this->ticket($event, 107500);

        $quote = $this->pricer->quote($event, [$type->id => 1]);

        // The whole point of the model, in one assertion. An organizer selling
        // a ₦1,075 ticket is owed ₦1,000 of it — the ₦75 is VAT — and not one
        // naira less for the platform's ₦80, which the buyer pays on top.
        //
        // Taking it from the other side instead is arithmetically tidy and
        // commercially a pay cut to every organizer already selling.
        $this->assertSame(100000, $quote->netRevenue->amount);
        $this->assertSame(8000, $quote->serviceCharge->amount, '8% of the net, not of the gross.');
        $this->assertSame(
            $quote->netRevenue->amount + $quote->tax->amount + $quote->serviceCharge->amount,
            $quote->total->amount,
        );
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
        $this->assertSame(400, $quote->serviceCharge->amount, '8% of the 50.00 collected.');
        $this->assertSame(6050, $quote->total->amount);
    }

    public function test_the_service_charge_follows_the_discount_down(): void
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

        // The discount is the organizer's cost, and the service charge shrinks
        // with what was actually collected — 8% of 50.00, not of the 100.00
        // nobody paid. Charging on the face value would have the buyer of a
        // half-price ticket paying the platform's fee on the other half.
        $this->assertSame(400, $quote->serviceCharge->amount);
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
        $this->assertSame(10000, $quote->netRevenue->amount);
        $this->assertSame(800, $quote->serviceCharge->amount, 'No tax does not mean no service charge.');
        $this->assertSame(10800, $quote->total->amount);
    }
}
