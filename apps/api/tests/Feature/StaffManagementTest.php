<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Filament\Resources\Staff\Pages\ListStaff;
use App\Filament\Resources\Staff\StaffResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Staff\StaffAccess;
use App\Services\Staff\StaffChangeRefused;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Granting, changing and removing staff access — from the terminal and from
 * the admin's Staff screen — under one set of rules, every change audited.
 */
class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    private function person(?PlatformRole $role = null, array $attributes = []): User
    {
        return User::factory()->create([
            'platform_role' => $role,
            'email_verified_at' => now(),
            ...$attributes,
        ]);
    }

    private function onTheStaffScreenAs(User $admin): Testable
    {
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Livewire::test(ListStaff::class);
    }

    private function access(): StaffAccess
    {
        return app(StaffAccess::class);
    }

    // ---- From the terminal ----------------------------------------------

    public function test_the_first_administrator_can_be_made_from_the_terminal(): void
    {
        $ada = $this->person(attributes: ['email' => 'ada@example.com']);

        $this->artisan('staff:grant', ['email' => 'ADA@example.com ', 'role' => 'admin'])
            ->expectsOutputToContain('now has the Administrator role')
            ->assertSuccessful();

        $this->assertSame(PlatformRole::Admin, $ada->fresh()->platform_role);

        $entry = AuditLog::where('action', 'staff.granted')->sole();
        $this->assertSame($ada->id, $entry->subject_id);
        $this->assertNull($entry->actor_id);
        $this->assertSame(['role' => 'admin', 'via' => 'console'], $entry->metadata);
    }

    public function test_the_address_is_matched_whatever_its_capitals(): void
    {
        $this->person(PlatformRole::Admin);
        $grace = $this->person(attributes: ['email' => 'Grace.Hopper@Example.com']);

        $this->artisan('staff:grant', ['email' => 'grace.hopper@example.com', 'role' => 'support'])->assertSuccessful();
        $this->assertSame(PlatformRole::Support, $grace->fresh()->platform_role);

        $this->artisan('staff:revoke', ['email' => 'GRACE.HOPPER@example.com'])->assertSuccessful();
        $this->assertNull($grace->fresh()->platform_role);
    }

    public function test_the_terminal_refuses_a_role_that_does_not_exist(): void
    {
        $this->person(attributes: ['email' => 'ada@example.com']);

        $this->artisan('staff:grant', ['email' => 'ada@example.com', 'role' => 'superuser'])
            ->expectsOutputToContain('admin, finance, support')
            ->assertFailed();

        $this->assertSame(0, AuditLog::count());
    }

    public function test_the_terminal_refuses_an_address_with_no_account(): void
    {
        $this->artisan('staff:grant', ['email' => 'nobody@example.com', 'role' => 'support'])
            ->expectsOutputToContain('No account uses nobody@example.com')
            ->assertFailed();
    }

    public function test_an_unverified_address_cannot_be_given_a_role(): void
    {
        $this->person(attributes: ['email' => 'new@example.com', 'email_verified_at' => null]);

        $this->artisan('staff:grant', ['email' => 'new@example.com', 'role' => 'support'])
            ->expectsOutputToContain('has not been verified')
            ->assertFailed();

        $this->assertNull(User::where('email', 'new@example.com')->value('platform_role'));
    }

    public function test_an_unclaimed_account_cannot_be_given_a_role(): void
    {
        $this->person(attributes: ['email' => 'buyer@example.com', 'password' => null]);

        $this->artisan('staff:grant', ['email' => 'buyer@example.com', 'role' => 'support'])
            ->expectsOutputToContain('never set a password')
            ->assertFailed();
    }

    public function test_granting_to_existing_staff_changes_their_role(): void
    {
        $this->person(PlatformRole::Admin);
        $grace = $this->person(PlatformRole::Support, ['email' => 'grace@example.com']);

        $this->artisan('staff:grant', ['email' => 'grace@example.com', 'role' => 'finance'])->assertSuccessful();

        $this->assertSame(PlatformRole::Finance, $grace->fresh()->platform_role);
        $this->assertSame(
            ['from' => 'support', 'to' => 'finance', 'via' => 'console'],
            AuditLog::where('action', 'staff.role_changed')->sole()->metadata,
        );
    }

    public function test_revoking_from_the_terminal(): void
    {
        $this->person(PlatformRole::Admin);
        $grace = $this->person(PlatformRole::Finance, ['email' => 'grace@example.com']);

        $this->artisan('staff:revoke', ['email' => 'grace@example.com'])
            ->expectsOutputToContain('no longer has the Finance role')
            ->assertSuccessful();

        $this->assertNull($grace->fresh()->platform_role);
        $this->assertSame(
            ['role' => 'finance', 'via' => 'console'],
            AuditLog::where('action', 'staff.revoked')->sole()->metadata,
        );
    }

    public function test_a_deactivated_account_can_have_its_role_revoked_from_the_terminal(): void
    {
        $this->person(PlatformRole::Admin);
        $grace = $this->person(PlatformRole::Finance, ['email' => 'grace@example.com']);
        $grace->delete();

        $this->artisan('staff:revoke', ['email' => 'Grace@example.com'])
            ->expectsOutputToContain('reactivating it will not give the role back')
            ->assertSuccessful();

        $this->assertNull($grace->fresh()->platform_role);
        $this->assertSame(
            ['role' => 'finance', 'via' => 'console', 'account_deactivated' => true],
            AuditLog::where('action', 'staff.revoked')->sole()->metadata,
        );

        // Brought back as somebody's customer account, it is only that.
        $grace->fresh()->restore();
        $this->assertFalse($grace->fresh()->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_a_deactivated_administrator_is_not_the_last_one_who_can_sign_in(): void
    {
        // The only administrator, and deactivated: nobody can sign in as one
        // either way, so taking the role off leaves nobody worse placed.
        $gone = $this->person(PlatformRole::Admin, ['email' => 'gone@example.com']);
        $gone->delete();

        $this->artisan('staff:revoke', ['email' => 'gone@example.com'])->assertSuccessful();

        $this->assertNull($gone->fresh()->platform_role);
    }

    public function test_a_deactivated_account_can_only_be_revoked_not_given_another_role(): void
    {
        $admin = $this->person(PlatformRole::Admin);
        $sam = $this->person(PlatformRole::Support);
        $sam->delete();

        try {
            $this->access()->changeRole($sam, PlatformRole::Finance, $admin);
            $this->fail('A deactivated account was given a new role.');
        } catch (StaffChangeRefused $refused) {
            $this->assertStringContainsString('deactivated', $refused->getMessage());
        }

        $this->assertSame(PlatformRole::Support, $sam->fresh()->platform_role);
    }

    public function test_revoking_somebody_who_is_not_staff_changes_nothing(): void
    {
        $this->person(attributes: ['email' => 'buyer@example.com']);

        $this->artisan('staff:revoke', ['email' => 'buyer@example.com'])
            ->expectsOutputToContain('Nothing changed')
            ->assertSuccessful();

        $this->assertSame(0, AuditLog::count());
    }

    public function test_the_last_administrator_cannot_be_revoked(): void
    {
        $only = $this->person(PlatformRole::Admin, ['email' => 'only@example.com']);
        // An administrator who cannot sign in does not count.
        $this->person(PlatformRole::Admin, ['email_verified_at' => null]);

        $this->artisan('staff:revoke', ['email' => 'only@example.com'])
            ->expectsOutputToContain('last administrator')
            ->assertFailed();

        $this->assertSame(PlatformRole::Admin, $only->fresh()->platform_role);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_the_last_administrator_cannot_be_demoted_either(): void
    {
        $this->person(PlatformRole::Admin, ['email' => 'only@example.com']);

        $this->artisan('staff:grant', ['email' => 'only@example.com', 'role' => 'support'])
            ->expectsOutputToContain('last administrator')
            ->assertFailed();

        $this->assertSame(PlatformRole::Admin, User::where('email', 'only@example.com')->sole()->platform_role);
    }

    public function test_with_a_second_administrator_the_first_can_go(): void
    {
        $this->person(PlatformRole::Admin);
        $leaving = $this->person(PlatformRole::Admin, ['email' => 'leaving@example.com']);

        $this->artisan('staff:revoke', ['email' => 'leaving@example.com'])->assertSuccessful();

        $this->assertNull($leaving->fresh()->platform_role);
    }

    // ---- The rules, whoever asks ----------------------------------------

    public function test_only_an_administrator_may_change_access(): void
    {
        $finance = $this->person(PlatformRole::Finance);
        $support = $this->person(PlatformRole::Support);

        $this->expectException(StaffChangeRefused::class);
        $this->expectExceptionMessage('Only an administrator');

        $this->access()->changeRole($support, PlatformRole::Finance, $finance);
    }

    public function test_an_administrator_demoted_a_moment_ago_cannot_finish_a_change(): void
    {
        $this->person(PlatformRole::Admin);
        $stale = $this->person(PlatformRole::Admin);
        $support = $this->person(PlatformRole::Support);

        // Their User object still says admin; the database no longer does.
        DB::table('users')->where('id', $stale->id)->update(['platform_role' => 'support']);

        $this->expectException(StaffChangeRefused::class);

        $this->access()->revoke($support, $stale);
    }

    public function test_nobody_changes_their_own_access(): void
    {
        $this->person(PlatformRole::Admin);
        $me = $this->person(PlatformRole::Admin);

        try {
            $this->access()->changeRole($me, PlatformRole::Support, $me);
            $this->fail('Changed their own role.');
        } catch (StaffChangeRefused $refused) {
            $this->assertStringContainsString('your own access', $refused->getMessage());
        }

        $this->expectException(StaffChangeRefused::class);
        $this->access()->revoke($me, $me);
    }

    public function test_revoking_ends_every_admin_session_and_remember_cookie_at_once(): void
    {
        config(['session.driver' => 'database']);

        $admin = $this->person(PlatformRole::Admin);
        $leaving = $this->person(PlatformRole::Support);
        $token = $leaving->getRememberToken();

        $session = fn (?string $userId) => [
            'id' => Str::random(40),
            'user_id' => $userId,
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => now()->getTimestamp(),
        ];
        DB::table('sessions')->insert([$session($leaving->id), $session($leaving->id), $session($admin->id)]);

        $this->access()->revoke($leaving, $admin);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $leaving->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $admin->id)->count(), 'Somebody else was signed out.');
        $this->assertNotSame($token, $leaving->fresh()->getRememberToken());
    }

    // ---- The admin's Staff screen --------------------------------------

    public function test_only_administrators_see_the_staff_screen(): void
    {
        foreach ([PlatformRole::Finance, PlatformRole::Support] as $role) {
            $this->actingAs($this->person($role));

            $this->assertFalse(StaffResource::canViewAny(), "{$role->value} could see the staff screen.");
            $this->get('/admin/staff')->assertForbidden();
        }

        $this->actingAs($this->person(PlatformRole::Admin));
        $this->assertTrue(StaffResource::canViewAny());
        $this->get('/admin/staff')->assertOk();
    }

    public function test_the_staff_screen_lists_staff_and_nobody_else(): void
    {
        $admin = $this->person(PlatformRole::Admin);
        $finance = $this->person(PlatformRole::Finance);
        $buyer = $this->person();

        $this->onTheStaffScreenAs($admin)
            ->assertCanSeeTableRecords([$admin, $finance])
            ->assertCanNotSeeTableRecords([$buyer]);
    }

    public function test_the_staff_screen_searches_filters_and_sorts(): void
    {
        $admin = $this->person(PlatformRole::Admin, ['name' => 'Ada Admin']);
        $grace = $this->person(PlatformRole::Finance, ['name' => 'Grace Finance', 'email' => 'grace@example.com', 'last_login_at' => now()->subDays(3)]);
        $sam = $this->person(PlatformRole::Support, ['name' => 'Sam Support', 'last_login_at' => null]);

        $screen = $this->onTheStaffScreenAs($admin);

        $screen->searchTable('grace@')
            ->assertCanSeeTableRecords([$grace])
            ->assertCanNotSeeTableRecords([$admin, $sam])
            ->searchTable('');

        $screen->filterTable('platform_role', [PlatformRole::Support])
            ->assertCanSeeTableRecords([$sam])
            ->assertCanNotSeeTableRecords([$admin, $grace])
            ->resetTableFilters();

        $screen->filterTable('signed_in', false)
            ->assertCanSeeTableRecords([$sam])
            ->assertCanNotSeeTableRecords([$grace])
            ->resetTableFilters();

        $screen->filterTable('last_signed_in', ['from' => now()->subWeek()->toDateString(), 'until' => now()->toDateString()])
            ->assertCanSeeTableRecords([$grace])
            ->assertCanNotSeeTableRecords([$sam])
            ->resetTableFilters();

        $screen->sortTable('name')
            ->assertCanSeeTableRecords([$admin, $grace, $sam], inOrder: true);
    }

    public function test_changing_a_role_from_the_screen(): void
    {
        $admin = $this->person(PlatformRole::Admin);
        $sam = $this->person(PlatformRole::Support);

        $this->onTheStaffScreenAs($admin)
            ->callTableAction('changeRole', $sam, ['platform_role' => 'finance'])
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertSame(PlatformRole::Finance, $sam->fresh()->platform_role);

        $entry = AuditLog::where('action', 'staff.role_changed')->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame($sam->id, $entry->subject_id);
        $this->assertSame(['from' => 'support', 'to' => 'finance', 'via' => 'admin'], $entry->metadata);
    }

    public function test_revoking_from_the_screen(): void
    {
        $admin = $this->person(PlatformRole::Admin);
        $sam = $this->person(PlatformRole::Support);

        $this->onTheStaffScreenAs($admin)
            ->callTableAction('revoke', $sam)
            ->assertHasNoTableActionErrors();

        $this->assertNull($sam->fresh()->platform_role);

        $entry = AuditLog::where('action', 'staff.revoked')->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame(['role' => 'support', 'via' => 'admin'], $entry->metadata);
    }

    public function test_deactivated_staff_are_found_behind_a_filter_and_can_be_revoked_there(): void
    {
        $admin = $this->person(PlatformRole::Admin);
        $sam = $this->person(PlatformRole::Support);
        $sam->delete();

        $screen = $this->onTheStaffScreenAs($admin)
            ->assertCanSeeTableRecords([$admin])
            ->assertCanNotSeeTableRecords([$sam]);

        $screen->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$sam])
            ->assertCanNotSeeTableRecords([$admin])
            ->assertTableActionHidden('changeRole', $sam)
            ->assertTableActionVisible('revoke', $sam)
            ->callTableAction('revoke', $sam)
            ->assertHasNoTableActionErrors();

        $this->assertNull($sam->fresh()->platform_role);
        $this->assertSame($admin->id, AuditLog::where('action', 'staff.revoked')->sole()->actor_id);
    }

    public function test_your_own_row_offers_no_way_to_change_your_access(): void
    {
        $admin = $this->person(PlatformRole::Admin);
        $other = $this->person(PlatformRole::Admin);

        $this->onTheStaffScreenAs($admin)
            ->assertTableActionHidden('changeRole', $admin)
            ->assertTableActionHidden('revoke', $admin)
            ->assertTableActionVisible('changeRole', $other)
            ->assertTableActionVisible('revoke', $other);
    }

    public function test_granting_access_by_email_from_the_screen(): void
    {
        $admin = $this->person(PlatformRole::Admin);
        $newcomer = $this->person(attributes: ['email' => 'newcomer@example.com']);

        $this->onTheStaffScreenAs($admin)
            ->callAction('grant', ['email' => 'newcomer@example.com', 'platform_role' => 'support'])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame(PlatformRole::Support, $newcomer->fresh()->platform_role);

        $entry = AuditLog::where('action', 'staff.granted')->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame(['role' => 'support', 'via' => 'admin'], $entry->metadata);
    }

    public function test_granting_to_an_address_with_no_account_is_refused_on_the_screen(): void
    {
        $admin = $this->person(PlatformRole::Admin);

        $this->onTheStaffScreenAs($admin)
            ->callAction('grant', ['email' => 'nobody@example.com', 'platform_role' => 'admin'])
            ->assertNotified('Access not granted');

        $this->assertSame(0, AuditLog::where('action', 'staff.granted')->count());
        $this->assertSame(1, User::whereNotNull('platform_role')->count());
    }

    public function test_the_staff_screen_offers_no_bulk_actions_or_deletion(): void
    {
        $this->actingAs($this->person(PlatformRole::Admin));

        $this->assertFalse(StaffResource::canCreate());
        $this->assertFalse(StaffResource::canDeleteAny());
        $this->assertFalse(StaffResource::canEdit($this->person(PlatformRole::Support)));
    }
}
