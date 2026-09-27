<?php

namespace Tests\Feature;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\Event;
use App\Models\Order;
use App\Models\PaymentEvidence;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketType;
use App\Services\Audit\Auditor;
use App\Services\Disputes\DisputeDesk;
use App\Services\Door\CheckInService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\SellsTicketsForDisputes;
use Tests\TestCase;

/**
 * Letting the dispute evidence go when no dispute can come, and nothing else.
 *
 * Eighteen months after a night — past every card network's window — the
 * address and browser on its orders are cleared, and its ticket history and
 * payment records are deleted. The orders, tickets, scans, ledger and audit
 * trail they sat beside are records, and are exactly as they were.
 */
class DisputeEvidenceRetentionTest extends TestCase
{
    use RefreshDatabase, SellsTicketsForDisputes;

    private const RECORDS = ['orders', 'tickets', 'ticket_scans', 'ledger_entries', 'audit_logs', 'event_completions', 'disputes', 'ticket_transfers'];

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcessors();

        config(['trustedproxy.proxies' => ['10.0.0.0/8']]);

        [$this->event, $this->type] = $this->night('CAD', [
            'starts_at' => now()->addDays(10)->setTime(22, 0),
            'ends_at' => now()->addDays(11)->setTime(3, 0),
        ]);
    }

    /** A night's worth of evidence: an order, its payment record, its history, its door. */
    private function evidenceFor(Event $event, TicketType $type): Order
    {
        $order = $this->buy($event, $type,
            server: ['REMOTE_ADDR' => '10.0.0.5'],
            headers: ['X-Forwarded-For' => '198.51.100.23', 'User-Agent' => 'Mozilla/5.0 Firefox/131.0'],
        );

        $pi = 'pi_'.Str::random(24);
        $ch = 'ch_'.Str::random(24);
        $this->processor['api.stripe.com/v1/payment_intents/*'] = fn () => Http::response($this->stripeIntent($pi, $ch, $order->total_amount));
        $this->processor['api.stripe.com/v1/charges/*'] = fn () => Http::response($this->stripeCharge($ch, $pi, $order->total_amount));

        $this->stripePaid($order, $pi)->assertOk();
        $this->artisan('disputes:collect-evidence')->assertSuccessful();

        $this->get("/api/tickets/{$order->access_token}")->assertOk();

        app(CheckInService::class)->scan(Ticket::where('order_id', $order->id)->firstOrFail()->code, $event->id);
        app(Auditor::class)->record('event.published', $event, null, $event->organization_id);

        $this->assertSame(PaymentEvidence::CAPTURED, PaymentEvidence::where('order_id', $order->id)->sole()->status);

        return $order->refresh();
    }

    /** @return array<string, int> */
    private function records(): array
    {
        return collect(self::RECORDS)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }

    private function prune(): void
    {
        $this->artisan('disputes:prune-evidence')->assertSuccessful();
    }

    public function test_eighteen_months_after_the_night_the_evidence_goes_and_every_record_stays(): void
    {
        $order = $this->evidenceFor($this->event, $this->type);

        // A later night, with its own evidence, still inside its window.
        [$later, $laterType] = $this->night('CAD', [
            'starts_at' => now()->addMonths(6),
            'ends_at' => now()->addMonths(6)->addHours(5),
        ]);
        $recent = $this->evidenceFor($later, $laterType);

        $this->travelTo($this->event->ends_at->copy()->addMonths(18)->addDay());

        // Written down for the first night as a record would be.
        $this->artisan('disputes:record-completions')->assertSuccessful();

        $records = $this->records();
        $ledger = DB::table('ledger_entries')->orderBy('id')->get()->toArray();

        $this->prune();

        $this->assertSame($records, $this->records(), 'The prune deleted or added a record.');
        $this->assertEquals($ledger, DB::table('ledger_entries')->orderBy('id')->get()->toArray(), 'The prune touched the ledger.');

        $order->refresh();
        $this->assertNull($order->purchase_ip);
        $this->assertNull($order->purchase_user_agent);
        $this->assertSame('paid', $order->status);
        $this->assertSame(0, TicketActivity::where('event_id', $this->event->id)->count());
        $this->assertSame(0, PaymentEvidence::where('order_id', $order->id)->count());

        // The later night's evidence is inside its window and untouched.
        $recent->refresh();
        $this->assertSame('198.51.100.23', $recent->purchase_ip);
        $this->assertGreaterThan(0, TicketActivity::where('event_id', $later->id)->count());
        $this->assertSame(1, PaymentEvidence::where('order_id', $recent->id)->count());

        // A second night finds nothing more to do.
        $after = [$this->records(), TicketActivity::count(), PaymentEvidence::count()];
        $this->prune();
        $this->assertSame($after, [$this->records(), TicketActivity::count(), PaymentEvidence::count()]);
    }

    public function test_nothing_goes_a_day_early(): void
    {
        $order = $this->evidenceFor($this->event, $this->type);

        $this->travelTo($this->event->ends_at->copy()->addMonths(18)->subDay());
        $this->prune();

        $this->assertSame('198.51.100.23', $order->fresh()->purchase_ip);
        $this->assertGreaterThan(0, TicketActivity::where('order_id', $order->id)->count());
        $this->assertSame(1, PaymentEvidence::where('order_id', $order->id)->count());
    }

    public function test_something_written_after_the_night_goes_with_the_rest_and_nothing_is_written_after_that(): void
    {
        $order = $this->evidenceFor($this->event, $this->type);

        // The ticket link opened a week after the night, and again seventeen
        // months on: eighteen months from each would keep an address for as
        // long as somebody kept opening old tickets.
        $this->travelTo($this->event->ends_at->copy()->addWeek());
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 Late/1.0'])->get("/api/tickets/{$order->access_token}")->assertOk();
        $this->travelTo($this->event->ends_at->copy()->addMonths(17));
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->withHeaders(['User-Agent' => 'Mozilla/5.0 Later/1.0'])
            ->get("/api/tickets/{$order->access_token}")->assertOk();

        $this->assertSame(2, TicketActivity::where('order_id', $order->id)->whereIn('user_agent', ['Mozilla/5.0 Late/1.0', 'Mozilla/5.0 Later/1.0'])->count());

        $this->travelTo($this->event->ends_at->copy()->addMonths(18)->addDay());
        $this->prune();

        $this->assertSame(0, TicketActivity::where('order_id', $order->id)->count());

        // Past the window, an opening is not written down at all.
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 Latest/1.0'])->get("/api/tickets/{$order->access_token}")->assertOk();
        $this->assertSame(0, TicketActivity::where('order_id', $order->id)->count());
    }

    public function test_an_order_whose_dispute_is_still_open_keeps_everything_until_it_closes(): void
    {
        $order = $this->evidenceFor($this->event, $this->type);

        Dispute::create([
            'order_id' => $order->id,
            'organization_id' => $order->organization_id,
            'event_id' => $order->event_id,
            'gateway' => 'stripe',
            'gateway_reference' => 'dp_'.Str::random(24),
            'amount' => $order->total_amount,
            'currency' => 'CAD',
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->travelTo($this->event->ends_at->copy()->addMonths(18)->addDay());
        $this->prune();

        $this->assertSame('198.51.100.23', $order->fresh()->purchase_ip);
        $this->assertGreaterThan(0, TicketActivity::where('order_id', $order->id)->count());
        $this->assertSame(1, PaymentEvidence::where('order_id', $order->id)->count());
    }

    public function test_the_answer_to_a_closed_dispute_goes_with_the_rest_and_the_dispute_itself_stays(): void
    {
        Storage::fake(DisputeDesk::DISK);

        $closed = $this->disputeWithAnswer($this->evidenceFor($this->event, $this->type), 'won');
        $open = $this->disputeWithAnswer($this->evidenceFor($this->event, $this->type), 'open');

        $this->travelTo($this->event->ends_at->copy()->addMonths(18)->addDay());
        $this->prune();

        $this->assertSame(0, DisputeEvidence::where('dispute_id', $closed->id)->count());
        Storage::disk(DisputeDesk::DISK)->assertMissing('disputes/'.$closed->id.'/receipt-sent.pdf');
        $this->assertSame('won', $closed->fresh()->status);

        // Still open: its answer, and the documents sent with it, wait.
        $this->assertSame(1, DisputeEvidence::where('dispute_id', $open->id)->count());
        Storage::disk(DisputeDesk::DISK)->assertExists('disputes/'.$open->id.'/receipt-sent.pdf');
    }

    private function disputeWithAnswer(Order $order, string $status): Dispute
    {
        $dispute = Dispute::create([
            'order_id' => $order->id,
            'organization_id' => $order->organization_id,
            'event_id' => $order->event_id,
            'gateway' => 'stripe',
            'gateway_reference' => 'dp_'.Str::random(24),
            'amount' => $order->total_amount,
            'currency' => 'CAD',
            'reason' => 'product_not_received',
            'status' => $status,
            'opened_at' => now(),
            'closed_at' => $status === 'open' ? null : now(),
        ]);

        app(DisputeDesk::class)->build($dispute);
        Storage::disk(DisputeDesk::DISK)->put('disputes/'.$dispute->id.'/receipt-sent.pdf', '%PDF-1.7');

        return $dispute;
    }

    public function test_only_the_prune_may_delete_and_only_inside_itself(): void
    {
        $order = $this->evidenceFor($this->event, $this->type);

        $this->travelTo($this->event->ends_at->copy()->addMonths(18)->addDay());
        $this->prune();

        // A row for the same old night, written after the prune ran: the
        // permission the prune gave itself ended with it.
        DB::table('ticket_activity')->insert([
            'id' => (string) Str::uuid(),
            'event_id' => $order->event_id,
            'order_id' => $order->id,
            'kind' => TicketActivity::TICKET_PAGE,
            'occurred_at' => now()->subYears(2),
        ]);

        try {
            DB::transaction(fn () => DB::table('ticket_activity')->where('order_id', $order->id)->delete());
            $this->fail('A delete outside the prune got through.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('retention prune', $e->getMessage());
        }
    }

    public function test_the_schedule_runs_all_three_and_none_is_aimed_at_a_record(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command);

        foreach (['disputes:collect-evidence', 'disputes:record-completions', 'disputes:prune-evidence'] as $expected) {
            $this->assertTrue(
                $commands->contains(fn (string $command) => str_contains($command, $expected)),
                "The schedule does not run {$expected}.",
            );
        }

        $commands
            ->filter(fn (string $command) => str_contains($command, 'disputes:'))
            ->each(fn (string $command) => $this->assertDoesNotMatchRegularExpression('/audit|ledger|activitylog/i', $command));
    }
}
