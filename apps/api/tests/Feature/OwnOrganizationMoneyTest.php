<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Filament\Pages\Overdrafts as OverdraftsPage;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\PayoutDetails\Pages\ListPayoutDetails;
use App\Filament\Resources\PayoutRequests\Pages\ListPayoutRequests;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\OrganizationPayoutDetail;
use App\Models\PayoutRequest;
use App\Models\Repayment;
use App\Models\SensitiveDataAccess;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Payouts\Overdrafts;
use App\Services\Payouts\OwnOrganization;
use App\Services\Payouts\PayoutRequestRefused;
use App\Services\Payouts\PayoutRequests;
use App\Services\Payouts\PayoutVerificationRefused;
use App\Services\Payouts\PayoutVerifier;
use App\Services\Payouts\RepaymentRefused;
use App\Services\Payouts\SettlementRecorder;
use App\Services\Payouts\SettlementRefused;
use App\Support\Money;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

/**
 * Nobody decides money for their own organization.
 *
 * Found by clicking through the admin as the owner of Lagos Nights who had
 * also been made an administrator: she asked for $100 in the console, then
 * paid herself $1,200 from the admin — $31.05 of it an advance from myFiesta,
 * to bank details nobody had checked — and every step went through, because
 * paying asked only whether she was staff. Verifying where the money goes
 * already refused a member of the organization; paying it, recording it,
 * crediting a repayment and opening the details now do too, and the buttons
 * say so before they are pressed.
 */
class OwnOrganizationMoneyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    /** Owner of the organization, and an administrator here. */
    private User $ada;

    private OrganizationPayoutDetail $details;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->ada = $this->staff(PlatformRole::Admin, ['name' => 'Ada Okoro']);
        $this->org->members()->attach($this->ada->id, ['id' => (string) Str::uuid(), 'role' => Role::Owner->value, 'accepted_at' => now()]);

        $this->details = OrganizationPayoutDetail::create([
            'organization_id' => $this->org->id,
            'rail' => 'bank_transfer',
            'currency' => 'CAD',
            'account_name' => 'Lagos Nights Inc',
            'bank_name' => 'RBC',
            'account_number' => '000123456789',
            'account_last_four' => '6789',
        ]);

        LedgerEntry::create(['organization_id' => $this->org->id, 'type' => 'sale', 'amount' => 116_895, 'currency' => 'CAD', 'occurred_at' => now()]);
    }

    private function staff(PlatformRole $role, array $attributes = []): User
    {
        return User::factory()->create(['platform_role' => $role, 'email_verified_at' => now(), ...$attributes]);
    }

    private function request(int $amount = 10_000): PayoutRequest
    {
        return PayoutRequest::create([
            'organization_id' => $this->org->id,
            'currency' => 'CAD',
            'amount' => $amount,
            'balance_at_request' => 116_895,
            'requested_by' => $this->ada->id,
            'status' => 'pending',
        ]);
    }

    private function inPanel(User $staff): void
    {
        $this->actingAs($staff);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function refusedWith(string $sentence, Closure $attempt): void
    {
        try {
            $attempt();
        } catch (Throwable $refused) {
            $this->assertSame($sentence, $refused->getMessage());

            return;
        }

        $this->fail('It went through: '.$sentence);
    }

    public function test_staff_on_the_team_cannot_pay_their_own_organizations_request_advance_or_not(): void
    {
        $request = $this->request();

        // What happened in the click-through: $1,200 against $1,168.95 owed.
        $this->refusedWith(OwnOrganization::DECIDE_REQUEST, fn () => app(PayoutRequests::class)->pay(
            $request,
            $this->ada,
            new Money(120_000, 'CAD'),
            'bank_transfer',
            'Sent by e-Transfer.',
            'Advance agreed for the weekend.',
        ));

        $this->refusedWith(OwnOrganization::DECIDE_REQUEST, fn () => app(PayoutRequests::class)->pay(
            $request,
            $this->ada,
            new Money(10_000, 'CAD'),
            'bank_transfer',
            'Sent by e-Transfer.',
        ));

        $this->assertTrue($request->fresh()->isPending());
        $this->assertSame(0, Settlement::count());
        $this->assertSame(116_895, (int) LedgerEntry::where('organization_id', $this->org->id)->sum('amount'));
        $this->assertSame(0, AuditLog::whereIn('action', ['payout_request.paid', 'payout_request.overdraft_approved', 'settlement.recorded'])->count());
        Mail::assertNothingQueued();
    }

    public function test_nor_refuse_it(): void
    {
        $request = $this->request();

        $this->refusedWith(OwnOrganization::DECIDE_REQUEST, fn () => app(PayoutRequests::class)->reject($request, $this->ada, 'Not this week.'));

        $this->assertTrue($request->fresh()->isPending());
    }

    public function test_somebody_else_at_myfiesta_still_can(): void
    {
        $request = $this->request();

        $paid = app(PayoutRequests::class)->pay($request, $this->staff(PlatformRole::Finance), new Money(10_000, 'CAD'), 'bank_transfer', 'Sent by transfer.');

        $this->assertSame('paid', $paid->status);
    }

    public function test_nor_record_a_payout_to_it_or_a_repayment_from_it(): void
    {
        $this->refusedWith(OwnOrganization::RECORD_PAYOUT, fn () => app(SettlementRecorder::class)->record(
            $this->org,
            new Money(5_000, 'CAD'),
            'bank_transfer',
            'Sent.',
            $this->ada,
        ));

        $this->assertSame(0, Settlement::count());

        // Below zero, so there is something a repayment could be recorded against.
        LedgerEntry::create(['organization_id' => $this->org->id, 'type' => 'refund', 'amount' => -150_000, 'currency' => 'CAD', 'occurred_at' => now()]);

        $this->refusedWith(OwnOrganization::RECORD_REPAYMENT, fn () => app(Overdrafts::class)->recordRepayment(
            $this->org,
            $this->ada,
            new Money(1_000, 'CAD'),
            'BANK-1',
            'Paid back by the owner.',
        ));

        $this->assertSame(0, Repayment::count());
    }

    public function test_nor_open_its_payout_details_which_they_may_not_verify(): void
    {
        $this->refusedWith(OwnOrganization::VERIFY_DETAILS, fn () => app(PayoutVerifier::class)->reveal($this->details, $this->ada));

        // Nothing was read, so nothing says it was.
        $this->assertSame(0, SensitiveDataAccess::count());
        $this->assertSame(0, AuditLog::where('action', 'payout_details.revealed')->count());

        $this->refusedWith(OwnOrganization::VERIFY_DETAILS, fn () => app(PayoutVerifier::class)->verify(
            $this->details,
            $this->ada,
            'confirmed_by_phone',
            null,
            $this->details->fingerprint(),
        ));

        // Somebody else opens them, logged.
        $finance = $this->staff(PlatformRole::Finance);
        $this->assertSame('000123456789', app(PayoutVerifier::class)->reveal($this->details, $finance)['account_number']);
        $this->assertSame(1, SensitiveDataAccess::where('user_id', $finance->id)->count());
    }

    public function test_the_admin_says_so_on_the_buttons_before_they_are_pressed(): void
    {
        $request = $this->request();
        LedgerEntry::create(['organization_id' => $this->org->id, 'type' => 'refund', 'amount' => -150_000, 'currency' => 'CAD', 'occurred_at' => now()]);

        $this->inPanel($this->ada);

        Livewire::test(ListPayoutRequests::class)
            ->assertTableActionDisabled('pay', $request)
            ->assertTableActionDisabled('reject', $request)
            ->assertSee(OwnOrganization::DECIDE_REQUEST);

        Livewire::test(ListPayoutDetails::class)
            ->assertTableActionDisabled('verify', $this->details)
            ->assertSee(OwnOrganization::VERIFY_DETAILS);

        Livewire::test(ListOrganizations::class)
            ->assertTableActionDisabled('settle', $this->org)
            ->assertSee(OwnOrganization::RECORD_PAYOUT);

        Livewire::test(OverdraftsPage::class)
            ->assertTableActionDisabled('recordRepayment', $this->org->id.':CAD')
            ->assertSee(OwnOrganization::RECORD_REPAYMENT);

        // Nothing opened the details on the way.
        $this->assertSame(0, SensitiveDataAccess::count());

        // Another administrator gets them all.
        $this->inPanel($this->staff(PlatformRole::Admin));

        Livewire::test(ListPayoutRequests::class)
            ->assertTableActionEnabled('pay', $request)
            ->assertTableActionEnabled('reject', $request);

        Livewire::test(ListPayoutDetails::class)->assertTableActionEnabled('verify', $this->details);
        Livewire::test(ListOrganizations::class)->assertTableActionEnabled('settle', $this->org);
        Livewire::test(OverdraftsPage::class)->assertTableActionEnabled('recordRepayment', $this->org->id.':CAD');
    }

    public function test_each_refusal_is_the_type_its_screen_catches(): void
    {
        $request = $this->request();
        $caught = [];

        foreach ([
            PayoutRequestRefused::class => fn () => app(PayoutRequests::class)->pay($request, $this->ada, new Money(10_000, 'CAD'), 'bank_transfer', 'Sent.'),
            SettlementRefused::class => fn () => app(SettlementRecorder::class)->record($this->org, new Money(5_000, 'CAD'), 'bank_transfer', 'Sent.', $this->ada),
            RepaymentRefused::class => fn () => app(Overdrafts::class)->recordRepayment($this->org, $this->ada, new Money(1_000, 'CAD'), 'BANK-1', 'Paid back.'),
            PayoutVerificationRefused::class => fn () => app(PayoutVerifier::class)->reveal($this->details, $this->ada),
        ] as $type => $attempt) {
            try {
                $attempt();
            } catch (Throwable $refused) {
                $caught[$type] = $refused::class;
            }
        }

        $this->assertSame([
            PayoutRequestRefused::class => PayoutRequestRefused::class,
            SettlementRefused::class => SettlementRefused::class,
            RepaymentRefused::class => RepaymentRefused::class,
            PayoutVerificationRefused::class => PayoutVerificationRefused::class,
        ], $caught);
    }
}
