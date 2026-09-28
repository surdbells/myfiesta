<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Filament\Resources\PayoutRequests\Pages\ListPayoutRequests;
use App\Filament\Resources\PayoutRequests\PayoutRequestResource;
use App\Mail\PayoutRequestDecided;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\OrganizationPayoutDetail;
use App\Models\PayoutRequest;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Payouts\PayoutRequestRefused;
use App\Services\Payouts\PayoutRequests;
use App\Services\Payouts\SettlementRecorder;
use App\Services\Payouts\SettlementRefused;
use App\Support\Money;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Organizers asking to be paid, and staff paying or refusing.
 */
class PayoutRequestTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->owner = $this->member(Role::Owner);

        Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);

        OrganizationPayoutDetail::create([
            'organization_id' => $this->org->id,
            'rail' => 'interac',
            'currency' => 'CAD',
            'interac_email' => 'money@lagosnights.test',
            'verified_at' => now(),
            'verification_method' => 'interac_test_transfer',
        ]);
    }

    private function member(Role $role): User
    {
        $user = User::factory()->create();
        $this->org->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => $role->value, 'accepted_at' => now()]);

        return $user->fresh()->load('organizations');
    }

    private function actAs(User $user): void
    {
        Sanctum::actingAs($user->fresh()->load('organizations'), [TokenAbility::Organizer->value]);
    }

    private function staff(PlatformRole $role): User
    {
        return User::factory()->create(['platform_role' => $role, 'email_verified_at' => now()]);
    }

    private function owed(int $amount): void
    {
        LedgerEntry::create(['organization_id' => $this->org->id, 'type' => 'sale', 'amount' => $amount, 'currency' => 'CAD', 'occurred_at' => now()]);
    }

    private function balance(): int
    {
        return (int) LedgerEntry::where('organization_id', $this->org->id)->where('currency', 'CAD')->sum('amount');
    }

    private function ask(int $amount, ?User $as = null): TestResponse
    {
        $this->actAs($as ?? $this->owner);

        return $this->postJson('/api/organizer/payouts/requests', ['amount' => $amount, 'note' => 'For the weekend']);
    }

    // --- asking ---------------------------------------------------------------

    public function test_an_owner_asks_for_what_is_owed_and_sees_it_waiting(): void
    {
        $this->owed(50_000);

        $this->ask(30_000)->assertCreated()->assertJsonPath('data.status', 'pending');

        $request = PayoutRequest::sole();
        $this->assertSame(30_000, $request->amount);
        $this->assertSame(50_000, $request->balance_at_request);
        $this->assertSame($this->owner->id, $request->requested_by);

        // Asking moves no money.
        $this->assertSame(50_000, $this->balance());
        $this->assertSame(0, Settlement::count());

        $this->getJson('/api/organizer/payouts')
            ->assertOk()
            ->assertJsonPath('can_request', true)
            ->assertJsonPath('requests.0.status', 'pending')
            ->assertJsonPath('requests.0.amount.amount', 30_000);

        $this->assertSame(1, AuditLog::where('action', 'payout_request.created')->count());
    }

    public function test_nobody_can_ask_for_more_than_is_owed(): void
    {
        $this->owed(10_000);

        $this->ask(10_001)
            ->assertStatus(422)
            ->assertJsonPath('message', 'You can ask for up to $100.00, which is what you are owed right now.');

        $this->assertSame(0, PayoutRequest::count());
    }

    public function test_nothing_owed_means_nothing_to_ask_for(): void
    {
        $this->ask(1_000)->assertStatus(422)->assertJsonPath('message', 'There is nothing owed to you in CAD right now.');
    }

    public function test_one_open_request_at_a_time(): void
    {
        $this->owed(50_000);

        $this->ask(10_000)->assertCreated();
        $this->ask(10_000)->assertStatus(422);

        $this->assertSame(1, PayoutRequest::count());
    }

    public function test_no_payout_details_no_request(): void
    {
        OrganizationPayoutDetail::query()->delete();
        $this->owed(50_000);

        $this->ask(10_000)->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'Add where to send the money first'));
    }

    public function test_owners_and_finance_ask_and_nobody_else_does(): void
    {
        $this->owed(50_000);

        foreach ([Role::Manager, Role::Marketing, Role::Door] as $role) {
            $this->ask(1_000, $this->member($role))->assertForbidden();
        }

        $this->ask(1_000, $this->member(Role::Finance))->assertCreated();
    }

    public function test_an_organizer_can_withdraw_a_waiting_request_and_ask_again(): void
    {
        $this->owed(50_000);
        $this->ask(10_000)->assertCreated();

        $this->deleteJson('/api/organizer/payouts/requests/'.PayoutRequest::sole()->id)->assertOk();
        $this->assertSame('cancelled', PayoutRequest::sole()->status);

        $this->ask(20_000)->assertCreated();
    }

    public function test_another_organization_cannot_touch_the_request(): void
    {
        $this->owed(50_000);
        $this->ask(10_000)->assertCreated();

        $rival = Organization::create(['name' => 'Rival', 'slug' => 'rival']);
        $stranger = User::factory()->create();
        $rival->members()->attach($stranger->id, ['id' => (string) Str::uuid(), 'role' => 'owner', 'accepted_at' => now()]);
        $this->actAs($stranger);

        $this->deleteJson('/api/organizer/payouts/requests/'.PayoutRequest::sole()->id)->assertNotFound();
        $this->assertSame('pending', PayoutRequest::sole()->status);
    }

    // --- paying ---------------------------------------------------------------

    private function pending(int $asked = 30_000, int $owed = 50_000): PayoutRequest
    {
        $this->owed($owed);
        $this->ask($asked)->assertCreated();

        return PayoutRequest::sole();
    }

    public function test_finance_pays_a_request_and_the_organizer_is_told(): void
    {
        $request = $this->pending();
        $finance = $this->staff(PlatformRole::Finance);

        app(PayoutRequests::class)->pay($request, $finance, new Money(30_000, 'CAD'), 'interac');

        $request->refresh();
        $this->assertSame('paid', $request->status);
        $this->assertSame(30_000, $request->paid_amount);
        $this->assertSame('partial', $request->settlement->type);
        $this->assertSame(20_000, $this->balance());

        Mail::assertQueued(PayoutRequestDecided::class, fn ($mail) => $mail->hasTo($this->owner->email) && $mail->request->is($request));
    }

    public function test_paying_more_than_is_owed_needs_a_reason_of_its_own(): void
    {
        $request = $this->pending(30_000, 50_000);

        foreach ([PlatformRole::Finance, PlatformRole::Admin] as $role) {
            try {
                // A note for the organizer is not the reason for an advance.
                app(PayoutRequests::class)->pay($request, $this->staff($role), new Money(80_000, 'CAD'), 'interac', 'Enjoy the weekend');
                $this->fail($role->value.' gave an overdraft without a reason.');
            } catch (PayoutRequestRefused $refused) {
                $this->assertSame('That pays $300.00 more than they are owed ($500.00). Say why myFiesta is advancing it.', $refused->getMessage());
            }
        }

        // Still open after the refusals: nothing half-recorded.
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(0, Settlement::count());

        app(PayoutRequests::class)->pay($request, $this->staff(PlatformRole::Finance), new Money(80_000, 'CAD'), 'interac', null, 'Advance for the festival, agreed on the phone.');

        $this->assertSame('overdraft', $request->fresh()->settlement->type);
        $this->assertSame(-30_000, $this->balance());
        $this->assertTrue(AuditLog::where('action', 'payout_request.paid')->sole()->metadata['overdraft']);
    }

    public function test_support_cannot_pay_or_reject(): void
    {
        $request = $this->pending();

        $this->expectException(PayoutRequestRefused::class);

        app(PayoutRequests::class)->pay($request, $this->staff(PlatformRole::Support), new Money(30_000, 'CAD'), 'interac');
    }

    public function test_a_request_is_paid_once(): void
    {
        $request = $this->pending();
        $finance = $this->staff(PlatformRole::Finance);

        app(PayoutRequests::class)->pay($request, $finance, new Money(30_000, 'CAD'), 'interac');

        try {
            // A second operator with the same request open in another tab.
            app(PayoutRequests::class)->pay(PayoutRequest::find($request->id), $finance, new Money(30_000, 'CAD'), 'interac');
            $this->fail('A request was paid twice.');
        } catch (PayoutRequestRefused $refused) {
            $this->assertSame('This request was already paid.', $refused->getMessage());
        }

        $this->assertSame(1, Settlement::count());
    }

    public function test_rejecting_needs_a_reason_the_organizer_reads(): void
    {
        $request = $this->pending();
        $finance = $this->staff(PlatformRole::Finance);

        try {
            app(PayoutRequests::class)->reject($request, $finance, '  ');
            $this->fail('Rejected without a reason.');
        } catch (PayoutRequestRefused) {
        }

        app(PayoutRequests::class)->reject($request, $finance, 'Your bank details could not be verified.');

        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertSame(50_000, $this->balance());

        $this->actAs($this->owner);
        $this->getJson('/api/organizer/payouts')->assertJsonPath('requests.0.decision_note', 'Your bank details could not be verified.');

        Mail::assertQueued(PayoutRequestDecided::class);
    }

    // --- the admin screen ---------------------------------------------------

    public function test_the_queue_is_for_admin_and_finance(): void
    {
        $this->actingAs($this->staff(PlatformRole::Admin));
        $this->assertTrue(PayoutRequestResource::canViewAny());

        $this->actingAs($this->staff(PlatformRole::Finance));
        $this->assertTrue(PayoutRequestResource::canViewAny());

        $this->actingAs($this->staff(PlatformRole::Support));
        $this->assertFalse(PayoutRequestResource::canViewAny());
    }

    public function test_paying_from_the_panel(): void
    {
        $request = $this->pending();
        $admin = $this->staff(PlatformRole::Admin);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListPayoutRequests::class)
            ->assertCanSeeTableRecords([$request])
            ->mountTableAction('pay', $request)
            ->assertTableActionDataSet(['amount' => '300.00', 'rail' => 'interac'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame('paid', $request->fresh()->status);
        $this->assertSame($admin->id, $request->fresh()->decided_by);
    }

    /**
     * The total under "Asked for" is money waiting or paid. It added two
     * withdrawn requests to a paid one: $2,437.90 where $100 had been asked
     * for and sent.
     */
    public function test_the_total_counts_what_is_waiting_or_paid_and_nothing_withdrawn_or_refused(): void
    {
        $this->owed(200_000);
        $requests = app(PayoutRequests::class);
        $finance = $this->staff(PlatformRole::Finance);

        $requests->cancel($requests->request($this->org, $this->owner, new Money(116_895, 'CAD')), $this->owner);
        $requests->cancel($requests->request($this->org, $this->owner, new Money(116_895, 'CAD')), $this->owner);
        $requests->reject($requests->request($this->org, $this->owner, new Money(50_000, 'CAD')), $finance, 'Not verified yet.');
        $requests->pay($requests->request($this->org, $this->owner, new Money(10_000, 'CAD')), $finance, new Money(10_000, 'CAD'), 'interac');

        $this->actingAs($this->staff(PlatformRole::Finance));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListPayoutRequests::class)
            ->filterTable('status', null)
            ->assertSee('Waiting or paid')
            ->assertSee('$100.00')
            ->assertDontSee('$2,437.90')
            ->assertDontSee('$2,937.90');
    }

    public function test_rejecting_from_the_panel(): void
    {
        $request = $this->pending();

        $this->actingAs($this->staff(PlatformRole::Finance));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListPayoutRequests::class)
            ->callTableAction('reject', $request, ['reason' => 'Please re-enter your bank details.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('rejected', $request->fresh()->status);
    }

    public function test_the_standalone_settle_action_no_longer_overdraws(): void
    {
        $this->owed(10_000);

        $this->expectException(SettlementRefused::class);
        $this->expectExceptionMessage('only possible when administrators or finance pay');

        app(SettlementRecorder::class)->record(
            $this->org,
            new Money(20_000, 'CAD'),
            'interac',
            'Advance',
            $this->staff(PlatformRole::Admin),
        );
    }
}
