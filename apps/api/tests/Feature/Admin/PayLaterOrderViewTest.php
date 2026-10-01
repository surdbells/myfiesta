<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Mail\RefundMadeElsewhere;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\PaymentEvidence;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The order page says how the buyer paid on the processor's page, so support
 * can tell a Klarna or Affirm order (whose refunds close after the lender's
 * window) from a card one before anybody asks.
 */
class PayLaterOrderViewTest extends TestCase
{
    use RefreshDatabase, SupportFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakeGateways();
    }

    public function test_the_order_page_says_it_was_paid_later_and_with_whom(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));
        $event = $this->event($this->organization());
        $order = $this->paidOrder($event, $this->ticketType($event));

        PaymentEvidence::create([
            'order_id' => $order->id,
            'event_id' => $order->event_id,
            'gateway' => 'stripe',
            'status' => PaymentEvidence::CAPTURED,
            'method_type' => 'klarna',
            'captured_at' => now(),
        ]);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSuccessful()
            ->assertSee('Paid with')
            ->assertSee('Klarna (paid later)');
    }

    public function test_before_the_processor_has_been_asked_it_says_so(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));
        $event = $this->event($this->organization());
        $order = $this->paidOrder($event, $this->ticketType($event));

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSuccessful()
            ->assertSee('Not known yet')
            ->assertDontSee('paid later');
    }

    public function test_a_sale_with_no_processor_does_not_say_it_is_waiting_to_know(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));
        $event = $this->event($this->organization());
        $order = $this->paidOrder($event, $this->ticketType($event), attributes: ['channel' => 'door']);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSuccessful()
            ->assertSee('Paid with')
            ->assertDontSee('Not known yet');
    }

    /** A Klarna order paid this many days ago, its evidence captured. */
    private function paidWithKlarna(int $daysAgo, ?int $fee = null, ?int $orderFee = null): Order
    {
        $organization = $this->organization();
        $this->member($organization, Role::Owner, ['email' => 'owner@lagosnights.test']);
        $event = $this->event($organization);
        $order = $this->paidOrder($event, $this->ticketType($event), attributes: [
            'paid_at' => now()->subDays($daysAgo),
            'gateway_fee_amount' => $orderFee,
        ]);

        PaymentEvidence::create([
            'order_id' => $order->id,
            'event_id' => $order->event_id,
            'gateway' => 'stripe',
            'status' => PaymentEvidence::CAPTURED,
            'method_type' => 'klarna',
            'fee_amount' => $fee,
            'captured_at' => now(),
        ]);

        return $order;
    }

    public function test_inside_the_lenders_window_staff_refund_through_stripe_as_usual(): void
    {
        $this->actAs($this->staff(PlatformRole::Admin));
        $order = $this->paidWithKlarna(30);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('refund')
            ->assertActionHidden('recordReturnedOutside');
    }

    public function test_past_the_lenders_window_staff_record_the_money_they_returned_another_way(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));
        $order = $this->paidWithKlarna(200);

        $page = Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionHidden('refund')
            ->assertActionVisible('recordReturnedOutside');

        // What the confirmation says before anything is recorded.
        $said = (string) OrderActions::recordReturnedOutside()->record($order)->getModalDescription();
        $this->assertStringContainsString('Klarna takes money back only within 180 days', $said);
        $this->assertStringContainsString('Send the buyer their money another way first', $said);
        $this->assertStringContainsString('cannot be undone', $said);

        $page->callAction('recordReturnedOutside', data: [
            'amount' => number_format($order->total_amount / 100, 2, '.', ''),
            'reference' => 'ETR-20261001-77',
            'note' => 'The night was cancelled and the buyer asked for their money.',
            'sent' => true,
        ])->assertHasNoActionErrors();

        $order->refresh();
        $refund = Refund::where('order_id', $order->id)->sole();

        $this->assertSame('refunded', $order->status);
        $this->assertSame('succeeded', $refund->status);
        $this->assertSame($order->total_amount, (int) $refund->amount);
        $this->assertSame($admin->id, $refund->issued_by);
        $this->assertStringContainsString('Returned by myFiesta support outside Stripe, ref. ETR-20261001-77', (string) $refund->reason);
        $this->assertSame(0, $order->tickets()->whereIn('status', ['valid', 'checked_in'])->count());

        // Off the organizer's balance, said the way it was done.
        $entry = LedgerEntry::where('order_id', $order->id)->where('type', 'refund')->sole();
        $this->assertStringContainsString('Returned by myFiesta support', (string) $entry->reason);

        $this->assertTrue(AuditLog::where('action', 'refund.made_elsewhere')->where('actor_id', $admin->id)->exists());
        Mail::assertQueued(RefundMadeElsewhere::class, fn (RefundMadeElsewhere $mail) => $mail->hasTo('owner@lagosnights.test')
            && $mail->how !== null
            && str_contains($mail->render(), 'myFiesta support returned'));
    }

    public function test_the_processor_fee_says_what_the_organizer_paid_of_a_lenders_charge(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));
        $order = $this->paidWithKlarna(5, fee: 755, orderFee: 381);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSuccessful()
            ->assertSee('The platform&#039;s part. Klarna took $7.55 in all; the organizer paid $3.74 of it', false);
    }
}
