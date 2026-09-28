<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Filament\Pages\PlatformSettings;
use App\Filament\Resources\Disputes\Pages\ViewDispute;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Pages\ReviewEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\TicketsRelationManager;
use App\Filament\Resources\OrganizationIdentityDocuments\Pages\ViewOrganizationIdentityDocument;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Resources\PayoutDetails\Pages\ListPayoutDetails;
use App\Filament\Resources\PayoutRequests\Pages\ListPayoutRequests;
use App\Filament\Resources\Staff\Pages\ListStaff;
use App\Filament\Resources\TaxRates\Pages\CreateTaxRate;
use App\Filament\Resources\TaxRates\Pages\EditTaxRate;
use App\Filament\Resources\TaxRates\Pages\ListTaxRates;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\Dispute;
use App\Models\OrganizationIdentityDocument;
use App\Models\OrganizationPayoutDetail;
use App\Models\PayoutRequest;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Disputes\DisputeDesk;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Nothing in the admin changes anything, or sends anything, on one click.
 *
 * Every action a member of staff can press either leaves the page (a link)
 * or opens a modal first: a confirmation, or a form. And the modal says what
 * is about to happen and to whom — its own heading and description, never
 * Filament's "Are you sure you would like to do this?", which names nothing.
 * The destructive ones are red.
 *
 * Driven from what each screen actually offers, rather than a list of names,
 * so an action added later without a question fails here.
 */
