<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Resources\PayoutRequests\Pages\ListPayoutRequests;
use App\Filament\Resources\Settlements\Pages\ListSettlements;
use App\Mail\OrganizationSuspended;
use App\Mail\OrganizationUnsuspended;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\OrganizationIdentityDocument;
use App\Models\OrganizationPayoutDetail;
use App\Models\PayoutRequest;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Organizations\Suspension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Organizations screen: found by name, link or owner, filtered by where
 * they sell and how far they are through verification, and opened onto a page
 * that says whether we are selling for them. Suspending one is an
 * administrator's decision, from that page, with a reason.
 */
class OrganizationsScreenTest extends TestCase
{
    use RefreshDatabase, SupportFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_every_staff_role_opens_the_list_and_the_page(): void
    {
        $organization = $this->organization('Toronto Sound');
        $this->member($organization, Role::Owner, ['name' => 'Tunde Owner', 'email' => 'tunde@torontosound.test']);
        $this->event($organization, ['title' => 'Highlife Night']);

        foreach (PlatformRole::cases() as $role) {
            $this->actAs($this->staff($role));

            $this->assertTrue(OrganizationResource::canViewAny(), $role->value.' could not open Organizations.');

            Livewire::test(ListOrganizations::class)
                ->assertCanSeeTableRecords([$organization])
                ->assertTableActionVisible('view', $organization);

            // The page the View button opens, with something on it.
            Livewire::test(ViewOrganization::class, ['record' => $organization->getKey()])
                ->assertSuccessful()
                ->assertSee('Toronto Sound')
                ->assertSee('Key numbers')
                ->assertSee('tunde@torontosound.test');
        }

        $this->actAs(User::factory()->create());
        $this->assertFalse(OrganizationResource::canViewAny());
        $this->get(OrganizationResource::getUrl('view', ['record' => $organization]))->assertForbidden();
    }

