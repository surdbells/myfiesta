<?php

namespace Tests\Feature;

use App\Mail\RefundMadeElsewhere;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Ticket;
use App\Services\Legacy\LegacyImporter;
use App\Services\Legacy\LegacyMap;
use App\Services\Legacy\StripeRefundRecorder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The imported orders, checked against a Stripe that is faked.
 *
 * Every sale below is an invented one on the fixture's event, each set up to
 * disagree with Stripe in one particular way, and Stripe's side of each is
 * answered from the table in stripe(). Nothing reaches the network.
 */
class LegacyReconcileTest extends TestCase
{
    use LegacyFixtures;
    use RefreshDatabase;

    /**
     * Where these tests' reports go, instead of storage/app/reconciliation.
     *
     * The command resumes the newest report it finds there. Run on a host
     * that holds a real cutover report, a test resuming it would append this
     * database's orders to it and rewrite it.
     */
    private string $storage;

    /** Set to make every Stripe request fail this many times first. */
    private int $stripeFailsFirst = 0;

    /** Set to answer as Stripe does a test key asking about live objects. */
    private bool $wrongModeKey = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLegacyDatabase();

        config(['payments.stripe.secret_key' => 'rk_test_reconcile', 'legacy.stripe_key' => null]);

        // Pacing and backoff are real waits in production and none here.
        Sleep::fake();

        // --apply records the way a dashboard refund is recorded, and that
        // tells the organizer.
        Mail::fake();

        $this->storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'legacy-reconcile-'.bin2hex(random_bytes(6));
        mkdir($this->storage, 0770, true);
        $this->app->useStoragePath($this->storage);

        // Sale 17 (paid, cs_live_abc123, $16.20 with its tickets) and sale 18
        // (abandoned, no session) come from the fixture. The rest, $10.80 each:
        $this->legacySale(19, 1000, 'cs_test_matched');
        $this->legacySale(20, 1000, 'cs_test_short');
        $this->legacySale(21, 1000, 'cs_test_usd');
        $this->legacySale(22, 1000, 'cs_test_unpaid');
        $this->legacySale(23, 1000, 'cs_test_gone');
        $this->legacySale(24, 1000, 'AWAITING');
        $this->legacySale(25, 1000, 'cs_test_partial');
        $this->legacySale(26, 1000, 'cs_test_kept', 'PENDING');
        $this->legacySale(27, 1000, 'cs_test_was_refunded', 'refunded');
        $this->legacySale(28, 1000, 'cs_test_not_refunded', 'refunded');
        // Charged back, and part refunded before that.
        $this->legacySale(29, 1000, 'cs_test_disputed');
        // Two sales on one Checkout Session, which Stripe then refunded once.
        $this->legacySale(30, 1000, 'cs_test_shared');
        $this->legacySale(31, 1000, 'cs_test_shared');

        $map = new LegacyMap;
        $map->warm();
        (new LegacyImporter($map))->run();

