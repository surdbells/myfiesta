<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\TaxRate;
use App\Models\TicketType;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\Fulfiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two ways of saying what an organizer is owed, compared.
 *
 * An order carries `net_revenue_amount`. The ledger carries entries that are
 * supposed to sum to the same thing. Nothing compared them, and they disagreed
 * for every event in a jurisdiction that adds tax on top: the sale entry
 * recorded a subtotal with no tax in it, and the tax entry then took the tax
 * out anyway. A Canadian organizer was short the full HST on every sale, and
 * settlements pay from the ledger.
 *
 * This asserts the invariant directly, in both tax modes, so the next change
 * to either side has to keep them in step.
 */
class LedgerAgreesWithOrderTest extends TestCase
{
    use RefreshDatabase;

    private function sell(string $currency, string $country, ?string $subdivision, int $price): array
    {
        $event = Event::factory()->create([
            'currency' => $currency,
            'country' => $country,
            'subdivision' => $subdivision,
            'status' => 'published',
        ]);

        $type = TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => $price,
            'status' => 'on_sale',
        ]);

        $order = app(CheckoutService::class)
            ->reserve($event, [$type->id => 2], 'buyer@example.test', 'Ada Buyer');

        app(Fulfiller::class)->fulfil($order);

        return [$order->refresh(), (int) LedgerEntry::where('order_id', $order->id)->sum('amount')];
    }

    public function test_tax_added_on_top_leaves_the_organizer_the_whole_ticket_price(): void
    {
        TaxRate::query()->updateOrCreate(
            ['country' => 'CA', 'subdivision' => 'ON'],
            ['name' => 'HST', 'rate_bps' => 1300, 'inclusive' => false, 'effective_from' => '2020-01-01'],
        );

        [$order, $ledger] = $this->sell('CAD', 'CA', 'ON', 10000);

        $this->assertSame(20000, $order->net_revenue_amount);
        $this->assertSame(2600, $order->tax_amount);
        $this->assertSame(1600, $order->service_charge_amount);

        // The HST was collected from the buyer on top. It is not the
        // organizer's, and it was never the organizer's to lose either.
        $this->assertSame(
            $order->net_revenue_amount,
            $ledger,
            'The ledger must not deduct a tax that was added to the buyer rather than taken from the price.',
        );
    }

    public function test_tax_inside_the_price_leaves_the_organizer_the_price_less_tax(): void
    {
        TaxRate::query()->updateOrCreate(
            ['country' => 'NG', 'subdivision' => null],
            ['name' => 'VAT', 'rate_bps' => 750, 'inclusive' => true, 'effective_from' => '2020-01-01'],
        );

        [$order, $ledger] = $this->sell('NGN', 'NG', null, 107500);

        // 215000 advertised, 15000 of it VAT.
        $this->assertSame(15000, $order->tax_amount);
        $this->assertSame(200000, $order->net_revenue_amount);

        $this->assertSame($order->net_revenue_amount, $ledger);
    }

    public function test_the_service_charge_never_appears_in_the_organizers_ledger(): void
    {
        TaxRate::query()->updateOrCreate(
            ['country' => 'CA', 'subdivision' => 'ON'],
            ['name' => 'HST', 'rate_bps' => 1300, 'inclusive' => false, 'effective_from' => '2020-01-01'],
        );

        [$order, $ledger] = $this->sell('CAD', 'CA', 'ON', 10000);

        $types = LedgerEntry::where('order_id', $order->id)->pluck('type')->all();

        $this->assertEqualsCanonicalizing(['sale', 'tax'], $types);
        $this->assertGreaterThan(0, $order->service_charge_amount);
        $this->assertSame($order->net_revenue_amount, $ledger);
    }
}
