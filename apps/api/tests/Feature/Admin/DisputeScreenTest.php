<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Filament\Resources\Disputes\Pages\ListDisputes;
use App\Filament\Resources\Disputes\Pages\ViewDispute;
use App\Filament\Resources\Disputes\Schemas\DisputeInfolist;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\User;
use App\Services\Disputes\DisputeDesk;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\OpensDisputes;
use Tests\Concerns\SellsTicketsForDisputes;
use Tests\TestCase;

/**
 * The chargeback screens: a list ordered by what needs answering first, and a
 * page that shows the deadline before anything else, what the records hold,
 * every field that will be sent — editable by Admin and Finance until it is —
 * and the buttons that send it or concede it. Support reads all of it.
 */
class DisputeScreenTest extends TestCase
{
    use OpensDisputes, RefreshDatabase, SellsTicketsForDisputes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcessors();

        Mail::fake();
        Storage::fake(DisputeDesk::DISK);
    }

    public function test_the_list_puts_the_closest_deadline_first_marks_it_and_filters_by_what_is_left_to_do(): void
    {
        $this->signIn(PlatformRole::Finance);

        $urgent = $this->dispute('product_not_received', 2);
        $later = $this->dispute('fraudulent', 20);
        $answered = $this->dispute('credit_not_processed', 6);
        $answered->forceFill(['response' => Dispute::SUBMITTED, 'responded_at' => now()])->save();

        $this->assertSame('danger', DisputeInfolist::urgency($urgent));
        $this->assertSame('gray', DisputeInfolist::urgency($later));
        $this->assertSame('gray', DisputeInfolist::urgency($answered->fresh()));

        Livewire::test(ListDisputes::class)
            ->assertCanSeeTableRecords([$urgent, $later, $answered], inOrder: true)
            ->assertTableColumnStateSet('answer', 'Put together, not sent', $urgent)
            ->assertTableColumnStateSet('answer', 'Evidence sent', $answered)
            ->filterTable('deadline', 'three_days')
            ->assertCanSeeTableRecords([$urgent])
            ->assertCanNotSeeTableRecords([$later, $answered])
            ->resetTableFilters()
            ->filterTable('answer', 'submitted')
            ->assertCanSeeTableRecords([$answered])
            ->assertCanNotSeeTableRecords([$urgent, $later])
            ->resetTableFilters()
            ->filterTable('reason', ['fraudulent'])
            ->assertCanSeeTableRecords([$later])
            ->assertCanNotSeeTableRecords([$urgent, $answered])
            ->resetTableFilters()
            ->filterTable('gateway', 'stripe')
            ->assertCanSeeTableRecords([$urgent, $later, $answered])
            ->resetTableFilters()
            ->searchTable($later->order->reference)
            ->assertCanSeeTableRecords([$later])
            ->assertCanNotSeeTableRecords([$urgent])
            ->searchTable('')
            ->sortTable('evidence_due_at', 'desc')
            ->assertCanSeeTableRecords([$later, $answered, $urgent], inOrder: true)
            ->assertTableActionHasUrl('open', DisputeResource::getUrl('view', ['record' => $urgent]), $urgent);
    }

    public function test_the_page_leads_with_the_deadline_and_lets_finance_correct_the_words_and_send_them(): void
    {
        $finance = $this->signIn(PlatformRole::Finance);
        $dispute = $this->dispute('product_not_received', 2);

        $page = Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Answer by')
            ->assertSee('Tickets not received')
            ->assertSee('What wins it')
            ->assertSee('What will be sent to Stripe')
            ->assertSee('Tickets emailed to the buyer')
            ->assertSchemaStateSet(['customer_name' => 'Ada Okafor'], 'form')
            ->assertActionVisible('submit')
            ->assertActionVisible('accept');

        $this->assertStringContainsString('left', (string) $page->instance()->getSubheading());

        $page->fillForm(['customer_name' => 'Ada N. Okafor'], 'form')
            ->call('saveDraft')
            ->assertHasNoFormErrors(form: 'form')
            ->assertNotified('Draft saved');

        $dispute->refresh();
        $this->assertSame('Ada N. Okafor', $dispute->evidence->fields['customer_name']);
        $this->assertSame($finance->id, $dispute->evidence->edited_by);
        $this->assertSame(['customer_name'], AuditLog::query()->where('action', 'dispute.evidence_edited')->sole()->metadata['fields']);

        // What is on the screen is what goes, saved on the way.
        $page->fillForm(['product_description' => 'Two general admission tickets to Afro Fest.'], 'form')
            ->callAction('submit')
            ->assertNotified('Evidence sent');

        $dispute->refresh();
        $this->assertSame(Dispute::SUBMITTED, $dispute->response);
        $this->assertSame('Two general admission tickets to Afro Fest.', $this->sentTo('/v1/disputes/'.$dispute->gateway_reference, 'POST')[0]->data()['evidence']['product_description']);

        // Answered: nothing left to press, nothing left to change.
        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->assertActionHidden('submit')
            ->assertActionHidden('accept')
            ->assertActionHidden('rebuild')
            ->assertSee('What was sent');
    }

    public function test_accepting_from_the_page_asks_why(): void
    {
        $this->signIn(PlatformRole::Admin);
        $dispute = $this->dispute('credit_not_processed', 8);

        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->callAction('accept', data: ['note' => ''])
            ->assertHasActionErrors(['note' => 'required']);

        $this->assertNull($dispute->fresh()->response);
        $this->assertCount(0, $this->sentTo('/close'));

        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->callAction('accept', data: ['note' => 'The organizer promised a refund in writing.'])
            ->assertNotified('Dispute accepted');

        $this->assertSame(Dispute::ACCEPTED, $dispute->fresh()->response);
        $this->assertCount(1, $this->sentTo('/close'));
    }

    public function test_support_reads_the_page_and_can_change_or_send_nothing(): void
    {
        $this->signIn(PlatformRole::Support);
        $dispute = $this->dispute('fraudulent', 5);

        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Answer by')
            ->assertSee('Only Admin and Finance can change these.')
            ->assertSee('Admin and Finance can open these.')
            ->assertActionHidden('submit')
            ->assertActionHidden('accept')
            ->assertActionHidden('rebuild')
            ->assertFormFieldIsDisabled('customer_name', 'form');

        $this->assertNull($dispute->fresh()->response);
    }

    public function test_the_page_never_shows_a_ticket_code(): void
    {
        foreach ([PlatformRole::Admin, PlatformRole::Support] as $role) {
            $this->signIn($role);
            $dispute = $this->dispute('product_not_received', 9);

            $html = $this->get(DisputeResource::getUrl('view', ['record' => $dispute]))->assertSuccessful()->getContent();

            foreach ($this->ticketCodes($dispute->order) as $code) {
                $this->assertStringNotContainsString($code, (string) $html);
            }
        }
    }

    private function signIn(PlatformRole $role): User
    {
        $user = $this->staffMember($role, ucfirst($role->value).' '.random_int(100, 999));

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    private function dispute(string $reason, int $daysLeft): Dispute
    {
        [$order, , $charge] = $this->paidOnStripe();

        return $this->disputeOnStripe($order, $charge, $reason, [
            'evidence_details' => ['due_by' => now()->addDays($daysLeft)->getTimestamp()],
        ]);
    }
}
