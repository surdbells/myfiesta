<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The arithmetic on an order, asserted at the storage layer.
 *
 * These are writes, not calculations. The pricer has its own tests and they
 * run entirely in memory, which is why they never noticed whether the figures
 * they produce can be stored — the two can disagree for a long time before
 * anybody finds out, and the way they find out is a checkout that 500s.
 */
class OrderArithmeticTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $money
     */
    private function order(Event $event, array $money): Order
    {
        return new Order([
            'event_id' => $event->id,
            'organization_id' => $event->organization_id,
            'buyer_email' => 'buyer@example.test',
            'buyer_name' => 'Buyer',
            'reference' => 'MF-'.substr(md5(serialize($money)), 0, 8),
            'status' => 'pending',
            'currency' => $event->currency,
            ...$money,
        ]);
    }

    public function test_tax_added_on_top_stores_as_charged(): void
    {
        $event = Event::factory()->create(['currency' => 'CAD']);

        // A $50 ticket in Ontario: $6.50 HST added, $4.00 service charge.
        // The buyer pays $60.50 and the organizer is owed $50.00.
        $order = $this->order($event, [
            'subtotal_amount' => 5000,
            'discount_amount' => 0,
            'tax_amount' => 650,
            'tax_inclusive' => false,
            'net_revenue_amount' => 5000,
            'service_charge_amount' => 400,
            'total_amount' => 6050,
        ]);

        $order->save();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'total_amount' => 6050,
            'net_revenue_amount' => 5000,
        ]);
    }

    public function test_tax_inside_the_price_stores_as_charged(): void
    {
        $event = Event::factory()->create(['currency' => 'NGN']);

        // ₦1,075 advertised, ₦75 of it VAT. The organizer earned ₦1,000, so
        // the service charge is ₦80 and the buyer pays ₦1,155.
        $order = $this->order($event, [
            'subtotal_amount' => 1075,
            'discount_amount' => 0,
            'tax_amount' => 75,
            'tax_inclusive' => true,
            'net_revenue_amount' => 1000,
            'service_charge_amount' => 80,
            'total_amount' => 1155,
        ]);

        $order->save();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'total_amount' => 1155]);
    }

    public function test_a_service_charge_taken_out_of_the_organizer_is_rejected(): void
    {
        $event = Event::factory()->create(['currency' => 'CAD']);

        // The shape of the bug this replaces: the platform's cut deducted from
        // net revenue rather than added for the buyer. The figures are
        // internally plausible — they simply describe an organizer being paid
        // 4600 for a 5000 ticket — so nothing but the constraint catches it.
        $order = $this->order($event, [
            'subtotal_amount' => 5000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'tax_inclusive' => false,
            'net_revenue_amount' => 4600,
            'service_charge_amount' => 400,
            'total_amount' => 5000,
        ]);

        $this->expectException(QueryException::class);

        $order->save();
    }

    public function test_the_inclusive_flag_cannot_disagree_with_the_figures(): void
    {
        $event = Event::factory()->create(['currency' => 'NGN']);

        // Says the tax is inside the price, then reports net revenue as though
        // it were added on top. The total still balances; the description of
        // how it got there does not.
        $order = $this->order($event, [
            'subtotal_amount' => 1075,
            'discount_amount' => 0,
            'tax_amount' => 75,
            'tax_inclusive' => true,
            'net_revenue_amount' => 1075,
            'service_charge_amount' => 86,
            'total_amount' => 1236,
        ]);

        $this->expectException(QueryException::class);

        $order->save();
    }
}