        Http::fake(fn (Request $request) => $this->stripe($request));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->storage);

        $this->tearDownLegacyDatabase();

        parent::tearDown();
    }

    public function test_the_report_names_every_way_the_import_and_stripe_can_disagree(): void
    {
        $this->artisan('legacy:reconcile')
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('charged a different amount in Stripe')
            ->assertSuccessful();

        $this->assertSame([
            // Paid here for 1620, refunded in full in Stripe on the second page.
            '17' => 'refunded_in_stripe_only',
            '19' => 'matched',
            '20' => 'amount_mismatch',
            '21' => 'amount_mismatch',
            '22' => 'not_paid_in_stripe',
            '23' => 'missing_in_stripe',
            '24' => 'no_stripe_id',
            '25' => 'refunded_in_stripe_only',
            // Abandoned here, and Stripe kept the money.
            '26' => 'paid_in_stripe_only',
            // The old platform said refunded, and Stripe agrees.
            '27' => 'matched',
            '28' => 'refunded_here_only',
            // Paid, succeeded, the right amount: and a bank took it back.
            '29' => 'disputed_in_stripe',
            // Each on its own agrees with Stripe. Together they are one
            // payment credited to the organizer twice.
            '30' => 'shared_stripe_payment',
            '31' => 'shared_stripe_payment',
        ], $this->outcomes());

        // Sale 18 never reached Stripe and is not a finding.
        $this->assertArrayNotHasKey('18', $this->outcomes());

        $rows = $this->report();

        $this->assertSame('1080', $rows['20']['total_here']);
        $this->assertSame('1000', $rows['20']['stripe_amount']);
        // Filed under the amount, and the refund behind it still said.
        $this->assertStringContainsString('Also refunded 500 in Stripe, 0 here.', $rows['20']['detail']);
        $this->assertSame('USD', $rows['21']['stripe_currency']);
        $this->assertSame('1620', $rows['17']['stripe_refunded'], 'The failed refund on page one is not money back.');
        $this->assertSame('pi_17', $rows['17']['payment_intent']);

        // The chargeback, and the refund before it, both said.
        $this->assertStringContainsString('Disputed in Stripe: dp_29 lost 580 CAD fraudulent.', $rows['29']['detail']);
        $this->assertStringContainsString('Also refunded 500 in Stripe, 0 here.', $rows['29']['detail']);

        // Each names the other, so either line finds both.
        $this->assertStringContainsString($rows['31']['order_reference'], $rows['30']['detail']);
        $this->assertStringContainsString($rows['30']['order_reference'], $rows['31']['detail']);

        // A dispute the organizer won took nothing: sale 19 is still matched
        // above. The report is read worst first.
        $this->assertSame(
            ['disputed_in_stripe', 'shared_stripe_payment', 'shared_stripe_payment'],
            array_slice(array_column($this->reportInFileOrder(), 'outcome'), 1, 3),
        );
    }

    public function test_a_chargeback_this_database_already_holds_is_not_reported_again(): void
    {
        // The platform's own dispute handling heard about it after the
        // cutover and wrote the chargeback down. What is left is the refund.
        $order = $this->order(29);

        Dispute::create([
            'order_id' => $order->id, 'organization_id' => $order->organization_id,
            'event_id' => $order->event_id, 'gateway' => 'stripe',
            'gateway_reference' => 'dp_29', 'amount' => 580, 'currency' => 'CAD',
            'status' => 'lost', 'opened_at' => now(), 'closed_at' => now(),
        ]);

        $this->artisan('legacy:reconcile')->assertSuccessful();

        $this->assertSame('refunded_in_stripe_only', $this->outcomes()['29']);
    }

    public function test_the_same_stripe_refund_is_never_written_against_two_orders(): void
    {
        $recorder = app(StripeRefundRecorder::class);

        $refund = [[
            'id' => 're_shared', 'amount' => 1080, 'currency' => 'CAD',
            'status' => 'succeeded', 'created' => 1_716_000_000,
        ]];

        $first = $this->order(30);
        $second = $this->order(31);

        $this->assertStringStartsWith('recorded re_shared', $recorder->record($first, $refund)[0]);

        $this->assertSame(
            ["left re_shared: already recorded on order {$first->reference}"],
            $recorder->record($second, $refund),
        );

        $this->assertSame(1, Refund::where('gateway_reference', 're_shared')->count());
        $this->assertSame('paid', $second->fresh()->status);
        $this->assertSame(0, LedgerEntry::where('order_id', $second->id)->where('type', 'refund')->count());
    }

    public function test_the_report_carries_no_buyer_and_no_ticket(): void
    {
        $this->artisan('legacy:reconcile')->assertSuccessful();

        $csv = file_get_contents($this->latestReport());

        $this->assertStringNotContainsString('@example.test', $csv);
        $this->assertStringNotContainsString('MFST-', $csv);
    }

    public function test_a_dry_run_changes_nothing_and_only_reads(): void
    {
        $before = $this->state();

        $this->artisan('legacy:reconcile')->assertSuccessful();

        $this->assertSame($before, $this->state());

        $this->assertGreaterThan(0, Http::recorded()->count());
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
        Mail::assertNothingOutgoing();
    }

    public function test_apply_records_a_refund_stripe_made_once_and_marks_nothing_paid(): void
    {
        $full = $this->order(17);
        $paidAt = $full->paid_at?->toIso8601String();

        $this->artisan('legacy:reconcile --apply')->assertSuccessful();

        // The whole of it, written by RefundService as a refund made at the
        // processor — the same path a dashboard refund takes after cutover.
        $refund = Refund::where('gateway_reference', 're_17')->sole();

        $this->assertSame('succeeded', $refund->status);
        $this->assertSame(Refund::FROM_PROCESSOR, $refund->source);
        $this->assertSame(1620, (int) $refund->amount);
        $this->assertSame(120, (int) $refund->service_charge_amount);
        $this->assertSame('stripe', $refund->gateway);
        $this->assertNull($refund->issued_by);

        // The organizer's side reversed, and nothing more: the sale was 1500,
        // the refund takes 1500 back, and the service charge was never theirs.
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $full->id)->sum('amount'));

        $full->refresh();
        $this->assertSame('refunded', $full->status);
        $this->assertSame($paidAt, $full->paid_at?->toIso8601String(), 'When it was paid is not changed.');

        // All of it back, so none of it gets in.
        $this->assertSame(
            ['refunded', 'refunded'],
            Ticket::where('order_id', $full->id)->orderBy('code')->pluck('status')->all(),
        );
        $this->assertSame(2, $refund->tickets()->count());

        // Part of it back: recorded, split in proportion, the order partly refunded.
        $partial = $this->order(25);
        $share = Refund::where('gateway_reference', 're_25')->sole();

        $this->assertSame('partially_refunded', $partial->status);
        $this->assertSame(540, (int) $share->amount);
        $this->assertSame(40, (int) $share->service_charge_amount, '80 of 1080 is service charge; half of it is 40.');
        $this->assertSame(1000 - 500, (int) LedgerEntry::where('order_id', $partial->id)->sum('amount'));

        // The refund that failed in Stripe is not one.
        $this->assertFalse(Refund::where('gateway_reference', 're_17_failed')->exists());

        // Money Stripe holds for an order that is not paid here is somebody's
        // to look at. Nothing about it changes.
        $kept = $this->order(26);
        $this->assertSame('cancelled', $kept->status);
        $this->assertNull($kept->paid_at);
        $this->assertSame(0, $kept->refunds()->count());

        // Nor does an order Stripe never took money for.
        $this->assertSame('paid', $this->order(22)->status);

        // Nor one whose total disagrees with Stripe, refund or not: the
        // disagreement is a person's to settle before anything is written.
        $short = $this->order(20);
        $this->assertSame('paid', $short->status);
        $this->assertSame(0, $short->refunds()->count());

        // Nor a charged-back one, nor two sharing a payment, refund or not.
        foreach ([29, 30, 31] as $sale) {
            $this->assertSame('paid', $this->order($sale)->status);
            $this->assertSame(0, $this->order($sale)->refunds()->count());
        }

        $this->assertSame(2, AuditLog::where('action', 'refund.reconciled')->count());
        $this->assertSame(2, AuditLog::where('action', 'refund.made_elsewhere')->count());
        $this->assertSame(2, Refund::count());

        // The organizer is told, once per refund, as for any dashboard refund.
        Mail::assertQueued(RefundMadeElsewhere::class, 2);
        Mail::assertQueued(RefundMadeElsewhere::class, fn (RefundMadeElsewhere $mail) => $mail->hasTo('ada@lagosnights.test'));

        // The same again. Nothing new, and the report now agrees with Stripe.
        $ledger = LedgerEntry::count();

        $this->artisan('legacy:reconcile --apply')->assertSuccessful();

        $this->assertSame(2, Refund::count());
        $this->assertSame($ledger, LedgerEntry::count());
        $this->assertSame(2, AuditLog::where('action', 'refund.reconciled')->count());
        $this->assertSame('matched', $this->outcomes()['17']);
        $this->assertSame('matched', $this->outcomes()['25']);
        Mail::assertQueued(RefundMadeElsewhere::class, 2);
    }

    public function test_apply_after_a_dry_run_does_not_resume_the_dry_runs_report(): void
    {
        // The natural order on the day: look first, then apply. A resumed
        // dry-run report already names every order, and --apply reading it
        // as done would record nothing and finish green.
        $this->artisan('legacy:reconcile')->assertSuccessful();

        $this->assertSame(0, Refund::count());

        $this->artisan('legacy:reconcile --apply --resume')
            ->expectsOutputToContain('No report of this kind to resume')
            ->assertSuccessful();

        $this->assertSame(2, Refund::count());
        $this->assertSame('refunded', $this->order(17)->status);

        // Two reports, one of each kind.
        $reports = glob(storage_path('app/reconciliation').'/reconcile-*.csv');
        $this->assertCount(2, $reports);
        $this->assertCount(1, array_filter($reports, fn (string $f) => str_ends_with($f, '-apply.csv')));
    }

    public function test_the_reconcile_key_is_used_in_place_of_the_platforms_own(): void
    {
        config(['legacy.stripe_key' => 'rk_live_reconcile_only']);

        $this->artisan('legacy:reconcile --limit=1')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer rk_live_reconcile_only'));
        Http::assertNotSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer rk_test_reconcile'));
    }

    public function test_a_refund_made_through_this_platform_is_not_recorded_twice(): void
    {
        // An organizer refunded sale 25 here after the cutover, and
        // RefundService stored Stripe's refund id — the same one Stripe lists.
        $order = $this->order(25);

        Refund::create([
            'order_id' => $order->id, 'event_id' => $order->event_id,
            'organization_id' => $order->organization_id, 'currency' => 'CAD',
            'amount' => 540, 'service_charge_amount' => 40, 'gateway' => 'stripe',
            'gateway_reference' => 're_25', 'status' => 'succeeded',
        ]);

        $this->artisan('legacy:reconcile --apply')->assertSuccessful();

        $this->assertSame(1, Refund::where('order_id', $order->id)->count());
        $this->assertSame('matched', $this->outcomes()['25']);
    }

    public function test_recording_the_same_stripe_refund_twice_records_it_once(): void
    {
        $recorder = app(StripeRefundRecorder::class);
        $order = $this->order(25);

        $refunds = [[
            'id' => 're_25', 'amount' => 540, 'currency' => 'CAD',
            'status' => 'succeeded', 'created' => 1_716_000_000,
        ]];

        $this->assertSame(
            ['recorded re_25 (540 CAD, refunded in Stripe 2024-05-18)'],
            $recorder->record($order, $refunds),
        );
        $this->assertSame([], $recorder->record($order->fresh(), $refunds));

        $this->assertSame(1, Refund::where('order_id', $order->id)->count());
        $this->assertSame(1, LedgerEntry::where('order_id', $order->id)->where('type', 'refund')->count());
    }

    public function test_a_busy_stripe_is_waited_for_and_asked_again(): void
    {
        $this->stripeFailsFirst = 2;

        $this->artisan('legacy:reconcile --limit=1')->assertSuccessful();

        $this->assertSame(['17' => 'refunded_in_stripe_only'], $this->outcomes());

        // Backed off between the tries: one second, then two.
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 1000);
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 2000);
    }

    public function test_a_run_stripe_stopped_answering_resumes_where_it_was(): void
    {
        // Stripe down for the whole of the first run: every order is written
        // as not checked, and the command says so.
        $this->stripeFailsFirst = PHP_INT_MAX;

        $this->artisan('legacy:reconcile --limit=3')
            ->expectsOutputToContain('--resume')
            ->assertFailed();

        $this->assertSame(
            ['17' => 'unreachable', '19' => 'unreachable', '20' => 'unreachable'],
            $this->outcomes(),
        );

        // Back up. The same report, carried on: the three are asked about
        // again, the rest for the first time, and each order is one line.
        $this->stripeFailsFirst = 0;

        $this->artisan('legacy:reconcile --resume')->assertSuccessful();

        $outcomes = $this->outcomes();

        $this->assertCount(14, $outcomes);
        $this->assertSame('matched', $outcomes['19']);
        $this->assertCount(1, glob(storage_path('app/reconciliation').'/reconcile-*.csv'));
        $this->assertSame(14, count(file($this->latestReport())) - 1, 'One line per order, no repeats.');
    }

    public function test_a_key_for_the_wrong_mode_stops_before_calling_everything_missing(): void
    {
        $this->wrongModeKey = true;

        $this->artisan('legacy:reconcile')
            ->expectsOutputToContain('wrong mode')
            ->assertFailed();

        $this->assertSame([], $this->outcomes());
    }

    /**
     * Stripe, as far as these orders are concerned.
     */
    private function stripe(Request $request): PromiseInterface
    {
        if ($this->wrongModeKey) {
            return Http::response(['error' => [
                'type' => 'invalid_request_error',
                'code' => 'resource_missing',
                'message' => "No such checkout.session: 'cs_live_abc123'; a similar object exists in live mode, but a test mode key was used to make this request.",
            ]], 404);
        }

        if ($this->stripeFailsFirst > 0) {
            $this->stripeFailsFirst--;

            return Http::response(['error' => ['message' => 'Too many requests']], 429);
        }

        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $paid = fn (int $amount, string $currency, string $intent) => [
            'status' => 'complete', 'payment_status' => 'paid',
            'amount_total' => $amount, 'currency' => $currency,
            'payment_intent' => [
                'id' => $intent, 'status' => 'succeeded',
                'amount' => $amount, 'amount_received' => $amount, 'currency' => $currency,
            ],
        ];

        $sessions = [
            'cs_live_abc123' => $paid(1620, 'cad', 'pi_17'),
            // Unexpanded, as an older account version can answer: an id to
            // be fetched rather than an object.
            'cs_test_matched' => ['payment_intent' => 'pi_19'] + $paid(1080, 'cad', 'pi_19'),
            'cs_test_short' => $paid(1000, 'cad', 'pi_20'),
            'cs_test_usd' => $paid(1080, 'usd', 'pi_21'),
            'cs_test_unpaid' => [
                'status' => 'expired', 'payment_status' => 'unpaid',
                'amount_total' => 1080, 'currency' => 'cad', 'payment_intent' => null,
            ],
            'cs_test_partial' => $paid(1080, 'cad', 'pi_25'),
            'cs_test_kept' => $paid(1080, 'cad', 'pi_26'),
            'cs_test_was_refunded' => $paid(1080, 'cad', 'pi_27'),
            'cs_test_not_refunded' => $paid(1080, 'cad', 'pi_28'),
            // Still paid and succeeded, as a disputed payment stays.
            'cs_test_disputed' => $paid(1080, 'cad', 'pi_29'),
            'cs_test_shared' => $paid(1080, 'cad', 'pi_30'),
        ];

        $refund = fn (string $id, int $amount, string $status = 'succeeded') => [
            'id' => $id, 'amount' => $amount, 'currency' => 'cad',
            'status' => $status, 'created' => 1_716_000_000,
        ];

        if (preg_match('#/v1/checkout/sessions/(.+)$#', $path, $m)) {
            return isset($sessions[$m[1]])
                ? Http::response(['id' => $m[1]] + $sessions[$m[1]])
                : Http::response(['error' => [
                    'type' => 'invalid_request_error', 'code' => 'resource_missing',
                    'message' => "No such checkout.session: '{$m[1]}'",
                ]], 404);
        }

        if (preg_match('#/v1/payment_intents/(.+)$#', $path, $m)) {
            return Http::response([
                'id' => $m[1], 'status' => 'succeeded',
                'amount' => 1080, 'amount_received' => 1080, 'currency' => 'cad',
            ]);
        }

        if (str_ends_with($path, '/v1/refunds')) {
            $pages = [
                // Two pages: a failed attempt first, then the refund itself.
                'pi_17' => [
                    '' => [[$refund('re_17_failed', 1620, 'failed')], true],
                    're_17_failed' => [[$refund('re_17', 1620)], false],
                ],
                // Short-charged and part refunded: filed as the amount.
                'pi_20' => ['' => [[$refund('re_20', 500)], false]],
                'pi_25' => ['' => [[$refund('re_25', 540)], false]],
                'pi_27' => ['' => [[$refund('re_27', 1080)], false]],
                'pi_29' => ['' => [[$refund('re_29', 500)], false]],
                'pi_30' => ['' => [[$refund('re_shared', 1080)], false]],
            ];

            [$data, $more] = $pages[$query['payment_intent']][$query['starting_after'] ?? ''] ?? [[], false];

            return Http::response(['object' => 'list', 'data' => $data, 'has_more' => $more]);
        }

        if (str_ends_with($path, '/v1/disputes')) {
            $dispute = fn (string $id, int $amount, string $status) => [
                'id' => $id, 'amount' => $amount, 'currency' => 'cad',
                'status' => $status, 'reason' => 'fraudulent', 'created' => 1_716_500_000,
            ];

            $data = match ($query['payment_intent']) {
                // Lost: the bank has the rest of the money.
                'pi_29' => [$dispute('dp_29', 580, 'lost')],
                // Won: the money came back, and nothing is owed.
                'pi_19' => [$dispute('dp_19', 1080, 'won')],
                default => [],
            };

            return Http::response(['object' => 'list', 'data' => $data, 'has_more' => false]);
        }

        return Http::response(['error' => ['message' => 'unexpected '.$path]], 400);
    }

    /** @return array<string, string> legacy sale id => outcome */
    private function outcomes(): array
    {
        return array_map(fn (array $r) => $r['outcome'], $this->report());
    }

    /** @return array<string, array<string, string>> legacy sale id => line */
    private function report(): array
    {
        $rows = [];

        foreach ($this->reportInFileOrder() as $row) {
            $rows[$row['legacy_sale_id']] = $row;
        }

        ksort($rows);

        return $rows;
    }

    /** @return list<array<string, string>> */
    private function reportInFileOrder(): array
    {
        $path = $this->latestReport();

        if ($path === null) {
            return [];
        }

        $lines = array_map(fn (string $l) => str_getcsv($l, escape: ''), file($path, FILE_IGNORE_NEW_LINES));
        $header = array_shift($lines);

        return array_map(fn (array $line) => array_combine($header, $line), $lines);
    }

    private function latestReport(): ?string
    {
        $files = glob(storage_path('app/reconciliation').'/reconcile-*.csv') ?: [];
        sort($files);

        return $files === [] ? null : end($files);
    }

    private function order(int $legacySaleId): Order
    {
        return Order::findOrFail(
            DB::table('legacy_map')->where('source_table', 'tickets_sales')
                ->where('source_id', (string) $legacySaleId)->value('target_id')
        );
    }

    /** Everything --apply could touch, and some things it must not. */
    private function state(): array
    {
        return [
            Refund::count(),
            LedgerEntry::count(),
            AuditLog::count(),
            Order::orderBy('id')->get(['status', 'paid_at', 'refunded_at', 'gateway_payment_reference'])->toArray(),
            Ticket::orderBy('id')->pluck('status')->all(),
        ];
    }
}
