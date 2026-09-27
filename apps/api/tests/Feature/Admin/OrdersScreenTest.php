<?php

namespace Tests\Feature\Admin;

use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Contracts\Payments\RefundResult;
use App\Enums\PlatformRole;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\TicketsRelationManager;
use App\Mail\TicketsResent;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\Refund;
use App\Models\User;
use App\Services\StaffSupport\TicketActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * The Orders screen: every order on the platform, found by what a buyer can
 * tell you, with refunds only through RefundService and only for finance and
 * administrators.
 */
class OrdersScreenTest extends TestCase
{
    use RefreshDatabase, SupportFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakeGateways();
    }

    public function test_every_staff_role_reads_orders_and_nobody_else(): void
    {
        foreach (PlatformRole::cases() as $role) {
            $this->actAs($this->staff($role));
            $this->assertTrue(OrderResource::canViewAny());
        }

        $this->actAs(User::factory()->create());
        $this->assertFalse(OrderResource::canViewAny());
    }

    public function test_orders_are_found_by_reference_email_or_name(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));
        $event = $this->event($this->organization());
        $type = $this->ticketType($event);

        $ada = $this->paidOrder($event, $type);
        $tunde = $this->paidOrder($event, $type, 1, ['buyer_name' => 'Tunde Bello', 'buyer_email' => 'tunde@example.com']);

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$ada, $tunde])
            ->searchTable($ada->reference)
            ->assertCanSeeTableRecords([$ada])
            ->assertCanNotSeeTableRecords([$tunde])
            ->searchTable('tunde@example.com')
            ->assertCanSeeTableRecords([$tunde])
            ->assertCanNotSeeTableRecords([$ada])
            ->searchTable('okafor')
            ->assertCanSeeTableRecords([$ada])
            ->assertCanNotSeeTableRecords([$tunde]);
    }

    public function test_the_filters_narrow_to_what_they_say(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));

        $toronto = $this->organization('Toronto Sound');
        $lagos = $this->organization('Eko Live');
        $cadEvent = $this->event($toronto);
        $ngnEvent = $this->event($lagos, ['currency' => 'NGN', 'title' => 'Eko Nights']);

        $cad = $this->paidOrder($cadEvent, $this->ticketType($cadEvent));
        $ngn = $this->paidOrder($ngnEvent, $this->ticketType($ngnEvent, ['price_amount' => 500000]));
        $door = $this->paidOrder($cadEvent, $cadEvent->ticketTypes()->first(), 1, ['channel' => 'door', 'buyer_email' => null]);
        $old = $this->paidOrder($cadEvent, $cadEvent->ticketTypes()->first(), 1, ['status' => 'refunded', 'created_at' => now()->subMonths(3)]);
        Refund::create([
            'order_id' => $old->id, 'event_id' => $cadEvent->id, 'organization_id' => $toronto->id,
            'currency' => 'CAD', 'amount' => 100, 'tax_amount' => 0, 'service_charge_amount' => 0, 'status' => 'succeeded',
        ]);
        Dispute::create([
            'order_id' => $ngn->id, 'organization_id' => $lagos->id, 'event_id' => $ngnEvent->id,
            'gateway' => 'paystack', 'gateway_reference' => 'dsp_1', 'amount' => 1000, 'currency' => 'NGN',
            'status' => 'open', 'opened_at' => now(),
        ]);

        Livewire::test(ListOrders::class)
            ->filterTable('status', ['refunded'])
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$cad, $ngn])
            ->resetTableFilters()
            ->filterTable('event', $ngnEvent->id)
            ->assertCanSeeTableRecords([$ngn])
            ->assertCanNotSeeTableRecords([$cad])
            ->resetTableFilters()
            ->filterTable('organization', $toronto->id)
            ->assertCanSeeTableRecords([$cad, $door])
            ->assertCanNotSeeTableRecords([$ngn])
            ->resetTableFilters()
            ->filterTable('currency', 'NGN')
            ->assertCanSeeTableRecords([$ngn])
            ->assertCanNotSeeTableRecords([$cad])
            ->resetTableFilters()
            ->filterTable('channel', 'door')
            ->assertCanSeeTableRecords([$door])
            ->assertCanNotSeeTableRecords([$cad])
            ->resetTableFilters()
            ->filterTable('gateway', 'paystack')
            ->assertCanSeeTableRecords([$ngn])
            ->assertCanNotSeeTableRecords([$cad, $door])
            ->resetTableFilters()
            ->filterTable('placed', ['from' => now()->subMonths(4)->toDateString(), 'until' => now()->subMonths(2)->toDateString()])
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$cad, $ngn])
            ->resetTableFilters()
            ->filterTable('refunded', true)
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$cad])
            ->resetTableFilters()
            ->filterTable('disputed', true)
            ->assertCanSeeTableRecords([$ngn])
            ->assertCanNotSeeTableRecords([$cad, $old]);
    }

    public function test_money_is_totalled_per_currency_and_never_across(): void
    {
        $this->actAs($this->staff(PlatformRole::Finance));

        $cadEvent = $this->event($this->organization());
        $ngnEvent = $this->event($this->organization('Eko Live'), ['currency' => 'NGN']);

        // 2 × 50.00 + 13% = $113.00; 1 × ₦5,000 + 13% = ₦5,650.
        $this->paidOrder($cadEvent, $this->ticketType($cadEvent));
        $this->paidOrder($ngnEvent, $this->ticketType($ngnEvent, ['price_amount' => 500000]), 1);

        Livewire::test(ListOrders::class)
            ->assertSee('$113.00 · ₦5,650')
            ->sortTable('total_amount', 'desc')
            ->assertSuccessful();
    }

    public function test_the_order_page_shows_everything_but_the_ticket_codes(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));
        $event = $this->event($this->organization(), ['title' => 'Highlife Night']);
        $order = $this->paidOrder($event, $this->ticketType($event, ['name' => 'Early Bird']));
        $codes = $order->tickets->pluck('code');

        $page = Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSuccessful()
            ->assertSee($order->reference)
            ->assertSee('Highlife Night')
            ->assertSee('Early Bird')
            ->assertSee('$113.00');

        $tickets = Livewire::test(TicketsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
            ->assertCanSeeTableRecords($order->tickets);

        foreach ($codes as $code) {
            $page->assertDontSee($code);
            $tickets->assertDontSee($code);
            // The tail a caller can read out is there; the rest is not.
            $tickets->assertSee('••••'.substr(str_replace('-', '', $code), -4));
        }
    }

    public function test_finance_refunds_a_whole_order_through_the_refund_service(): void
    {
        $finance = $this->actAs($this->staff(PlatformRole::Finance));
        $event = $this->event($this->organization());
        $order = $this->paidOrder($event, $this->ticketType($event));

        Livewire::test(ListOrders::class)
            ->callTableAction('refund', $order, data: ['scope' => 'all', 'reason' => 'Headliner cancelled.'])
            ->assertHasNoTableActionErrors();

        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertSame(['refunded'], $order->tickets()->pluck('status')->unique()->values()->all());

        $refund = Refund::sole();
        $this->assertSame(11300, (int) $refund->amount);
        $this->assertSame($finance->id, $refund->issued_by);
        $this->assertSame($finance->id, AuditLog::where('action', 'refund.processed')->sole()->actor_id);
    }

    public function test_finance_refunds_only_the_tickets_chosen(): void
    {
        $this->actAs($this->staff(PlatformRole::Admin));
        $event = $this->event($this->organization());
        $order = $this->paidOrder($event, $this->ticketType($event));
        [$first, $second] = $order->tickets()->orderBy('created_at')->orderBy('id')->get()->all();

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('refund', data: ['scope' => 'some', 'tickets' => [$first->id], 'reason' => 'One of them could not come.'])
            ->assertHasNoActionErrors();

        $this->assertSame('refunded', $first->fresh()->status);
        $this->assertSame('valid', $second->fresh()->status);
        $this->assertSame('partially_refunded', $order->fresh()->status);
    }

    public function test_tickets_ticked_on_the_order_are_refunded_together(): void
    {
        $this->actAs($this->staff(PlatformRole::Finance));
        $event = $this->event($this->organization());
        $order = $this->paidOrder($event, $this->ticketType($event), 3);
        $chosen = $order->tickets()->orderBy('created_at')->orderBy('id')->take(2)->get();

        Livewire::test(TicketsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
            ->assertTableBulkActionVisible('refundSelected')
            ->callTableBulkAction('refundSelected', $chosen, data: ['reason' => 'Two of three refunded.'])
            ->assertHasNoTableBulkActionErrors()
            ->assertNotified('Refund sent');

        $this->assertSame(2, $order->tickets()->where('status', 'refunded')->count());
        $this->assertSame(1, $order->tickets()->where('status', 'valid')->count());
    }

    public function test_a_refused_refund_says_why_and_leaves_the_tickets_working(): void
    {
        $this->gatewayRefunds = false;
        $this->actAs($this->staff(PlatformRole::Finance));
        $event = $this->event($this->organization());
        $order = $this->paidOrder($event, $this->ticketType($event));

        Livewire::test(ListOrders::class)
            ->callTableAction('refund', $order, data: ['scope' => 'all', 'reason' => 'Asked for it back.'])
            ->assertNotified('Not done');

        $this->assertSame('failed', Refund::sole()->status);
        $this->assertSame(['valid'], $order->tickets()->pluck('status')->unique()->values()->all());
    }

    public function test_a_refund_the_processor_did_not_answer_is_not_called_a_failure(): void
    {
        $registry = new PaymentGatewayRegistry;
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('name')->andReturn('stripe');
        $gateway->shouldReceive('supports')->andReturn(true);
        $gateway->shouldReceive('refund')->andReturn(RefundResult::unknown('Timed out'));
        $registry->register($gateway);
        $this->app->instance(PaymentGatewayRegistry::class, $registry);

        $this->actAs($this->staff(PlatformRole::Finance));
        $event = $this->event($this->organization());
        $order = $this->paidOrder($event, $this->ticketType($event));

        // Not "Not done": the money may already be on its way, and telling
        // somebody nothing happened is how they press Refund twice.
        Livewire::test(ListOrders::class)
            ->callTableAction('refund', $order, data: ['scope' => 'all', 'reason' => 'Asked for it back.'])
            ->assertNotified('Refund sent, not yet confirmed');

        $this->assertSame('pending', Refund::sole()->status);
        $this->assertSame(['valid'], $order->tickets()->pluck('status')->unique()->values()->all());
    }

    public function test_support_cannot_refund_but_can_resend_and_write_notes(): void
    {
        $support = $this->actAs($this->staff(PlatformRole::Support));
        $event = $this->event($this->organization());
        $order = $this->paidOrder($event, $this->ticketType($event));

        Livewire::test(ListOrders::class)
            ->assertTableActionHidden('refund', $order)
            ->callTableAction('resendTickets', $order)
            ->assertHasNoTableActionErrors()
            ->callTableAction('addNote', $order, data: ['note' => 'Buyer rang: the first email went to spam.'])
            ->assertHasNoTableActionErrors();

        Mail::assertQueued(TicketsResent::class, fn (TicketsResent $mail) => $mail->hasTo('ada@example.com'));
        $this->assertSame($support->id, AuditLog::where('action', 'order.tickets_resent')->sole()->actor_id);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSee('Buyer rang: the first email went to spam.');

        $this->assertSame(0, Refund::count());
    }

    public function test_resending_lists_only_tickets_that_still_work_and_are_still_the_buyers(): void
    {
        $finance = $this->actAs($this->staff(PlatformRole::Finance));
        $event = $this->event($this->organization());
        $order = $this->paidOrder($event, $this->ticketType($event), 3);
        [$kept, $refunded, $moved] = $order->tickets()->get()->all();

        $refunded->update(['status' => 'refunded']);
        $order->update(['status' => 'partially_refunded']);

        app(TicketActions::class)->reissue($moved, $finance, 'chioma@example.com', 'Chioma Obi', true, 'Buyer asked from their own address.');
        $newCode = $moved->fresh()->code;

        Livewire::test(ListOrders::class)
            ->callTableAction('resendTickets', $order)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Tickets email resent');

        Mail::assertQueued(TicketsResent::class, function (TicketsResent $mail) use ($kept, $refunded, $newCode) {
            // As a queue worker would build it: the order read back from the
            // database, not the one handed over.
            $mail = unserialize(serialize($mail));

            $mail->assertSeeInHtml($kept->code);
            $mail->assertDontSeeInHtml($refunded->code);
            $mail->assertDontSeeInHtml($newCode);

            return $mail->hasTo('ada@example.com');
        });
    }

    public function test_an_order_whose_working_tickets_all_moved_away_is_not_resent_to_the_buyer(): void
    {
        $finance = $this->actAs($this->staff(PlatformRole::Finance));
        $event = $this->event($this->organization());
        $order = $this->paidOrder($event, $this->ticketType($event), 1);

        app(TicketActions::class)->reissue($order->tickets()->sole(), $finance, 'chioma@example.com', 'Chioma Obi');

        Livewire::test(ListOrders::class)
            ->callTableAction('resendTickets', $order)
            ->assertNotified('Not done');

        Mail::assertNotQueued(TicketsResent::class);
        $this->assertSame(0, AuditLog::where('action', 'order.tickets_resent')->count());
    }

    public function test_a_door_sale_is_not_refunded_here_but_can_carry_a_note(): void
    {
        $this->actAs($this->staff(PlatformRole::Admin));
        $event = $this->event($this->organization());
        $door = $this->paidOrder($event, $this->ticketType($event), 1, ['channel' => 'door', 'buyer_email' => null]);

        Livewire::test(ListOrders::class)
            ->assertTableActionHidden('refund', $door)
            ->assertTableActionHidden('resendTickets', $door)
            ->callTableAction('addNote', $door, data: ['note' => 'Paid cash; the float was 20 short.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('door', AuditLog::where('action', 'order.staff_note')->sole()->metadata['channel']);
    }
}