class EveryActionAsksFirstTest extends TestCase
{
    use RefreshDatabase, SupportFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakeGateways();
        $this->actAs($this->staff(PlatformRole::Admin));
    }

    public function test_events_orders_and_tickets_ask_before_anything_happens(): void
    {
        $organization = $this->organization();
        $onSale = $this->event($organization);
        $featured = $this->event($organization, ['title' => 'Owambe', 'is_featured' => true]);
        $takenDown = $this->event($organization, ['title' => 'Detty December', 'taken_down_at' => now(), 'taken_down_reason' => 'The poster belongs to another promoter.', 'status' => 'draft']);
        $type = $this->ticketType($onSale);
        $order = $this->paidOrder($onSale, $type);
        $ticket = $order->tickets()->firstOrFail();

        $this->assertEveryActionAsks(Livewire::test(ListEvents::class), [$onSale, $featured, $takenDown]);
        $this->assertEveryActionAsks(Livewire::test(ViewEvent::class, ['record' => $onSale->getRouteKey()]));
        $this->assertEveryActionAsks(Livewire::test(ViewEvent::class, ['record' => $takenDown->getRouteKey()]));

        $this->assertEveryActionAsks(Livewire::test(ListOrders::class), [$order]);
        $this->assertEveryActionAsks(Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()]));
        $this->assertEveryActionAsks(
            Livewire::test(TicketsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class]),
            [$ticket],
        );

        $this->assertEveryActionAsks(Livewire::test(ListTickets::class), [$ticket]);
        $this->assertEveryActionAsks(Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()]));
    }

    public function test_the_review_asks_before_approving_or_sending_back(): void
    {
        $event = $this->event($this->organization(), ['status' => 'in_review', 'published_at' => null, 'submitted_at' => now()]);
        $this->ticketType($event);

        $this->assertEveryActionAsks(Livewire::test(ReviewEvent::class, ['record' => $event->getKey()]), expect: ['approveEvent', 'rejectEvent']);
    }

    public function test_people_and_organizations_ask_before_anything_happens(): void
    {
        $organization = $this->organization();
        $renamed = $this->organization('Eko Live');
        $renamed->forceFill(['verified_at' => now(), 'verified_name' => 'Eko Live Ltd'])->save();
        $suspended = $this->organization('Owambe');
        $suspended->forceFill(['suspended_at' => now(), 'suspension_reason' => 'Chargebacks on three nights in a row.'])->save();

        $buyer = User::factory()->create(['email_verified_at' => null]);
        $gone = User::factory()->create();
        $gone->delete();

        $this->assertEveryActionAsks(Livewire::test(ListOrganizations::class), [$organization, $renamed], expect: ['confirmName', 'settle']);
        $this->assertEveryActionAsks(Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()]), expect: ['suspend']);
        $this->assertEveryActionAsks(Livewire::test(ViewOrganization::class, ['record' => $suspended->getRouteKey()]), expect: ['unsuspend']);

        $this->assertEveryActionAsks(Livewire::test(ListUsers::class), [$buyer]);
        $this->assertEveryActionAsks(Livewire::test(ViewUser::class, ['record' => $buyer->getRouteKey()]), expect: ['sendPasswordReset', 'resendVerification', 'signOutEverywhere', 'deactivate']);
        $this->assertEveryActionAsks(Livewire::test(ViewUser::class, ['record' => $gone->getRouteKey()]), expect: ['reactivate']);

        $colleague = $this->staff(PlatformRole::Support);
        $this->assertEveryActionAsks(Livewire::test(ListStaff::class), [$colleague], expect: ['grant', 'changeRole', 'revoke']);

        $document = OrganizationIdentityDocument::create([
            'organization_id' => $organization->id,
            'document_type' => 'passport',
            'legal_first_name' => 'Adaeze',
            'legal_last_name' => 'Okafor',
            'date_of_birth' => '1990-04-01',
            'document_number' => 'X0000000',
            'document_path' => 'identity/placeholder.jpg',
            'review_status' => 'pending',
        ]);

        $this->assertEveryActionAsks(
            Livewire::test(ViewOrganizationIdentityDocument::class, ['record' => $document->getRouteKey()]),
            expect: ['viewDocument', 'approve', 'reject'],
        );
    }

    public function test_money_asks_before_anything_moves(): void
    {
        $organization = $this->organization();

        $request = PayoutRequest::create([
            'organization_id' => $organization->id,
            'currency' => 'CAD',
            'amount' => 12500,
            'balance_at_request' => 12500,
            'status' => 'pending',
        ]);

        $details = OrganizationPayoutDetail::create([
            'organization_id' => $organization->id,
            'rail' => 'interac',
            'currency' => 'CAD',
            'interac_email' => 'money@lagosnights.test',
        ]);

        $this->assertEveryActionAsks(Livewire::test(ListPayoutRequests::class), [$request], expect: ['pay', 'reject']);
        $this->assertEveryActionAsks(Livewire::test(ListPayoutDetails::class), [$details], expect: ['verify']);

        $rate = TaxRate::create([
            'country' => 'CA',
            'subdivision' => 'ON',
            'name' => 'HST',
            'rate_bps' => 1300,
            'default_currency' => 'CAD',
            'inclusive' => false,
            'effective_from' => now()->subYear()->startOfDay(),
        ]);

        $this->assertEveryActionAsks(Livewire::test(ListTaxRates::class), [$rate], expect: ['supersede']);
        $this->assertEveryActionAsks(Livewire::test(CreateTaxRate::class), also: ['confirmCreate'], expect: ['confirmCreate']);
        $this->assertEveryActionAsks(Livewire::test(EditTaxRate::class, ['record' => $rate->getRouteKey()]), also: ['confirmSave'], expect: ['confirmSave']);
        $this->assertEveryActionAsks(Livewire::test(PlatformSettings::class), also: ['confirmSave'], expect: ['confirmSave']);
    }

    public function test_a_tax_rate_and_the_settings_are_saved_only_once_it_is_confirmed(): void
    {
        $rate = TaxRate::create([
            'country' => 'CA',
            'subdivision' => 'PE',
            'name' => 'HST',
            'rate_bps' => 1500,
            'default_currency' => 'CAD',
            'inclusive' => false,
            'effective_from' => now()->addYear()->startOfDay(),
        ]);

        // Pressing Save, or Enter in a box, asks — and nothing changes until it is answered.
        Livewire::test(EditTaxRate::class, ['record' => $rate->getRouteKey()])
            ->fillForm(['rate_bps' => 16])
            ->call('confirmSave')
            ->assertActionMounted('confirmSave')
            ->tap(fn (Testable $page) => $this->assertStringContainsString('HST at 16%', (string) $page->instance()->getMountedAction()?->getModalHeading()));

        $this->assertSame(1500, $rate->fresh()->rate_bps);

        Livewire::test(EditTaxRate::class, ['record' => $rate->getRouteKey()])
            ->fillForm(['rate_bps' => 16])
            ->call('confirmSave')
            ->callMountedAction();

        $this->assertSame(1600, $rate->fresh()->rate_bps);

        // A mistake is shown on its field, and nothing is asked.
        Livewire::test(EditTaxRate::class, ['record' => $rate->getRouteKey()])
            ->fillForm(['rate_bps' => 250])
            ->call('confirmSave')
            ->assertHasFormErrors(['rate_bps'])
            ->assertActionNotMounted('confirmSave');

        Livewire::test(PlatformSettings::class)
            ->call('confirmSave')
            ->assertActionMounted('confirmSave');
    }

    public function test_a_dispute_asks_before_its_answer_is_kept_sent_or_given_up(): void
    {
        $order = $this->paidOrder($event = $this->event($this->organization()), $this->ticketType($event));

        $dispute = Dispute::create([
            'order_id' => $order->id,
            'organization_id' => $order->organization_id,
            'event_id' => $order->event_id,
            'gateway' => 'stripe',
            'gateway_reference' => 'dp_asks_first_1',
            'amount' => $order->total_amount,
            'currency' => $order->currency,
            'reason' => 'product_not_received',
            'status' => 'open',
            'opened_at' => now(),
            'evidence_due_at' => now()->addDays(5),
        ]);

        // Put together from the records, as when the dispute arrives: there is a draft to keep and send.
        app(DisputeDesk::class)->build($dispute);

        $this->assertEveryActionAsks(
            Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()]),
            also: ['confirmSaveDraft'],
            expect: ['submit', 'accept', 'confirmSaveDraft'],
        );
    }

    public function test_what_takes_away_or_destroys_is_red(): void
    {
        $organization = $this->organization();
        $event = $this->event($organization);
        $order = $this->paidOrder($event, $this->ticketType($event));
        $ticket = $order->tickets()->firstOrFail();

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionExists('refund', fn (Action $action) => $action->getColor() === 'danger');

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertActionExists('voidTicket', fn (Action $action) => $action->getColor() === 'danger');

        Livewire::test(ViewEvent::class, ['record' => $event->getRouteKey()])
            ->assertActionExists('takeDown', fn (Action $action) => $action->getColor() === 'danger');

        Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()])
            ->assertActionExists('suspend', fn (Action $action) => $action->getColor() === 'danger');

        Livewire::test(ViewUser::class, ['record' => User::factory()->create()->getRouteKey()])
            ->assertActionExists('deactivate', fn (Action $action) => $action->getColor() === 'danger');
    }

    /**
     * Every visible action on the page's header, on each row given, and in
     * its bulk menu either is a link or asks first, naming what it does.
     *
     * @param  iterable<Eloquent>  $rows
     * @param  list<string>  $expect  actions that must be there, so a fixture that hides them fails loudly
     * @param  list<string>  $also  actions reached another way than a button: the form's Enter, say
     */
    private function assertEveryActionAsks(Testable $page, iterable $rows = [], array $expect = [], array $also = []): void
    {
        $page->assertSuccessful();

        $instance = $page->instance();
        $seen = [];

        $header = method_exists($instance, 'getCachedHeaderActions') ? $instance->getCachedHeaderActions() : [];

        foreach ($this->flatten($header) as $action) {
            $seen[] = $this->check($page, $action->getName(), $action->getName());
        }

        foreach ($also as $name) {
            $seen[] = $this->check($page, $name, $name);
        }

        if ($instance instanceof HasTable) {
            $table = $instance->getTable();

            foreach ($rows as $row) {
                foreach (array_keys($table->getFlatActions()) as $name) {
                    $seen[] = $this->check($page, TestAction::make($name)->table($row), $name);
                }
            }

            foreach (array_keys($table->getFlatBulkActions()) as $name) {
                $seen[] = $this->check($page, TestAction::make($name)->table()->bulk(), $name);
            }
        }

        foreach ($expect as $name) {
            $this->assertContains($name, array_filter($seen), "[{$name}] was not offered on ".$instance::class.', so it was not checked.');
        }
    }

    /** The action's name when it is visible and asks first; null when it is hidden or a link. */
    private function check(Testable $page, string|TestAction $action, string $name): ?string
    {
        $offered = null;

        $page->assertActionExists(
            $action,
            function (Action $found) use (&$offered, $name): bool {
                if (! $found->isVisible() || $found->getUrl() !== null) {
                    return true;
                }

                $offered = $name;

                return $found->shouldOpenModal()
                    && $found->hasCustomModalHeading()
                    && filled($description = $found->getModalDescription())
                    && (string) $description !== __('filament-actions::modal.confirmation');
            },
            fn (string $prettyName, string $livewireClass): string => "[{$prettyName}] on {$livewireClass} does something on one click, or asks without saying what: "
                .'give it a modal heading and description that name what happens and to whom.',
        );

        return $offered;
    }

    /**
     * @param  array<Action|ActionGroup>  $actions
     * @return list<Action>
     */
    private function flatten(array $actions): array
    {
        $flat = [];

        foreach ($actions as $action) {
            if ($action instanceof ActionGroup) {
                array_push($flat, ...array_values($action->getFlatActions()));
            } elseif ($action instanceof Action) {
                $flat[] = $action;
            }
        }

        return $flat;
    }
}
