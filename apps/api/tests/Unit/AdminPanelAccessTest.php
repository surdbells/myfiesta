<?php

namespace Tests\Unit;

use App\Enums\PlatformRole;
use App\Models\User;
use Filament\Panel;
use Tests\TestCase;

/**
 * Who can open the admin panel.
 *
 * One users table holds attendees, organization members, and staff, which is
 * the right model but leaves the admin panel one gate away from being open to
 * anyone who ever bought a ticket. Filament is satisfied by a valid account;
 * this is what makes it demand more than that.
 */
class AdminPanelAccessTest extends TestCase
{
    private function panel(): Panel
    {
        return Panel::make()->id('admin');
    }

    private function user(?PlatformRole $role, bool $verified = true): User
    {
        $user = new User;
        $user->id = '11111111-1111-1111-1111-111111111111';
        $user->email = 'person@example.com';
        $user->platform_role = $role;
        $user->email_verified_at = $verified ? now() : null;

        return $user;
    }

    public function test_a_ticket_buyer_cannot_open_the_admin_panel(): void
    {
        $this->assertFalse(
            $this->user(null)->canAccessPanel($this->panel()),
            'An ordinary account reached the admin panel.'
        );
    }

    public function test_an_organization_member_is_not_platform_staff(): void
    {
        // Running events is membership of an organization. It says nothing
        // about working for myFiesta.
        $this->assertFalse($this->user(null)->isPlatformStaff());
    }

    public function test_platform_staff_can_open_the_panel(): void
    {
        foreach (PlatformRole::cases() as $role) {
            $this->assertTrue(
                $this->user($role)->canAccessPanel($this->panel()),
                "{$role->value} was refused the admin panel."
            );
        }
    }

    public function test_an_unverified_email_is_refused_even_with_a_platform_role(): void
    {
        $this->assertFalse(
            $this->user(PlatformRole::Admin, verified: false)->canAccessPanel($this->panel()),
            'An unverified address reached the admin panel.'
        );
    }

    public function test_support_cannot_settle_or_read_banking_details(): void
    {
        $support = PlatformRole::Support;

        $this->assertFalse($support->canSettle());
        $this->assertFalse($support->canReadSensitiveData());
        // Support does review identity documents — that is the job.
        $this->assertTrue($support->canReviewIdentityDocuments());
    }

    public function test_finance_settles_but_does_not_review_identity(): void
    {
        $finance = PlatformRole::Finance;

        $this->assertTrue($finance->canSettle());
        $this->assertTrue($finance->canReadSensitiveData());
        $this->assertFalse($finance->canReviewIdentityDocuments());
    }

    public function test_has_platform_role_is_exact(): void
    {
        $finance = $this->user(PlatformRole::Finance);

        $this->assertTrue($finance->hasPlatformRole(PlatformRole::Finance));
        $this->assertTrue($finance->hasPlatformRole(PlatformRole::Admin, PlatformRole::Finance));
        $this->assertFalse($finance->hasPlatformRole(PlatformRole::Admin));
        $this->assertFalse($this->user(null)->hasPlatformRole(PlatformRole::Admin));
    }
}
