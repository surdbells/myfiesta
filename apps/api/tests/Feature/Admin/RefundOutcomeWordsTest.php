<?php

namespace Tests\Feature\Admin;

use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Contracts\Payments\RefundNotice;
use App\Contracts\Payments\RefundResult;
use App\Contracts\Payments\TotalsRefunds;
use App\Enums\PlatformRole;
use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\Refund;
use App\Services\StaffSupport\StaffActionRefused;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * What staff are told after pressing Refund, in each state a refund can be in.
 *
 * The words are the whole of what the person at the screen knows. A refund
 * still waiting for the processor read as "the payment processor did not
 * return the money" is somebody pressing Refund again; one turned down because
 * the money had already gone back read as "nothing was voided" is somebody
 * telling a buyer their tickets still work when they do not.
 */
class RefundOutcomeWordsTest extends TestCase
{
    use RefreshDatabase, SupportFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakeGateways();
    }

    private function order(): Order
    {
        $this->actAs($this->staff(PlatformRole::Finance));
        $event = $this->event($this->organization());

        return $this->paidOrder($event, $this->ticketType($event));
    }

    /** A Stripe that answers a refund with $result, and says it has refunded $total so far. */
    private function stripe(RefundResult $result, int $total = 0): void
    {
        $registry = new PaymentGatewayRegistry;
        $gateway = Mockery::mock(PaymentGateway::class, TotalsRefunds::class);
        $gateway->shouldReceive('name')->andReturn('stripe');
        $gateway->shouldReceive('supports')->andReturn(true);
        $gateway->shouldReceive('refund')->andReturn($result);
        $gateway->shouldReceive('refundedSoFar')->andReturn(new RefundNotice(
            status: RefundNotice::SUCCEEDED,
            totalRefundedMinorUnits: $total,
        ));
        $registry->register($gateway);

        $this->app->instance(PaymentGatewayRegistry::class, $registry);
    }

    public function test_a_refund_that_went_through_says_the_money_is_on_its_way_and_the_tickets_have_stopped(): void
    {
        $order = $this->order();

        Livewire::test(ListOrders::class)
            ->callTableAction('refund', $order, data: ['scope' => 'all', 'reason' => 'Could not come.'])
            ->assertNotified('Refund sent');

        $said = OrderActions::saidAbout($order->fresh(), Refund::sole());

        $this->assertSame('$113.00 is on its way back to the buyer through Stripe. The 2 tickets it was for no longer get in.', $said);
    }

    public function test_a_refund_waiting_for_an_answer_is_neither_done_nor_failed(): void
    {
        $this->stripe(RefundResult::unknown('Timed out'));
        $order = $this->order();

        Livewire::test(ListOrders::class)
            ->callTableAction('refund', $order, data: ['scope' => 'all', 'reason' => 'Could not come.'])
            ->assertNotified('Refund sent, not yet confirmed');

        $refund = Refund::sole();
        $this->assertSame('pending', $refund->status);

        $said = OrderActions::saidAbout($order->fresh(), $refund);

        $this->assertInstanceOf(Notification::class, $said);
        $this->assertStringContainsString('Sent to Stripe, which has not answered yet (Timed out)', $said->getBody());
        $this->assertStringContainsString('The money may already be on its way', $said->getBody());
        $this->assertStringContainsString('Do not refund them again', $said->getBody());
        $this->assertStringNotContainsString('did not', $said->getBody());
    }

    public function test_a_refund_turned_down_says_nothing_moved(): void
    {
        $this->gatewayRefunds = false;
        $order = $this->order();

        Livewire::test(ListOrders::class)
            ->callTableAction('refund', $order, data: ['scope' => 'all', 'reason' => 'Could not come.'])
            ->assertNotified('Not done');

        try {
            OrderActions::saidAbout($order->fresh(), Refund::sole());
            $this->fail('A refused refund is not a success.');
        } catch (StaffActionRefused $refused) {
            $this->assertSame(
                'The refund did not go through: Card account closed. No money moved and the tickets still work. The attempt is recorded on the order.',
                $refused->getMessage(),
            );
        }

        $this->assertSame(['valid'], $order->tickets()->pluck('status')->unique()->values()->all());
    }

    public function test_a_refund_turned_down_because_the_money_had_already_gone_back_says_so(): void
    {
        // Refunded in the Stripe dashboard already, so Stripe refuses ours —
        // and, asked, says the whole payment has gone back.
        $order = $this->order();
        $this->stripe(RefundResult::failed('Charge has already been refunded.'), total: $order->total_amount);

        Livewire::test(ListOrders::class)
            ->callTableAction('refund', $order, data: ['scope' => 'all', 'reason' => 'Could not come.'])
            ->assertNotified('Refund turned down: the money had already gone back');

        $order->refresh();
        $ours = Refund::where('source', Refund::FROM_PLATFORM)->sole();

        // What it found is recorded, and the tickets have stopped: telling
        // anybody "nothing moved" now would be the opposite of true.
        $this->assertSame('failed', $ours->status);
        $this->assertSame('refunded', $order->status);
        $this->assertSame(['refunded'], $order->tickets()->pluck('status')->unique()->values()->all());

        $said = OrderActions::saidAbout($order, $ours);

        $this->assertInstanceOf(Notification::class, $said);
        $this->assertStringContainsString('Stripe turned this refund down (Charge has already been refunded)', $said->getBody());
        $this->assertStringContainsString('It had already refunded $113.00 of this payment, made in its own dashboard', $said->getBody());
        $this->assertStringContainsString('the tickets no longer get in', $said->getBody());
        $this->assertStringNotContainsString('No money moved', $said->getBody());
    }
}
