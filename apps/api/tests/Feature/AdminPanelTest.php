<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Filament\Resources\OrganizationIdentityDocuments\OrganizationIdentityDocumentResource;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\Settlements\SettlementResource;
use App\Filament\Resources\TaxRates\TaxRateResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin panel, exercised rather than assumed.
 *
 * Resource classes pass a syntax check whether or not their authorization does
 * anything, so these boot the panel for real and check who gets in.
 */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function staff(?PlatformRole $role): User
    {
        return User::factory()->create([
            'platform_role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    public function test_an_ordinary_account_is_refused_the_panel(): void
    {
        // 403 rather than a redirect, which is the right answer: they are
        // signed in, and being signed in is exactly what is not enough here.
        // Anyone who ever bought a ticket holds a valid account.
        $buyer = $this->staff(null);

        $this->actingAs($buyer)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get('/admin')->assertRedirect();
    }

    public function test_platform_staff_reach_the_dashboard(): void
    {
        $this->actingAs($this->staff(PlatformRole::Admin))
            ->get('/admin')
            ->assertSuccessful();
    }

    public function test_support_cannot_see_settlements(): void
    {
        $this->actingAs($this->staff(PlatformRole::Support));

        $this->assertFalse(
            SettlementResource::canViewAny(),
            'Support reached the settlement ledger.'
        );
    }

    public function test_finance_can_see_settlements(): void
    {
        $this->actingAs($this->staff(PlatformRole::Finance));

        $this->assertTrue(SettlementResource::canViewAny());
    }

    public function test_finance_cannot_review_identity_documents(): void
    {
        $this->actingAs($this->staff(PlatformRole::Finance));

        $this->assertFalse(
            OrganizationIdentityDocumentResource::canViewAny(),
            'Finance reached the identity queue. Settling money is not reviewing ID.'
        );
    }

    public function test_support_can_review_identity_documents(): void
    {
        $this->actingAs($this->staff(PlatformRole::Support));

        $this->assertTrue(OrganizationIdentityDocumentResource::canViewAny());
    }

    public function test_support_cannot_change_tax_rates(): void
    {
        $this->actingAs($this->staff(PlatformRole::Support));

        $this->assertTrue(TaxRateResource::canViewAny(), 'Support should still be able to look.');
        $this->assertFalse(TaxRateResource::canCreate(), 'Support was able to create a tax rate.');
    }

    public function test_nothing_financial_can_be_deleted(): void
    {
        $this->actingAs($this->staff(PlatformRole::Admin));

        // Corrections are made by reversal and supersession. Deleting a
        // settlement or a tax rate would orphan the orders pointing at it.
        $this->assertFalse(SettlementResource::canDeleteAny());
        $this->assertFalse(TaxRateResource::canDeleteAny());
        $this->assertFalse(OrganizationResource::canDeleteAny());
    }

    public function test_settlements_and_organizations_are_never_edited_in_place(): void
    {
        $this->actingAs($this->staff(PlatformRole::Admin));

        $this->assertFalse(SettlementResource::canCreate());
        $this->assertFalse(OrganizationResource::canCreate());
        $this->assertFalse(OrganizationIdentityDocumentResource::canCreate());
        $this->assertFalse(OrganizationIdentityDocumentResource::canEdit(null));
    }
}
