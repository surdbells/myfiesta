<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Filament\Pages\Overdrafts as OverdraftsPage;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Resources\PayoutRequests\Pages\ListPayoutRequests;
use App\Filament\Resources\Settlements\Pages\ListSettlements;
use App\Mail\PayoutRequestDecided;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\OrganizationPayoutDetail;
use App\Models\PayoutRequest;
use App\Models\Repayment;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Analytics\OrganizerMetrics;
use App\Services\Analytics\Period;
use App\Services\Analytics\PlatformMetrics;
use App\Services\Payouts\OverdraftOutstanding;
use App\Services\Payouts\Overdrafts;
use App\Services\Payouts\PayoutRequestRefused;
use App\Services\Payouts\PayoutRequests;
use App\Services\Payouts\RepaymentRefused;
use App\Services\Payouts\SettlementRefused;
use App\Services\PersonalData\Eraser;
use App\Services\PersonalData\Subject;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Paying a payout request for more than is owed, and getting it back.
 *
 * The operator's words: allow the overdraft when staff approve a request, and
 * make sure it is on the record and fully accounted for. So, from the moment
 * of paying: who approved how much beyond the balance and why, on the request
 * and in the audit trail; the ledger recording the payout as it always has,
 * so the balance goes below zero by exactly the advance; the next sales
 * paying it back, visibly, at every step; nothing more paid out meanwhile;
 * money sent back to us recorded as its own credit; and nothing that would
 * leave the debt with nobody — closing the organization, erasing its only
 * owner — allowed while it is owed.
 */
class PayoutOverdraftTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // 15:00 in Toronto on the 12th, so the day in every sentence is the
        // 12th wherever it is read from.
        $this->travelTo(CarbonImmutable::parse('2026-10-12 19:00:00', 'UTC'));

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

        foreach (['CAD' => 'interac', 'NGN' => 'bank_transfer'] as $currency => $rail) {
            OrganizationPayoutDetail::create([
                'organization_id' => $this->org->id,
                'rail' => $rail,
                'currency' => $currency,
                'interac_email' => $rail === 'interac' ? 'money@lagosnights.test' : null,
                'bank_name' => $rail === 'bank_transfer' ? 'Eko Bank' : null,
                'account_name' => $rail === 'bank_transfer' ? 'Lagos Nights Ltd' : null,
                'account_last_four' => $rail === 'bank_transfer' ? '4321' : null,
                'verified_at' => now(),
                'verification_method' => 'interac_test_transfer',
            ]);
        }
    }

    // --- helpers ----------------------------------------------------------------

    private function member(Role $role): User
    {
        $user = User::factory()->create();
        $this->org->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => $role->value, 'accepted_at' => now()]);

        return $user->fresh()->load('organizations');
    }

    private function staff(PlatformRole $role): User
    {
        return User::factory()->create(['platform_role' => $role, 'email_verified_at' => now()]);
    }

    private function actAs(User $user): void
    {
        Sanctum::actingAs($user->fresh()->load('organizations'), [TokenAbility::Organizer->value]);
    }

    private function inPanel(User $staff): void
    {
        $this->actingAs($staff);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** A night's takings, net: what a sale adds to the balance. */
    private function sold(int $amount, string $currency = 'CAD'): void
    {
        LedgerEntry::create(['organization_id' => $this->org->id, 'type' => 'sale', 'amount' => $amount, 'currency' => $currency, 'occurred_at' => now()]);
    }

    private function refunded(int $amount, string $currency = 'CAD'): void
    {
        LedgerEntry::create(['organization_id' => $this->org->id, 'type' => 'refund', 'amount' => -$amount, 'currency' => $currency, 'occurred_at' => now()]);
    }

    private function balance(string $currency = 'CAD'): int
    {
        return (int) LedgerEntry::where('organization_id', $this->org->id)->where('currency', $currency)->sum('amount');
    }

    private function ask(int $amount): TestResponse
    {
        $this->actAs($this->owner);

        return $this->postJson('/api/organizer/payouts/requests', ['amount' => $amount, 'note' => 'For the weekend']);
    }

    /** Asked for through the console, as an organizer would. */
    private function pending(int $asked, int $owed): PayoutRequest
    {
        $this->sold($owed);
        $this->ask($asked)->assertCreated();

        return PayoutRequest::query()->where('status', 'pending')->sole();
    }

    /** An advance: $500.00 owed, $800.00 paid, so $300.00 advanced. */
    private function advance(PlatformRole $role = PlatformRole::Finance): PayoutRequest
    {
        $request = $this->pending(50_000, 50_000);

        return app(PayoutRequests::class)->pay(
            $request,
            $this->staff($role),
            new Money(80_000, 'CAD'),
            'interac',
            null,
            'Advance for the festival, agreed with the owner on the phone.',
        );
    }

    /** @return array<string, mixed> */
    private function statement(): array
    {
        $this->actAs($this->owner);

        return $this->getJson('/api/organizer/payouts')->assertOk()->json();
    }

    /**
     * Something lands the moment after the balance is next read — as a sale
     * or refund committing then would, since neither waits for the
     * organization's lock.
     */
    private function afterTheBalanceIsRead(callable $landing): void
    {
        $landed = false;

        DB::listen(function (QueryExecuted $query) use (&$landed, $landing) {
            if (! $landed && str_contains($query->sql, 'SUM(amount) AS total')) {
                $landed = true;
                $landing();
            }
        });
    }

    /**
     * Paid beyond the balance with nothing but its settlement to say so: an
     * overdraft settlement, the reason as its note, and its ledger entry. As
     * the settlement form wrote one before advances were given only on
     * requests, and as paying a request did before requests kept the figures.
     */
    private function overdrawnTheOldWay(User $by, int $amount, string $reason): Settlement
    {
        $settlement = Settlement::create([
            'organization_id' => $this->org->id,
            'amount' => $amount,
            'currency' => 'CAD',
            'rail' => 'interac',
            'type' => 'overdraft',
            'note' => $reason,
            'status' => 'success',
            'settled_by' => $by->id,
            'settled_at' => now(),
        ]);

        LedgerEntry::create([
            'organization_id' => $this->org->id,
            'type' => 'settlement',
            'amount' => -$amount,
            'currency' => 'CAD',
            'reason' => 'Settlement '.$settlement->id,
            'occurred_at' => now(),
        ]);

        return $settlement;
    }

    // --- paying ---------------------------------------------------------------------

    public function test_paying_within_the_balance_is_unchanged_and_says_who_approved_it(): void
    {
        $request = $this->pending(30_000, 50_000);
        $finance = $this->staff(PlatformRole::Finance);

        app(PayoutRequests::class)->pay($request, $finance, new Money(30_000, 'CAD'), 'interac');

        $request->refresh();
        $this->assertSame('paid', $request->status);
        $this->assertSame('partial', $request->settlement->type);
        $this->assertNull($request->overdraft_amount);
        $this->assertNull($request->overdraft_reason);
        $this->assertSame($finance->id, $request->approved_by);
        $this->assertTrue($request->approved_at->equalTo(now()));

        $this->assertSame(20_000, $this->balance());
        $this->assertSame(0, AuditLog::where('action', 'payout_request.overdraft_approved')->count());
        $this->assertNull($this->statement()['overdraft']);
    }

    public function test_finance_pays_over_the_balance_with_a_reason_and_every_part_of_it_is_on_the_record(): void
    {
        $finance = $this->staff(PlatformRole::Finance);
        $request = $this->pending(50_000, 50_000);

        app(PayoutRequests::class)->pay($request, $finance, new Money(80_000, 'CAD'), 'interac', 'Sent before Friday.', 'Advance for the festival, agreed with the owner on the phone.');

        $request->refresh();

        // On the request: how much, why, who and when.
        $this->assertSame(80_000, $request->paid_amount);
        $this->assertSame(30_000, $request->overdraft_amount);
        $this->assertSame('Advance for the festival, agreed with the owner on the phone.', $request->overdraft_reason);
        $this->assertSame('Sent before Friday.', $request->decision_note);
        $this->assertSame($finance->id, $request->approved_by);
        $this->assertTrue($request->approved_at->equalTo(now()));

        // The ledger records the payout as it does any other: one settlement
        // entry of the whole amount. No money appears or disappears — the
        // balance is below zero by exactly the advance.
        $settlementEntries = LedgerEntry::where('organization_id', $this->org->id)->where('type', 'settlement')->get();
        $this->assertCount(1, $settlementEntries);
        $this->assertSame(-80_000, (int) $settlementEntries->sole()->amount);
        $this->assertSame(-30_000, $this->balance());
        $this->assertSame(50_000 - 80_000, $this->balance());

        $settlement = $request->settlement;
        $this->assertSame('overdraft', $settlement->type);
        $this->assertSame(80_000, (int) $settlement->amount);
        $this->assertSame("Sent before Friday.\nAdvance of \$300.00: Advance for the festival, agreed with the owner on the phone.", $settlement->note);

        // The decision, on its own line of the trail.
        $entry = AuditLog::where('action', 'payout_request.overdraft_approved')->sole();
        $this->assertSame($finance->id, $entry->actor_id);
        $this->assertSame($request->id, $entry->subject_id);
        $this->assertSame($this->org->id, $entry->organization_id);
        $this->assertSame(50_000, $entry->metadata['balance']);
        $this->assertSame(80_000, $entry->metadata['paid']);
        $this->assertSame(30_000, $entry->metadata['overdraft_amount']);
        $this->assertSame('CAD', $entry->metadata['currency']);
        $this->assertSame('Advance for the festival, agreed with the owner on the phone.', $entry->metadata['reason']);
        $this->assertTrue($entry->created_at->equalTo(now()));
        $this->assertTrue(AuditLog::where('action', 'payout_request.paid')->sole()->metadata['overdraft']);

        // The organizer is told it was an advance, and how it comes back.
        Mail::assertQueued(PayoutRequestDecided::class, function (PayoutRequestDecided $mail) {
            $html = $mail->render();

            return str_contains($html, '$300.00 of this is an advance from myFiesta')
                && str_contains($html, 'Your next sales in CAD pay it back automatically');
        });
    }

    public function test_an_administrator_can_too_and_the_overdraft_is_measured_against_the_balance_at_the_moment_of_paying(): void
    {
        $request = $this->pending(30_000, 50_000);

        // A refund since they asked: $100.00 is owed now, not $500.00.
        $this->refunded(40_000);

        app(PayoutRequests::class)->pay($request, $this->staff(PlatformRole::Admin), new Money(30_000, 'CAD'), 'interac', null, 'Paying what they asked for; the refund was ours to absorb.');

        $this->assertSame(20_000, $request->fresh()->overdraft_amount);
        $this->assertSame(-20_000, $this->balance());
    }

    public function test_support_can_pay_nothing_let_alone_more_than_is_owed(): void
    {
        $request = $this->pending(50_000, 50_000);

        try {
            app(PayoutRequests::class)->pay($request, $this->staff(PlatformRole::Support), new Money(80_000, 'CAD'), 'interac', null, 'A reason.');
            $this->fail('Support paid a payout request.');
        } catch (PayoutRequestRefused $refused) {
            $this->assertSame('Only platform administrators and finance can pay payout requests.', $refused->getMessage());
        }

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(0, Settlement::count());
        $this->assertSame(50_000, $this->balance());
    }

    public function test_an_advance_to_details_nobody_verified_needs_the_note_that_says_why(): void
    {
        OrganizationPayoutDetail::where('organization_id', $this->org->id)->where('currency', 'CAD')
            ->update(['verified_at' => null, 'verification_method' => null]);

        $request = $this->pending(50_000, 50_000);
        $finance = $this->staff(PlatformRole::Finance);

        // Why money is advanced is not why it went to details nobody checked,
        // and unearned money sent on is the payout that most needs the second.
        try {
            app(PayoutRequests::class)->pay($request, $finance, new Money(80_000, 'CAD'), 'interac', null, 'Festival advance agreed with the owner.');
            $this->fail('An advance went to unverified details with nothing said about them.');
        } catch (SettlementRefused $refused) {
            $this->assertStringStartsWith('The Interac details for this organization are not verified.', $refused->getMessage());
        }

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(0, Settlement::count());
        $this->assertSame(50_000, $this->balance());

        app(PayoutRequests::class)->pay($request, $finance, new Money(80_000, 'CAD'), 'interac', 'Sent to the email the owner confirmed on the phone.', 'Festival advance agreed with the owner.');

        $this->assertSame(
            "Sent to the email the owner confirmed on the phone.\nAdvance of \$300.00: Festival advance agreed with the owner.",
            $request->fresh()->settlement->note,
        );
        $this->assertFalse(AuditLog::where('action', 'settlement.recorded')->sole()->metadata['destination_verified']);
    }

    public function test_a_sale_landing_while_paying_leaves_the_request_and_the_ledger_telling_the_same_advance(): void
    {
        $request = $this->pending(50_000, 50_000);

        $this->afterTheBalanceIsRead(fn () => $this->sold(30_000));

        app(PayoutRequests::class)->pay($request, $this->staff(PlatformRole::Finance), new Money(80_000, 'CAD'), 'interac', null, 'Advance for the festival, agreed with the owner.');

        $request->refresh();

        // Measured once, against the $500.00 owed when it was paid: the
        // request, the trail and the payout's type all say $300.00 advanced.
        $this->assertSame(30_000, $request->overdraft_amount);
        $this->assertSame('overdraft', $request->settlement->type);
        $this->assertSame(50_000, AuditLog::where('action', 'payout_request.overdraft_approved')->sole()->metadata['balance']);

        // And the sale that landed paid it straight back.
        $this->assertSame(0, $this->balance());
        $this->assertSame('myFiesta advanced $300.00 on 12 Oct 2026; $300.00 recovered from sales since; nothing outstanding.', $this->statement()['overdraft']['summary']);
    }

    public function test_a_refund_landing_while_paying_does_not_turn_a_covered_payment_into_a_refused_advance(): void
    {
        $request = $this->pending(50_000, 50_000);

        $this->afterTheBalanceIsRead(fn () => $this->refunded(10_000));

        app(PayoutRequests::class)->pay($request, $this->staff(PlatformRole::Finance), new Money(50_000, 'CAD'), 'interac');

        $request->refresh();
        $this->assertSame('paid', $request->status);
        $this->assertSame('full', $request->settlement->type);
        $this->assertNull($request->overdraft_amount);

        // The refund came after a payout the balance covered, and is told so.
        $this->assertSame(-10_000, $this->balance());
        $this->assertSame('Refunds and chargebacks since the last payout on 12 Oct 2026 came to $100.00 more than sales; $100.00 outstanding.', $this->statement()['overdraft']['summary']);
    }

    // --- getting it back ---------------------------------------------------------

    public function test_the_statement_tells_the_advance_and_its_recovery_at_every_step(): void
    {
        $this->advance();

        $statement = $this->statement();
        $this->assertSame(-30_000, $statement['balance']['amount']);
        $this->assertSame(30_000, $statement['requests'][0]['advance']['amount']);
        $this->assertSame(30_000, $statement['overdraft']['advanced']['amount']);
        $this->assertSame(30_000, $statement['overdraft']['outstanding']['amount']);
        $this->assertSame('myFiesta advanced $300.00 on 12 Oct 2026; nothing recovered from sales yet; $300.00 outstanding.', $statement['overdraft']['summary']);
        $this->assertStringStartsWith('Your next sales in CAD pay this back automatically', $statement['overdraft']['recovery']);

        // Sales three days later pay part of it back.
        $this->travel(3)->days();
        $this->sold(12_000);

        $statement = $this->statement();
        $this->assertSame(-18_000, $statement['balance']['amount']);
        $this->assertSame(12_000, $statement['overdraft']['recovered']['amount']);
        $this->assertSame('myFiesta advanced $300.00 on 12 Oct 2026; $120.00 recovered from sales since; $180.00 outstanding.', $statement['overdraft']['summary']);

        // A refund takes some of that back again.
        $this->refunded(2_000);

        $this->assertSame('myFiesta advanced $300.00 on 12 Oct 2026; $100.00 recovered from sales since; $200.00 outstanding.', $this->statement()['overdraft']['summary']);

        // And a busy weekend covers the rest, with money to spare.
        $this->sold(25_000);

        $statement = $this->statement();
        $this->assertSame(5_000, $statement['balance']['amount']);
        $this->assertSame(0, $statement['overdraft']['outstanding']['amount']);
        $this->assertSame(30_000, $statement['overdraft']['recovered']['amount']);
        $this->assertSame('myFiesta advanced $300.00 on 12 Oct 2026; $300.00 recovered from sales since; nothing outstanding.', $statement['overdraft']['summary']);
        $this->assertNull($statement['overdraft']['recovery']);

        // Every cent accounted for: sales less what was paid out.
        $this->assertSame(50_000 + 12_000 - 2_000 + 25_000 - 80_000, $this->balance());
    }

    public function test_refunds_since_the_advance_are_shown_as_added_rather_than_hidden(): void
    {
        $this->advance();

        $this->travel(1)->day();
        $this->sold(1_000);
        $this->refunded(6_000);

        $overdraft = $this->statement()['overdraft'];

        $this->assertSame(35_000, $overdraft['outstanding']['amount']);
        $this->assertSame(5_000, $overdraft['added']['amount']);
        $this->assertSame('myFiesta advanced $300.00 on 12 Oct 2026; nothing recovered from sales yet; refunds since added $50.00; $350.00 outstanding.', $overdraft['summary']);
    }

    public function test_nothing_more_can_be_asked_for_while_the_balance_is_below_zero(): void
    {
        $this->advance();

        $this->travel(1)->day();
        $this->sold(10_000);

        $this->ask(1_000)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Your balance in CAD is below zero. myFiesta advanced $300.00 on 12 Oct 2026; $100.00 recovered from sales since; $200.00 outstanding. '
                .'Your next sales pay it back, and you can ask to be paid again once your balance is above zero.');

        // At exactly zero there is still nothing to ask for.
        $this->sold(20_000);
        $this->ask(1_000)->assertStatus(422)->assertJsonPath('message', 'There is nothing owed to you in CAD right now.');

        // Above zero, asking works again — for what is owed beyond the advance.
        $this->sold(7_500);
        $this->ask(7_501)->assertStatus(422);
        $this->ask(7_500)->assertCreated();
    }

    public function test_a_shortfall_with_no_advance_behind_it_is_told_as_refunds(): void
    {
        $request = $this->pending(50_000, 50_000);
        app(PayoutRequests::class)->pay($request, $this->staff(PlatformRole::Finance), new Money(50_000, 'CAD'), 'interac');

        $this->travel(2)->days();
        $this->refunded(3_000);

        $overdraft = $this->statement()['overdraft'];

        $this->assertNull($overdraft['advanced']);
        $this->assertSame(3_000, $overdraft['outstanding']['amount']);
        $this->assertSame('Refunds and chargebacks since the last payout on 12 Oct 2026 came to $30.00 more than sales; $30.00 outstanding.', $overdraft['summary']);
    }

    public function test_a_balance_carried_over_below_zero_is_not_given_a_cause_it_may_not_have(): void
    {
        // As the old platform's payouts come across: ledger entries with no
        // settlement or request beside them.
        $this->sold(10_000);
        LedgerEntry::create(['organization_id' => $this->org->id, 'type' => 'settlement', 'amount' => -14_000, 'currency' => 'CAD', 'reason' => 'Imported settlement', 'occurred_at' => now()->subYear()]);

        $position = app(Overdrafts::class)->position($this->org, 'CAD');

        $this->assertFalse($position->isAdvance());
        $this->assertSame('The balance went $40.00 below zero, with no advance on record; $40.00 outstanding.', $position->summary());
    }

    public function test_an_advance_paid_on_a_request_before_requests_kept_its_figures_is_told_as_an_advance(): void
    {
        // Paid over the balance as paying did before this: the payout said
        // it, the request only who decided and when.
        $request = $this->pending(50_000, 50_000);
        $admin = $this->staff(PlatformRole::Admin);
        $settlement = $this->overdrawnTheOldWay($admin, 80_000, 'Advance for the festival.');
        $request->update([
            'status' => 'paid',
            'paid_amount' => 80_000,
            'settlement_id' => $settlement->id,
            'decision_note' => 'Advance for the festival.',
            'decided_by' => $admin->id,
            'decided_at' => now(),
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);

        // Told from its payout, not as refunds after it.
        $position = app(Overdrafts::class)->position($this->org, 'CAD');
        $this->assertTrue($position->isAdvance());
        $this->assertSame(30_000, $position->advanced->amount);
        $this->assertSame('myFiesta advanced $300.00 on 12 Oct 2026; nothing recovered from sales yet; $300.00 outstanding.', $position->summary('America/Toronto'));
        $this->assertSame('Approved by '.$admin->name, $position->decidedBy());
        $this->assertSame('Advance for the festival.', $position->reason());

        // And the migration writes the figures onto the request itself, so
        // the request, the payout and the statement all say the same.
        $migration = require database_path('migrations/2026_09_27_061500_an_advance_is_on_the_record.php');
        $migration->down();
        $migration->up();

        $request->refresh();
        $this->assertSame(30_000, $request->overdraft_amount);
        $this->assertSame('Advance for the festival.', $request->overdraft_reason);
        $this->assertSame($admin->id, $request->approved_by);
        $this->assertTrue($request->approved_at->equalTo(now()));

        $statement = $this->statement();
        $this->assertSame(30_000, $statement['requests'][0]['advance']['amount']);
        $this->assertSame(30_000, $statement['overdraft']['advanced']['amount']);
        $this->assertSame('myFiesta advanced $300.00 on 12 Oct 2026; nothing recovered from sales yet; $300.00 outstanding.', $statement['overdraft']['summary']);
        $this->assertSame($request->id, app(Overdrafts::class)->position($this->org, 'CAD')->advance?->id);
    }

    public function test_an_advance_paid_from_the_settlement_form_is_told_as_one_without_inventing_an_approval(): void
    {
        // Before advances were given only on requests, the organization's
        // settlement form could overdraw with a note. No request stands
        // behind it, so the payout is all there is.
        $this->sold(50_000);
        $admin = $this->staff(PlatformRole::Admin);
        $this->overdrawnTheOldWay($admin, 80_000, 'Advance before the festival weekend.');

        $this->travel(2)->days();
        $this->sold(5_000);

        $position = app(Overdrafts::class)->position($this->org, 'CAD');
        $this->assertTrue($position->isAdvance());
        $this->assertNull($position->advance);
        $this->assertSame('myFiesta advanced $300.00 on 12 Oct 2026; $50.00 recovered from sales since; $250.00 outstanding.', $position->summary('America/Toronto'));
        $this->assertSame('Paid by '.$admin->name.'; no approval on record', $position->decidedBy());
        $this->assertSame('Advance before the festival weekend.', $position->reason());

        // The staff screens say the same, and neither calls it refunds.
        $this->inPanel($this->staff(PlatformRole::Finance));

        Livewire::test(OverdraftsPage::class)
            ->assertSuccessful()
            ->assertSee('Advance of $300.00')
            ->assertSee('Paid by '.$admin->name.'; no approval on record')
            ->assertDontSee('Refunds after a payout');

        Livewire::test(ViewOrganization::class, ['record' => $this->org->getKey()])
            ->assertSuccessful()
            ->assertSee('Paid by '.$admin->name.'; no approval on record: Advance before the festival weekend.')
            ->assertDontSee('No advance: refunds after a payout');
    }

    public function test_an_advance_covered_and_followed_by_an_ordinary_payout_is_no_longer_the_story(): void
    {
        $this->advance();

        $this->travel(5)->days();
        $this->sold(40_000);

        // $100.00 owed beyond the advance, asked for and paid from the balance.
        $this->ask(10_000)->assertCreated();
        $regular = PayoutRequest::query()->where('status', 'pending')->sole();
        app(PayoutRequests::class)->pay($regular, $this->staff(PlatformRole::Finance), new Money(10_000, 'CAD'), 'interac');

        $this->assertNull($this->statement()['overdraft']);

        $this->travel(1)->day();
        $this->refunded(1_500);

        $overdraft = $this->statement()['overdraft'];
        $this->assertNull($overdraft['advanced']);
        $this->assertSame('Refunds and chargebacks since the last payout on 17 Oct 2026 came to $15.00 more than sales; $15.00 outstanding.', $overdraft['summary']);
    }

    // --- repayments ------------------------------------------------------------------

    public function test_finance_records_a_repayment_up_to_what_is_outstanding(): void
    {
        $this->advance();
        $this->travel(1)->day();
        $this->sold(12_000);

        $overdrafts = app(Overdrafts::class);
        $finance = $this->staff(PlatformRole::Finance);
        $cad = fn (int $amount) => new Money($amount, 'CAD');

        foreach ([
            [$this->staff(PlatformRole::Support), $cad(5_000), 'TRX-1', 'Paid back', 'Only platform administrators and finance can record a repayment.'],
            [$finance, $cad(18_001), 'TRX-1', 'Paid back', 'That is more than is outstanding ($180.00). Record up to that amount.'],
            [$finance, $cad(5_000), '  ', 'Paid back', 'Give the reference the money arrived with, so it can be matched to the bank statement.'],
            [$finance, $cad(5_000), 'TRX-1', '', 'Say why this is being recorded: who paid it, and how.'],
            [$finance, $cad(0), 'TRX-1', 'Paid back', 'A repayment has to be more than zero.'],
        ] as [$by, $amount, $reference, $reason, $refusal]) {
            try {
                $overdrafts->recordRepayment($this->org, $by, $amount, $reference, $reason);
                $this->fail('Recorded: '.$refusal);
            } catch (RepaymentRefused $refused) {
                $this->assertSame($refusal, $refused->getMessage());
            }
        }

        $this->assertSame(0, Repayment::count());

        $repayment = $overdrafts->recordRepayment($this->org, $finance, $cad(5_000), ' INTERAC-CA-7731 ', 'The owner sent it after the festival, by e-Transfer.');

        // A ledger credit of its own kind, beside its record.
        $entry = $repayment->ledgerEntry;
        $this->assertSame('repayment', $entry->type);
        $this->assertSame(5_000, (int) $entry->amount);
        $this->assertSame('CAD', $entry->currency);
        $this->assertSame('INTERAC-CA-7731', $repayment->reference);
        $this->assertSame($finance->id, $repayment->recorded_by);
        $this->assertSame(-13_000, $this->balance());

        $audit = AuditLog::where('action', 'overdraft.repaid')->sole();
        $this->assertSame($finance->id, $audit->actor_id);
        $this->assertSame(5_000, $audit->metadata['amount']);
        $this->assertSame(18_000, $audit->metadata['outstanding_amount']);
        $this->assertSame(13_000, $audit->metadata['remaining_amount']);
        $this->assertSame('INTERAC-CA-7731', $audit->metadata['reference']);

        $overdraft = $this->statement()['overdraft'];
        $this->assertSame(5_000, $overdraft['repaid']['amount']);
        $this->assertSame('myFiesta advanced $300.00 on 12 Oct 2026; $120.00 recovered from sales since; $50.00 repaid; $130.00 outstanding.', $overdraft['summary']);

        // What is left can be repaid in full, and then nothing more can.
        $overdrafts->recordRepayment($this->org, $this->staff(PlatformRole::Admin), $cad(13_000), 'BANK-9921', 'Balance settled by transfer.');
        $this->assertSame(0, $this->balance());
        $this->assertSame('myFiesta advanced $300.00 on 12 Oct 2026; $120.00 recovered from sales since; $180.00 repaid; nothing outstanding.', $this->statement()['overdraft']['summary']);

        $this->expectException(RepaymentRefused::class);
        $this->expectExceptionMessage('Nothing is outstanding in CAD, so there is nothing to repay.');
        $overdrafts->recordRepayment($this->org, $finance, $cad(100), 'BANK-9922', 'Again.');
    }

    public function test_one_transfer_is_recorded_once(): void
    {
        $this->advance();

        $overdrafts = app(Overdrafts::class);
        $finance = $this->staff(PlatformRole::Finance);
        $first = $overdrafts->recordRepayment($this->org, $finance, new Money(10_000, 'CAD'), 'INTERAC-555', 'Sent by the owner.');

        // A second person records the same transfer, typed a little
        // differently. Credited twice, $100.00 that never arrived would come
        // off the debt, and sales that should repay it would be paid out.
        try {
            $overdrafts->recordRepayment($this->org, $this->staff(PlatformRole::Admin), new Money(10_000, 'CAD'), ' interac-555 ', 'The owner sent it by e-Transfer.');
            $this->fail('One transfer was recorded twice.');
        } catch (RepaymentRefused $refused) {
            $this->assertSame(
                'INTERAC-555 is already recorded: a repayment of $100.00 on 12 Oct 2026 (UTC), by '.$finance->name.'. '
                .'A transfer is recorded once. If this is a second transfer that arrived with the same reference, record it as INTERAC-555/2.',
                $refused->getMessage(),
            );
        }

        $this->assertSame(1, Repayment::count());
        $this->assertSame(-20_000, $this->balance());

        // Past the service, the database holds to it as well.
        try {
            DB::transaction(fn () => DB::table('repayments')->insert([
                'id' => (string) Str::uuid(),
                'organization_id' => $this->org->id,
                'currency' => 'CAD',
                'amount' => 10_000,
                'reference' => 'Interac-555',
                'reason' => 'Again.',
                'ledger_entry_id' => $first->ledger_entry_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $this->fail('The database took the same reference twice.');
        } catch (UniqueConstraintViolationException) {
        }

        // A second transfer under its own reference is its own repayment.
        $overdrafts->recordRepayment($this->org, $finance, new Money(10_000, 'CAD'), 'INTERAC-555/2', 'The second half, sent a week later.');
        $this->assertSame(-10_000, $this->balance());
        $this->assertSame(2, Repayment::count());
    }

    public function test_a_repayment_is_never_edited_or_deleted(): void
    {
        $this->advance();

        $repayment = app(Overdrafts::class)->recordRepayment($this->org, $this->staff(PlatformRole::Finance), new Money(1_000, 'CAD'), 'TRX-1', 'Part paid back.');

        foreach ([fn () => $repayment->update(['amount' => 99_999]), fn () => $repayment->delete()] as $change) {
            try {
                $change();
                $this->fail('A repayment was changed.');
            } catch (RuntimeException) {
            }
        }

        // And past the model, the database says no as well.
        try {
            DB::transaction(fn () => DB::table('repayments')->where('id', $repayment->id)->update(['amount' => 99_999]));
            $this->fail('The database let a repayment change.');
        } catch (QueryException $refused) {
            $this->assertStringContainsString('append-only', $refused->getMessage());
        }

        $this->assertSame(1_000, (int) DB::table('repayments')->where('id', $repayment->id)->value('amount'));
    }

    // --- currencies ------------------------------------------------------------------

    public function test_an_advance_in_one_currency_leaves_the_other_alone(): void
    {
        // Owed $400.00 in Toronto; ₦50,000 advanced in Lagos.
        $this->sold(40_000);
        $this->sold(2_000_000, 'NGN');
        $ngn = app(PayoutRequests::class)->request($this->org, $this->owner, new Money(2_000_000, 'NGN'));
        app(PayoutRequests::class)->pay($ngn, $this->staff(PlatformRole::Finance), new Money(7_000_000, 'NGN'), 'bank_transfer', null, 'Advance for the Lagos run, agreed in writing.');

        $this->assertSame(-5_000_000, $this->balance('NGN'));
        $this->assertSame(40_000, $this->balance('CAD'));

        $overdrafts = app(Overdrafts::class);
        $this->assertSame(['NGN'], array_keys($overdrafts->positionsFor($this->org)));
        $this->assertNull($overdrafts->position($this->org, 'CAD'));
        $this->assertSame('myFiesta advanced ₦50,000 on 12 Oct 2026; nothing recovered from sales yet; ₦50,000 outstanding.', $overdrafts->position($this->org, 'NGN')->summary('Africa/Lagos'));

        // Dollars are still asked for and paid as usual; naira are not.
        $this->ask(40_000)->assertCreated()->assertJsonPath('data.amount.currency', 'CAD');
        $this->assertNull($this->statement()['overdraft']);

        try {
            app(PayoutRequests::class)->request($this->org, $this->owner, new Money(100, 'NGN'));
            $this->fail('Naira were asked for while the naira balance was below zero.');
        } catch (PayoutRequestRefused $refused) {
            $this->assertStringStartsWith('Your balance in NGN is below zero.', $refused->getMessage());
        }

        // A dollar repayment is refused: nothing is owed in dollars, and it is
        // never set against the naira.
        try {
            $overdrafts->recordRepayment($this->org, $this->staff(PlatformRole::Finance), new Money(100, 'CAD'), 'TRX', 'Wrong currency.');
            $this->fail('A dollar repayment was taken against a naira advance.');
        } catch (RepaymentRefused $refused) {
            $this->assertSame('Nothing is outstanding in CAD, so there is nothing to repay.', $refused->getMessage());
        }

        $outstanding = $overdrafts->outstanding();
        $this->assertCount(1, $outstanding);
        $this->assertSame('NGN', $outstanding[0]->currency);
        $this->assertSame(5_000_000, $outstanding[0]->outstanding->amount);
    }

    // --- nobody walks away from it ------------------------------------------------------

    public function test_an_organization_that_owes_cannot_be_closed_until_it_is_repaid(): void
    {
        $this->advance();

        try {
            $this->org->delete();
            $this->fail('An organization that owes money was closed.');
        } catch (OverdraftOutstanding $refused) {
            $this->assertSame(
                'Lagos Nights owes myFiesta money, so it cannot be closed. CAD: myFiesta advanced $300.00 on 12 Oct 2026; nothing recovered from sales yet; $300.00 outstanding. '
                .'Close it once that has been recovered from its sales or repaid.',
                $refused->getMessage(),
            );
        }

        $this->assertFalse($this->org->fresh()->trashed());

        app(Overdrafts::class)->recordRepayment($this->org, $this->staff(PlatformRole::Admin), new Money(30_000, 'CAD'), 'BANK-1', 'Repaid in full before closing.');

        $this->org->delete();
        $this->assertTrue($this->org->fresh()->trashed());
    }

    public function test_its_only_owner_is_not_erased_while_it_owes(): void
    {
        $this->advance();

        $refusal = app(Eraser::class)->refusal(new Subject($this->owner->email, $this->owner));

        $this->assertSame(
            'You are the only owner of Lagos Nights. Erasing your account would leave its events, its money and its ticket holders with nobody who can reach them, '
            .'and Lagos Nights owes myFiesta money, so it cannot be closed until that is recovered from its sales or repaid. Make somebody else an owner, and then ask again.',
            $refusal,
        );

        // Asked from the app, it is refused the same way.
        $this->actAs($this->owner);
        $this->getJson('/api/auth/erasure')->assertOk()->assertJsonPath('refused', $refusal);
    }

    // --- figures across the platform --------------------------------------------------

    public function test_the_platform_figures_never_net_an_overdraft_against_what_others_are_owed(): void
    {
        $this->advance();

        $toronto = Organization::create(['name' => 'Toronto Sound', 'slug' => 'toronto-sound']);
        LedgerEntry::create(['organization_id' => $toronto->id, 'type' => 'sale', 'amount' => 45_000, 'currency' => 'CAD', 'occurred_at' => now()]);

        $period = Period::resolve('7d', timezone: 'America/Toronto', now: CarbonImmutable::now());

        // What we hold for organizers is what Toronto is owed; Lagos Nights'
        // advance is beside it, not subtracted from it.
        $this->assertSame(
            ['owed' => 45_000, 'organizations' => 1, 'overdrawn' => 30_000],
            app(PlatformMetrics::class)->overview('CAD', $period)['owed'],
        );

        $metrics = app(OrganizerMetrics::class);
        $this->assertSame(-30_000, $metrics->for($this->org->id, 'CAD', $period)['payouts']['owed']);
        $this->assertSame(80_000, $metrics->for($this->org->id, 'CAD', $period)['payouts']['paid']);
        $this->assertSame([$this->org->id], $metrics->table('CAD', $period)->where('owed', '<', 0)->pluck('id')->all());
        $this->assertSame([$toronto->id], $metrics->table('CAD', $period)->where('owed', '>', 0)->pluck('id')->all());
    }

    public function test_the_statement_carries_what_the_contract_promises(): void
    {
        $this->advance();

        $schemas = Yaml::parseFile(base_path('../../packages/contract/openapi.yaml'))['components']['schemas'];
        $statement = $this->statement();

        foreach ([
            'PayoutStatement' => $statement,
            'PayoutOverdraft' => $statement['overdraft'],
            'PayoutRequestRow' => $statement['requests'][0],
        ] as $schema => $body) {
            foreach (array_keys($schemas[$schema]['properties']) as $field) {
                $this->assertArrayHasKey($field, $body, "{$schema} declares '{$field}' and the statement does not return it.");
            }
        }
    }

    // --- the admin panel -----------------------------------------------------------------

    public function test_paying_over_the_balance_from_the_panel_asks_about_the_advance_first(): void
    {
        $request = $this->pending(50_000, 50_000);
        $finance = $this->staff(PlatformRole::Finance);
        $this->inPanel($finance);

        $page = Livewire::test(ListPayoutRequests::class)
            ->mountTableAction('pay', $request)
            ->setTableActionData(['amount' => '800.00', 'rail' => 'interac', 'note' => ''])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        // Nothing recorded yet: the advance is its own question.
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(0, Settlement::count());

        $page->assertMountedActionModalSee('Advance $300.00 to Lagos Nights?')
            ->assertMountedActionModalSee('Owed to them now')
            ->assertMountedActionModalSee('$500.00')
            ->assertMountedActionModalSee('Asked for')
            ->assertMountedActionModalSee('$800.00')
            ->assertMountedActionModalSee('Overdraft — advanced by myFiesta')
            ->assertMountedActionModalSee('balance goes to -$300.00')
            ->assertMountedActionModalSee('Advance $300.00 and record payment');

        // Without a reason it goes nowhere.
        $page->callMountedAction()->assertHasFormErrors(['reason' => 'required']);
        $this->assertSame('pending', $request->fresh()->status);

        $page->fillForm(['reason' => 'Advance for the festival, agreed on the phone.'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $request->refresh();
        $this->assertSame('paid', $request->status);
        $this->assertSame(30_000, $request->overdraft_amount);
        $this->assertSame('Advance for the festival, agreed on the phone.', $request->overdraft_reason);
        $this->assertSame($finance->id, $request->approved_by);
        $this->assertSame(-30_000, $this->balance());
    }

    public function test_paying_within_the_balance_from_the_panel_asks_nothing_more(): void
    {
        $request = $this->pending(30_000, 50_000);
        $this->inPanel($this->staff(PlatformRole::Finance));

        Livewire::test(ListPayoutRequests::class)
            ->callTableAction('pay', $request, ['amount' => '300.00', 'rail' => 'interac'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('paid', $request->fresh()->status);
        $this->assertNull($request->fresh()->overdraft_amount);
    }

    public function test_the_overdrafts_page_lists_who_owes_and_takes_a_repayment(): void
    {
        $this->advance();
        $this->travel(4)->days();
        $this->sold(10_000);

        $this->inPanel($this->staff(PlatformRole::Support));
        $this->assertFalse(OverdraftsPage::canAccess());

        $this->inPanel($this->staff(PlatformRole::Finance));
        $this->assertTrue(OverdraftsPage::canAccess());
        $this->assertSame('1', OverdraftsPage::getNavigationBadge());

        $key = $this->org->id.':CAD';

        Livewire::test(OverdraftsPage::class)
            ->assertSuccessful()
            ->assertSee('Lagos Nights')
            ->assertSee('$200.00')
            ->assertSee('Advance of $300.00')
            ->assertSee('4 days')
            ->assertSee('$100.00')
            ->mountTableAction('recordRepayment', $key)
            ->assertMountedActionModalSee('Record a repayment from Lagos Nights')
            ->assertMountedActionModalSee('CAD — $200.00 outstanding')
            ->setTableActionData(['amount' => '75.00', 'reference' => 'INTERAC-555', 'reason' => 'Sent by the owner.', 'received' => false])
            // The form says what it will write before it writes it.
            ->assertMountedActionModalSee('A repayment of $75.00 from Lagos Nights. What they owe in CAD goes from $200.00 to $125.00.')
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['received']);

        $this->assertSame(0, Repayment::count());

        Livewire::test(OverdraftsPage::class)
            ->callTableAction('recordRepayment', $key, ['amount' => '75.00', 'reference' => 'INTERAC-555', 'reason' => 'Sent by the owner.', 'received' => true])
            ->assertHasNoTableActionErrors();

        $this->assertSame(7_500, (int) Repayment::sole()->amount);
        $this->assertSame(-12_500, $this->balance());
    }

    public function test_the_organization_page_shows_what_is_owed_and_who_decided_it(): void
    {
        $this->advance();

        $this->inPanel($this->staff(PlatformRole::Admin));

        Livewire::test(ViewOrganization::class, ['record' => $this->org->getKey()])
            ->assertSuccessful()
            ->assertSee('Owes myFiesta $300.00')
            ->assertSee('Owed to myFiesta')
            ->assertSee('myFiesta advanced $300.00 on 12 Oct 2026; nothing recovered from sales yet; $300.00 outstanding.')
            ->assertSee('Advance for the festival, agreed with the owner on the phone.')
            ->assertSee('Record a repayment');

        // The list of organizations says who owes, rather than a minus sign.
        Livewire::test(ListOrganizations::class)
            ->assertSuccessful()
            ->assertSee('Owes myFiesta $300.00')
            ->assertDontSee('-$300.00');

        // And the payout itself says how much of it was advanced, by whom.
        Livewire::test(ListSettlements::class)
            ->assertSuccessful()
            ->assertSee('$300.00 advanced');
    }
}
