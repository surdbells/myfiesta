<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Models\AuditLog;
use App\Models\ImpersonationSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Open as organization" on the Organizations screen.
 *
 * The rules live in App\Services\Impersonation\Impersonation and are tested
 * in ImpersonationTest; this is the button: who sees it, that it asks why,
 * and that pressing it starts a session under the staff member's own name
 * without minting a token (the console does that, with the code).
 */
class ImpersonateOrganizationActionTest extends TestCase
{
    use RefreshDatabase, SupportFixtures;

    public function test_support_opens_an_organizations_console_with_a_reason(): void
    {
        $support = $this->actAs($this->staff(PlatformRole::Support, ['name' => 'Sade Support']));
        $organization = $this->organization();
        $owner = $this->member($organization, Role::Owner);

        Livewire::test(ListOrganizations::class)
            ->assertTableActionVisible('impersonate', $organization)
            ->callTableAction('impersonate', $organization, data: ['reason' => 'Ticket #4411: the tiers look wrong'])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Staff session started');

        $session = ImpersonationSession::sole();
        $this->assertSame($organization->id, $session->organization_id);
        $this->assertSame($support->id, $session->staff_user_id);
        $this->assertSame('Ticket #4411: the tiers look wrong', $session->reason);
        $this->assertSame('waiting', $session->state());

        // A code waits for the console; no token exists until it is traded.
        $this->assertSame(0, PersonalAccessToken::count());

        $started = AuditLog::where('action', 'impersonation.started')->sole();
        $this->assertSame($support->id, $started->actor_id);
        $this->assertSame($organization->id, $started->subject_id);
        $this->assertSame($organization->id, $started->organization_id);

        // Nobody's membership moved.
        $this->assertSame([$owner->id], $organization->members()->pluck('users.id')->all());
    }

    public function test_the_reason_is_required(): void
    {
        $this->actAs($this->staff(PlatformRole::Admin));
        $organization = $this->organization();

        Livewire::test(ListOrganizations::class)
            ->callTableAction('impersonate', $organization, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        // A word is not a reason.
        Livewire::test(ListOrganizations::class)
            ->callTableAction('impersonate', $organization, data: ['reason' => 'because'])
            ->assertHasTableActionErrors(['reason']);

        $this->assertSame(0, ImpersonationSession::count());
        $this->assertSame(0, AuditLog::where('action', 'impersonation.started')->count());
    }

    public function test_finance_does_not_get_the_button(): void
    {
        $this->actAs($this->staff(PlatformRole::Finance));
        $organization = $this->organization();

        Livewire::test(ListOrganizations::class)
            ->assertTableActionHidden('impersonate', $organization);

        $this->assertSame(0, ImpersonationSession::count());
    }

    public function test_an_account_without_a_staff_role_does_not_get_the_button(): void
    {
        $organization = $this->organization();
        $this->actAs($this->member($organization, Role::Owner, ['email_verified_at' => now()]));

        // Not even the screen: the resource is staff-only. The button checks
        // again, and the service a third time.
        $this->get('/admin/organizations')->assertForbidden();
        $this->assertSame(0, ImpersonationSession::count());
    }
}
