<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Filament\Resources\PayoutDetails\Pages\ListPayoutDetails;
use App\Filament\Resources\PayoutDetails\PayoutDetailResource;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\OrganizationPayoutDetail;
use App\Models\SensitiveDataAccess;
use App\Models\User;
use App\Services\Payouts\PayoutVerificationRefused;
use App\Services\Payouts\PayoutVerifier;
use App\Services\Payouts\SettlementRecorder;
use App\Services\Payouts\SettlementRefused;
use App\Support\Money;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Nobody is paid to a bank account nobody confirmed.
 *
 * The fraud manual settlement invites is simple: get into an organizer's
 * account, change the payout details before a big night settles, and wait.
 */
class PayoutVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private OrganizationPayoutDetail $detail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        Event::factory()->published()->create(['organization_id' => $this->org->id, 'currency' => 'CAD']);

        $this->detail = OrganizationPayoutDetail::create([
            'organization_id' => $this->org->id,
            'rail' => 'bank_transfer',
            'currency' => 'CAD',
            'bank_name' => 'Royal Bank',
            'account_name' => 'Lagos Nights Inc',
            'account_number' => '0012345678',
            'transit_number' => '00012',
            'institution_number' => '003',
        ]);
    }

    private function staff(PlatformRole $role): User
    {
        return User::factory()->create(['platform_role' => $role, 'email_verified_at' => now()]);
    }

    private function verify(?User $by = null, ?string $seen = null, string $method = 'test_deposit'): OrganizationPayoutDetail
    {
        return app(PayoutVerifier::class)->verify(
            $this->detail,
            $by ?? $this->staff(PlatformRole::Finance),
            $method,
            'Two deposits of 0.14 and 0.37 confirmed on a call.',
            $seen ?? $this->detail->fingerprint(),
        );
    }

    // --- the stored number ---------------------------------------------------

    public function test_an_account_number_reads_back_as_it_was_entered(): void
    {
        // It used to read back as s:10:"0012345678"; — the serialized form.
        $this->assertSame('0012345678', $this->detail->fresh()->account_number);
        $this->assertSame('5678', $this->detail->fresh()->account_last_four);
    }

    public function test_numbers_stored_the_old_way_are_repaired(): void
    {
        DB::table('organization_payout_details')->where('id', $this->detail->id)
            ->update(['account_number' => encrypt('0012345678')]);

        $this->assertSame('s:10:"0012345678";', $this->detail->fresh()->account_number);

        $migration = require database_path('migrations/2026_09_13_000200_add_verification_to_payout_details.php');
        $migration->down();
        $migration->up();

        $this->assertSame('0012345678', $this->detail->fresh()->account_number);
    }

    // --- verifying -----------------------------------------------------------

    public function test_finance_verifies_and_the_record_says_who_and_how(): void
    {
        $finance = $this->staff(PlatformRole::Finance);

        $verified = $this->verify($finance);

        $this->assertNotNull($verified->verified_at);
        $this->assertSame($finance->id, $verified->verified_by);
        $this->assertSame('test_deposit', $verified->verification_method);

        $entry = AuditLog::where('action', 'payout_details.verified')->sole();
        $this->assertSame($finance->id, $entry->actor_id);
        $this->assertSame('5678', $entry->metadata['last_four']);
    }

    public function test_support_cannot_see_or_verify_banking_details(): void
    {
        $support = $this->staff(PlatformRole::Support);

        $this->expectException(PayoutVerificationRefused::class);

        app(PayoutVerifier::class)->reveal($this->detail, $support);
    }

    public function test_support_is_refused_the_verification_too(): void
    {
        $this->expectException(PayoutVerificationRefused::class);

        $this->verify($this->staff(PlatformRole::Support));
    }

    public function test_details_changed_after_opening_cannot_be_verified(): void
    {
        $seen = $this->detail->fingerprint();

        // The organizer — or whoever has their password — changes the account
        // while staff are on the phone confirming the old one.
        $this->detail->update(['account_number' => '9990001111']);

        try {
            $this->verify(seen: $seen);
            $this->fail('A verification of the old account landed on the new one.');
        } catch (PayoutVerificationRefused $refused) {
            $this->assertStringContainsString('changed after you opened', $refused->getMessage());
        }

        $this->assertNull($this->detail->fresh()->verified_at);
    }

    public function test_a_member_of_the_organization_cannot_verify_its_own_details(): void
    {
        $insider = $this->staff(PlatformRole::Admin);
        $this->org->members()->attach($insider->id, ['id' => (string) Str::uuid(), 'role' => Role::Owner->value, 'accepted_at' => now()]);

        $this->expectException(PayoutVerificationRefused::class);

        $this->verify($insider);
    }

    public function test_an_interac_transfer_cannot_confirm_a_bank_account(): void
    {
        $this->expectException(PayoutVerificationRefused::class);

        $this->verify(method: 'interac_test_transfer');
    }

    public function test_opening_the_details_is_logged(): void
    {
        $finance = $this->staff(PlatformRole::Finance);

        $details = app(PayoutVerifier::class)->reveal($this->detail, $finance);

        $this->assertSame('0012345678', $details['account_number']);
        $this->assertSame(1, SensitiveDataAccess::where('user_id', $finance->id)->where('action', 'viewed')->count());
    }

    // --- the organizer changing them ----------------------------------------

    /** @return array<string, string> */
    private function sameDetails(array $overrides = []): array
    {
        return array_merge([
            'rail' => 'bank_transfer',
            'bank_name' => 'Royal Bank',
            'account_name' => 'Lagos Nights Inc',
            'account_number' => '0012345678',
            'transit_number' => '00012',
            'institution_number' => '003',
        ], $overrides);
    }

    private function asOwner(): void
    {
        $owner = User::factory()->create();
        $this->org->members()->attach($owner->id, ['id' => (string) Str::uuid(), 'role' => Role::Owner->value, 'accepted_at' => now()]);
        Sanctum::actingAs($owner->fresh()->load('organizations'), [TokenAbility::Organizer->value]);
    }

    public function test_moving_the_same_account_number_to_another_bank_clears_the_verification(): void
    {
        $this->verify();
        $this->asOwner();

        // Same account and transit, different institution: a different bank
        // account. This field was missing from the reset.
        $this->putJson('/api/organizer/payout-details', $this->sameDetails(['institution_number' => '004']))
            ->assertOk()
            ->assertJsonPath('verified_at', null);

        $fresh = $this->detail->fresh();
        $this->assertNull($fresh->verified_by);
        $this->assertNull($fresh->verification_method);
    }

    public function test_saving_the_details_unchanged_keeps_the_verification(): void
    {
        $this->verify();
        $this->asOwner();

        $this->putJson('/api/organizer/payout-details', $this->sameDetails())->assertOk();

        // Re-encrypting the same number must not read as a change.
        $this->assertNotNull($this->detail->fresh()->verified_at);
    }

    // --- settling -----------------------------------------------------------

    private function settle(?string $note = null, string $rail = 'bank_transfer'): void
    {
        LedgerEntry::create([
            'organization_id' => $this->org->id,
            'type' => 'sale',
            'amount' => 50_000,
            'currency' => 'CAD',
            'occurred_at' => now(),
        ]);

        app(SettlementRecorder::class)->record($this->org, new Money(20_000, 'CAD'), $rail, $note, $this->staff(PlatformRole::Finance));
    }

    public function test_a_bank_payout_to_unverified_details_needs_a_reason(): void
    {
        try {
            $this->settle();
            $this->fail('A payout to unverified details was recorded without a word.');
        } catch (SettlementRefused $refused) {
            $this->assertStringContainsString('not verified', $refused->getMessage());
        }

        $this->settle('Organizer confirmed in person at the office; verification to follow.');

        $entry = AuditLog::where('action', 'settlement.recorded')->sole();
        $this->assertFalse($entry->metadata['destination_verified']);
    }

    public function test_a_payout_to_verified_details_needs_no_reason(): void
    {
        $this->verify();

        $this->settle();

        $this->assertTrue(AuditLog::where('action', 'settlement.recorded')->sole()->metadata['destination_verified']);
    }

    public function test_processor_payouts_are_not_held_to_it(): void
    {
        $this->settle(rail: 'stripe');

        $this->assertNull(AuditLog::where('action', 'settlement.recorded')->sole()->metadata['destination_verified']);
    }

    public function test_withdrawing_a_verification_is_recorded_with_its_reason(): void
    {
        $this->verify();

        app(PayoutVerifier::class)->revoke($this->detail->fresh(), $this->staff(PlatformRole::Admin), 'Organizer reported their account was compromised.');

        $this->assertNull($this->detail->fresh()->verified_at);
        $this->assertSame(
            'Organizer reported their account was compromised.',
            AuditLog::where('action', 'payout_details.verification_revoked')->sole()->metadata['reason'],
        );
    }

    // --- the admin screen ----------------------------------------------------

    public function test_the_screen_is_for_admin_and_finance_only(): void
    {
        $this->actingAs($this->staff(PlatformRole::Finance));
        $this->assertTrue(PayoutDetailResource::canViewAny());

        $this->actingAs($this->staff(PlatformRole::Admin));
        $this->assertTrue(PayoutDetailResource::canViewAny());

        $this->actingAs($this->staff(PlatformRole::Support));
        $this->assertFalse(PayoutDetailResource::canViewAny(), 'Support reached organizer bank details.');
    }

    public function test_verifying_from_the_panel_shows_the_details_and_marks_them(): void
    {
        $finance = $this->staff(PlatformRole::Finance);
        $this->actingAs($finance);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListPayoutDetails::class)
            ->assertCanSeeTableRecords([$this->detail])
            ->mountTableAction('verify', $this->detail)
            ->assertTableActionDataSet(['account_number' => '0012345678', 'institution_number' => '003'])
            ->setTableActionData(['method' => 'test_deposit', 'note' => 'Deposits confirmed.'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame($finance->id, $this->detail->fresh()->verified_by);
        $this->assertSame(1, SensitiveDataAccess::where('user_id', $finance->id)->count());
    }
}
