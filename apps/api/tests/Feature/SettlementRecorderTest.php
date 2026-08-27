<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Payouts\SettlementRecorder;
use App\Services\Payouts\SettlementRefused;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recording that money left the building.
 *
 * The narrowest operation on this platform: a real transfer to a real bank,
 * made by a person, and the hardest thing here to walk back. The rules were
 * previously inside a closure in an admin table definition, where the only way
 * to reach them was to drive that table through Livewire — so the most
 * consequential logic on the platform was also the least tested.
 */
class SettlementRecorderTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->finance = User::factory()->create();

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);
    }

    private function owed(int $amount, string $currency = 'CAD'): void
    {
        LedgerEntry::create([
            'organization_id' => $this->org->id,
            'event_id' => $currency === 'CAD' ? $this->event->id : null,
            'type' => 'sale',
            'amount' => $amount,
            'currency' => $currency,
            'occurred_at' => now(),
        ]);
    }

    private function record(int $amount, ?string $note = null, string $currency = 'CAD'): Settlement
    {
        return app(SettlementRecorder::class)->record(
            $this->org,
            new Money($amount, $currency),
            'interac',
            $note,
            $this->finance,
        );
    }

    private function balance(string $currency = 'CAD'): int
    {
        return (int) LedgerEntry::where('organization_id', $this->org->id)
            ->where('currency', $currency)
            ->sum('amount');
    }

    // --- classification ------------------------------------------------------

    public function test_paying_exactly_what_is_owed_is_a_full_settlement_and_zeroes_the_balance(): void
    {
        $this->owed(50_000);

        $settlement = $this->record(50_000);

        $this->assertSame('full', $settlement->type);
        $this->assertSame(0, $this->balance());
    }

    public function test_paying_less_is_partial_and_leaves_the_rest(): void
    {
        $this->owed(50_000);

        $settlement = $this->record(20_000);

        $this->assertSame('partial', $settlement->type);
        $this->assertSame(30_000, $this->balance());
    }

    public function test_paying_more_than_is_owed_needs_a_reason(): void
    {
        $this->owed(10_000);

        // Legitimate — an advance before a weekend, a goodwill payment — and
        // every one of those is a decision somebody is asked about later.
        $this->expectException(SettlementRefused::class);
        $this->expectExceptionMessage('needs a reason on the record');

        $this->record(25_000);
    }

    public function test_an_overdraft_with_a_reason_is_recorded_and_the_balance_goes_negative(): void
    {
        $this->owed(10_000);

        $settlement = $this->record(25_000, 'Advance agreed with the promoter before the weekend.');

        $this->assertSame('overdraft', $settlement->type);
        // Negative on purpose: they have been paid money they have not yet
        // earned, and the next sale settles it. Clamping to zero would lose
        // that.
        $this->assertSame(-15_000, $this->balance());
    }

    public function test_a_blank_note_is_not_a_reason(): void
    {
        $this->owed(10_000);

        $this->expectException(SettlementRefused::class);

        $this->record(25_000, '   ');
    }

    public function test_settling_against_nothing_owed_is_an_overdraft(): void
    {
        // No ledger entries at all. Paying somebody with a zero balance is
        // paying more than is owed, and has to say why.
        $this->expectException(SettlementRefused::class);

        $this->record(5_000);
    }

    // --- refusals ------------------------------------------------------------

    public function test_a_settlement_cannot_be_zero_or_negative(): void
    {
        $this->owed(50_000);

        // Returning money is a refund against an order. A negative settlement
        // would put an entry in the ledger that raises a balance and calls
        // itself a payout.
        $this->expectException(SettlementRefused::class);
        $this->expectExceptionMessage('positive amount');

        $this->record(-5_000);
    }

    // --- the two halves of one fact -----------------------------------------

    public function test_the_settlement_and_its_ledger_entry_are_written_together(): void
    {
        $this->owed(50_000);

        $settlement = $this->record(20_000);

        $entry = LedgerEntry::where('organization_id', $this->org->id)
            ->where('type', 'settlement')
            ->firstOrFail();

        // Either without the other leaves the balance disagreeing with the
        // payout history, and the ledger is append-only — there is no tidying
        // it afterwards.
        $this->assertSame(-20_000, (int) $entry->amount);
        $this->assertStringContainsString($settlement->id, (string) $entry->reason);
    }

    public function test_who_recorded_it_is_kept(): void
    {
        $this->owed(50_000);

        $settlement = $this->record(50_000);

        $this->assertSame($this->finance->id, $settlement->settled_by);
        $this->assertNotNull($settlement->settled_at);

        $this->assertSame(1, AuditLog::where('action', 'settlement.recorded')->count());
    }

    // --- currencies ----------------------------------------------------------

    public function test_currencies_are_settled_separately(): void
    {
        $this->owed(50_000, 'CAD');
        $this->owed(80_000, 'NGN');

        $this->record(50_000, null, 'CAD');

        // An organization running Toronto and Lagos has two balances, and
        // settling one must not touch the other — adding CAD to NGN produces a
        // number that is not money.
        $this->assertSame(0, $this->balance('CAD'));
        $this->assertSame(80_000, $this->balance('NGN'));
    }

    public function test_a_currency_with_nothing_owed_is_still_an_overdraft_against_itself(): void
    {
        $this->owed(50_000, 'CAD');

        // Plenty owed in CAD, nothing in NGN. Classification is per currency,
        // so this is an overdraft even though the organization is well in
        // credit overall.
        $this->expectException(SettlementRefused::class);

        $this->record(1_000, null, 'NGN');
    }
}