    public function test_organizations_are_found_by_name_link_or_owner_email(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));

        $toronto = $this->organization('Toronto Sound');
        $this->member($toronto, Role::Owner, ['email' => 'tunde@example.test']);
        $lagos = $this->organization('Eko Live');
        $this->member($lagos, Role::Manager, ['email' => 'tunde.manager@example.test']);

        Livewire::test(ListOrganizations::class)
            ->searchTable('toronto')
            ->assertCanSeeTableRecords([$toronto])
            ->assertCanNotSeeTableRecords([$lagos])
            ->searchTable($lagos->slug)
            ->assertCanSeeTableRecords([$lagos])
            ->assertCanNotSeeTableRecords([$toronto])
            // An owner's address finds it; a manager's does not stand in for one.
            ->searchTable('tunde@example')
            ->assertCanSeeTableRecords([$toronto])
            ->assertCanNotSeeTableRecords([$lagos])
            // Typed wildcards are letters, not wildcards.
            ->searchTable('%')
            ->assertCanNotSeeTableRecords([$toronto, $lagos]);
    }

    public function test_the_filters_narrow_to_what_they_say(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));

        $toronto = $this->organization('Toronto Sound');
        $this->event($toronto);
        $lagos = $this->organization('Eko Live');
        $this->event($lagos, ['currency' => 'NGN', 'status' => 'draft', 'published_at' => null]);
        $verified = $this->organization('Verified Nights');
        $verified->forceFill(['verified_at' => now(), 'verified_name' => 'Verified Nights'])->save();
        $renamed = $this->organization('Renamed Nights');
        $renamed->forceFill(['verified_at' => now(), 'verified_name' => 'Old Name'])->save();
        $waiting = $this->organization('Waiting Nights');
        OrganizationIdentityDocument::create([
            'organization_id' => $waiting->id,
            'document_type' => 'passport',
            'review_status' => 'pending',
        ]);
        $old = $this->organization('Old Nights');
        $old->forceFill(['created_at' => now()->subYear()])->save();

        app(Suspension::class)->suspend($toronto, $admin, 'Chargebacks on three events in a week.');

        Livewire::test(ListOrganizations::class)
            ->filterTable('country', 'NG')
            ->assertCanSeeTableRecords([$lagos])
            ->assertCanNotSeeTableRecords([$toronto, $verified])
            ->resetTableFilters()
            ->filterTable('currency', 'CAD')
            ->assertCanSeeTableRecords([$toronto])
            ->assertCanNotSeeTableRecords([$lagos])
            ->resetTableFilters()
            ->filterTable('suspended', true)
            ->assertCanSeeTableRecords([$toronto])
            ->assertCanNotSeeTableRecords([$lagos, $verified])
            ->resetTableFilters()
            ->filterTable('suspended', false)
            ->assertCanSeeTableRecords([$lagos, $verified])
            ->assertCanNotSeeTableRecords([$toronto])
            ->resetTableFilters()
            ->filterTable('verification', 'verified')
            ->assertCanSeeTableRecords([$verified])
            ->assertCanNotSeeTableRecords([$renamed, $waiting, $lagos])
            ->resetTableFilters()
            ->filterTable('verification', 'renamed')
            ->assertCanSeeTableRecords([$renamed])
            ->assertCanNotSeeTableRecords([$verified])
            ->resetTableFilters()
            ->filterTable('verification', 'documents_pending')
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$lagos, $verified])
            ->resetTableFilters()
            ->filterTable('verification', 'unverified')
            ->assertCanSeeTableRecords([$lagos, $toronto])
            ->assertCanNotSeeTableRecords([$waiting, $verified, $renamed])
            ->resetTableFilters()
            ->filterTable('joined', ['from' => now()->subYears(2)->toDateString(), 'until' => now()->subMonth()->toDateString()])
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$toronto, $lagos]);

        // "Has events on sale": suspending took Toronto's off.
        app(Suspension::class)->unsuspend($toronto, $admin);
        $this->ticketType($toronto->events()->first());
        $toronto->events()->update(['status' => 'published']);

        // Filters persist in the session; start from none.
        Livewire::test(ListOrganizations::class)
            ->resetTableFilters()
            ->filterTable('on_sale', true)
            ->assertCanSeeTableRecords([$toronto])
            ->assertCanNotSeeTableRecords([$lagos])
            ->resetTableFilters()
            ->filterTable('on_sale', false)
            ->assertCanSeeTableRecords([$lagos])
            ->assertCanNotSeeTableRecords([$toronto]);
    }

    public function test_the_columns_sort_and_the_page_sizes_are_offered(): void
    {
        $this->actAs($this->staff(PlatformRole::Finance));

        $busy = $this->organization('Busy');
        $this->event($busy);
        $this->event($busy);
        $this->member($busy, Role::Owner);
        $quiet = $this->organization('Quiet');

        Livewire::test(ListOrganizations::class)
            ->assertTableColumnStateSet('events_count', 2, $busy)
            ->assertTableColumnStateSet('on_sale_count', 2, $busy)
            ->assertTableColumnStateSet('members_count', 1, $busy)
            ->assertTableColumnStateSet('events_count', 0, $quiet)
            ->sortTable('events_count', 'desc')
            ->assertCanSeeTableRecords([$busy, $quiet], inOrder: true)
            ->sortTable('members_count', 'asc')
            ->assertCanSeeTableRecords([$quiet, $busy], inOrder: true)
            ->sortTable('suspended_at')
            ->assertSuccessful()
            ->sortTable('created_at', 'desc')
            ->assertSuccessful();
    }

    public function test_an_administrator_suspends_from_the_page_and_lifts_it(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));
        $organization = $this->organization('Toronto Sound');
        $this->member($organization, Role::Owner, ['email' => 'owner@torontosound.test']);
        $event = $this->event($organization);
        $this->ticketType($event);

        Livewire::test(ViewOrganization::class, ['record' => $organization->getKey()])
            ->assertActionVisible('suspend')
            ->assertActionHidden('unsuspend')
            ->assertActionVisible('impersonate')
            ->callAction('suspend', data: ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);

        $this->assertFalse($organization->fresh()->isSuspended());

        Livewire::test(ViewOrganization::class, ['record' => $organization->getKey()])
            ->callAction('suspend', data: ['reason' => 'Chargebacks on three events in a week.', 'share_reason' => true])
            ->assertHasNoActionErrors()
            ->assertNotified('Organization suspended');

        $organization->refresh();
        $this->assertTrue($organization->isSuspended());
        $this->assertTrue($organization->suspension_reason_shared);
        $this->assertSame('draft', $event->fresh()->status);
        $this->assertSame($admin->id, AuditLog::where('action', 'organization.suspended')->sole()->actor_id);
        Mail::assertQueued(OrganizationSuspended::class, fn (OrganizationSuspended $mail) => $mail->hasTo('owner@torontosound.test')
            && $mail->reason === 'Chargebacks on three events in a week.');

        Livewire::test(ViewOrganization::class, ['record' => $organization->getKey()])
            ->assertSee('Suspended')
            ->assertSee('Chargebacks on three events in a week.')
            ->assertActionHidden('suspend')
            ->callAction('unsuspend', data: ['note' => 'Bank confirmed they were errors.'])
            ->assertHasNoActionErrors()
            ->assertNotified('Suspension lifted');

        $this->assertFalse($organization->fresh()->isSuspended());
        $this->assertSame('published', $event->fresh()->status);
        Mail::assertQueued(OrganizationUnsuspended::class, fn (OrganizationUnsuspended $mail) => $mail->hasTo('owner@torontosound.test'));
    }

    public function test_support_and_finance_see_a_suspension_but_do_not_get_the_buttons(): void
    {
        $organization = $this->organization('Toronto Sound');
        app(Suspension::class)->suspend($organization, $this->staff(PlatformRole::Admin), 'Kept to ourselves: fraud review.');

        foreach ([PlatformRole::Support, PlatformRole::Finance] as $role) {
            $this->actAs($this->staff($role));

            Livewire::test(ViewOrganization::class, ['record' => $organization->getKey()])
                ->assertSuccessful()
                ->assertSee('Kept to ourselves: fraud review.')
                ->assertActionHidden('suspend')
                ->assertActionHidden('unsuspend');

            Livewire::test(ListOrganizations::class)
                ->filterTable('suspended', true)
                ->assertCanSeeTableRecords([$organization]);
        }

        $this->assertTrue($organization->fresh()->isSuspended());
    }

    public function test_the_page_shows_where_payouts_go_masked_and_only_to_the_roles_that_settle(): void
    {
        $organization = $this->organization('Toronto Sound');
        $this->member($organization, Role::Manager, ['name' => 'Mo Manager']);
        OrganizationPayoutDetail::create([
            'organization_id' => $organization->id,
            'rail' => 'bank_transfer',
            'currency' => 'CAD',
            'account_name' => 'Toronto Sound Inc',
            'bank_name' => 'RBC',
            'account_number' => '000123456789',
            'account_last_four' => '6789',
        ]);

        $this->actAs($this->staff(PlatformRole::Finance));

        Livewire::test(ViewOrganization::class, ['record' => $organization->getKey()])
            ->assertSee('Bank account ending 6789')
            ->assertSee('Not verified')
            ->assertDontSee('000123456789')
            ->assertSee('Mo Manager')
            ->assertSee('Manager');

        $this->actAs($this->staff(PlatformRole::Support));

        Livewire::test(ViewOrganization::class, ['record' => $organization->getKey()])
            ->assertSuccessful()
            ->assertDontSee('Bank account ending 6789');
    }

    public function test_the_money_screens_say_payouts_are_frozen(): void
    {
        $admin = $this->staff(PlatformRole::Admin);
        $this->actAs($this->staff(PlatformRole::Finance));
        $organization = $this->organization('Toronto Sound');
        $owner = $this->member($organization, Role::Owner);
        $this->event($organization);
        LedgerEntry::create(['organization_id' => $organization->id, 'type' => 'sale', 'amount' => 50_000, 'currency' => 'CAD', 'occurred_at' => now()]);
        OrganizationPayoutDetail::create([
            'organization_id' => $organization->id,
            'rail' => 'interac',
            'currency' => 'CAD',
            'interac_email' => 'money@torontosound.test',
            'verified_at' => now(),
            'verification_method' => 'interac_test_transfer',
        ]);

        $request = PayoutRequest::create([
            'organization_id' => $organization->id,
            'currency' => 'CAD',
            'amount' => 20_000,
            'balance_at_request' => 50_000,
            'requested_by' => $owner->id,
            'status' => 'pending',
        ]);

        Settlement::create([
            'organization_id' => $organization->id,
            'amount' => 1_000,
            'currency' => 'CAD',
            'rail' => 'interac',
            'type' => 'partial',
            'status' => 'success',
            'settled_at' => now()->subMonth(),
        ]);

        app(Suspension::class)->suspend($organization, $admin, 'Chargebacks on three events in a week.');

        Livewire::test(ListPayoutRequests::class)
            ->assertCanSeeTableRecords([$request])
            ->assertSee('Held — the organization is suspended')
            ->assertTableActionHidden('pay', $request)
            ->filterTable('held', true)
            ->assertCanSeeTableRecords([$request]);

        Livewire::test(ListSettlements::class)
            ->assertSee('Suspended — payouts frozen');

        Livewire::test(ListOrganizations::class)
            ->assertTableActionHidden('settle', $organization)
            ->assertSee('Payouts frozen');

        app(Suspension::class)->unsuspend($organization, $admin);

        // Filters persist in the session; start from none.
        Livewire::test(ListPayoutRequests::class)
            ->resetTableFilters()
            ->assertTableActionVisible('pay', $request)
            ->assertDontSee('Held — the organization is suspended');
    }
}
