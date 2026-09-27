<?php

namespace App\Console\Commands;

use App\Services\Legacy\LegacyReconciler;
use App\Services\Legacy\ReconcileOutcome;
use App\Services\Legacy\StripeReader;
use App\Services\Legacy\StripeReadFailed;
use App\Services\Legacy\StripeRefundRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The imported orders, checked against Stripe.
 *
 *   php artisan legacy:reconcile             a report, and nothing changed
 *   php artisan legacy:reconcile --resume    carry on with the last report
 *   php artisan legacy:reconcile --apply     record refunds Stripe already made
 *
 * The report is a CSV in storage/app/reconciliation/ and a summary here.
 * docs/CUTOVER.md says what to do with each kind of line in it.
 *
 * Written to as it goes, one line per order, so a run that stops — Stripe
 * slow, the connection gone, somebody pressing ctrl-C — has lost nothing it
 * had already asked, and `--resume` starts from where it was. Orders Stripe
 * did not answer for are asked about again on the resume.
 *
 * A dry run and an --apply run keep separate reports, and each resumes only
 * its own. Otherwise `--apply --resume` after a finished dry run would find
 * every order already in the report and record nothing, and say it was done.
 *
 * The key is LEGACY_STRIPE_KEY if set (config/legacy.php), else the platform's
 * own. It should be a restricted key that can only read.
 */
class ReconcileLegacy extends Command
{
    protected $signature = 'legacy:reconcile
        {--apply : record refunds Stripe has already made that this database has not; nothing else is changed}
        {--resume : continue the most recent report of the same kind rather than starting a new one}
        {--rate= : Stripe requests per second, at most (default LEGACY_STRIPE_RATE, or 20)}
        {--limit= : stop after this many orders, for a first look}';

    protected $description = 'Check imported orders against Stripe and report every disagreement';

    private const COLUMNS = [
        'legacy_sale_id', 'order_id', 'order_reference', 'outcome',
        'status_here', 'currency_here', 'total_here', 'refunded_here',
        'stripe_reference', 'payment_intent', 'stripe_status',
        'stripe_currency', 'stripe_amount', 'stripe_refunded',
        'detail', 'action',
    ];

    public function handle(StripeRefundRecorder $recorder): int
    {
        $key = (string) (config('legacy.stripe_key') ?: config('payments.stripe.secret_key'));

        if ($key === '') {
            $this->components->error('Neither LEGACY_STRIPE_KEY nor STRIPE_SECRET_KEY is set.');
            $this->line('  Set LEGACY_STRIPE_KEY to a restricted key with read access to Checkout');
            $this->line('  Sessions, PaymentIntents, Refunds and Disputes, on the Stripe account the');
            $this->line('  old platform charged through.');

            return self::FAILURE;
        }

        if (str_starts_with($key, 'sk_')) {
            // Not refused: every request here is a GET whatever the key. But
            // the key is the only guarantee that stays true if this changes.
            $this->components->warn('This is a full secret key. A restricted read-only key (rk_) is safer: see docs/CUTOVER.md.');
        }

        $imported = DB::table('legacy_map')->where('source_table', 'tickets_sales')->count();

        if ($imported === 0) {
            $this->components->warn('No imported orders to check. Run legacy:import first.');

            return self::SUCCESS;
        }

        [$path, $rows] = $this->reportFile();

        // Asked about again: Stripe did not answer for these last time.
        $done = collect($rows)
            ->reject(fn (array $r) => $r['outcome'] === ReconcileOutcome::Unreachable->value)
            ->mapWithKeys(fn (array $r) => [$r['order_id'] => true])
            ->all();

        $this->components->info(
            ($this->option('apply') ? 'Recording refunds Stripe already made' : 'Dry run: nothing here will change')
            .'. Report: '.$path
        );

        if ($this->option('apply')) {
            // Recorded the way a refund made in the Stripe dashboard is after
            // the cutover, and that path tells the organizer.
            $this->line('  Each refund recorded emails the people who can refund on that organization.');
        }

        $rate = $this->option('rate') ?? config('legacy.stripe_rate', 20);

        $reconciler = new LegacyReconciler(
            new StripeReader($key, max(0, (int) $rate)),
            $recorder,
        );

        // Asked of the file, not the stream: where an append-mode stream says
        // it is before its first write is not something PHP promises.
        clearstatcache();
        $fresh = ! is_file($path) || filesize($path) === 0;

        $handle = fopen($path, 'a');

        if ($fresh) {
            fputcsv($handle, self::COLUMNS, escape: '');
        }

        // A count rather than a bar. Abandoned checkouts are passed over
        // without a line, so no total known up front would be reached.
        $bar = $this->output->createProgressBar();

        try {
            $result = $reconciler->run(
                (bool) $this->option('apply'),
                $done,
                $this->option('limit') !== null ? (int) $this->option('limit') : null,
                function (array $row) use ($handle, $bar, &$rows): void {
                    // Flushed per line: this file is the resume point.
                    fputcsv($handle, array_map(fn (string $c) => $row[$c], self::COLUMNS), escape: '');
                    fflush($handle);

                    $rows[] = array_map('strval', $row);
                    $bar->advance();
                },
            );
        } catch (StripeReadFailed $e) {
            fclose($handle);
            $this->newLine(2);
            $this->components->error($e->getMessage());
            $this->line('  Nothing further was asked. Fix the key and run again with --resume.');

            return self::FAILURE;
        }

        fclose($handle);
        $bar->finish();
        $this->newLine(2);

        $rows = $this->tidy($path, $rows);

        return $this->summarise($rows, $result['skipped'], $path);
    }

    /**
     * The report to write to, and whatever it already holds.
     *
     * @return array{0: string, 1: list<array<string, string>>}
     */
    private function reportFile(): array
    {
        $directory = storage_path('app/reconciliation');

        if (! is_dir($directory)) {
            mkdir($directory, 0770, true);
        }

        $kind = $this->option('apply') ? '-apply' : '';

        if ($this->option('resume')) {
            // Only reports of the same kind: a dry run's lines say what was
            // found, not what was recorded, and resuming one with --apply
            // would pass over every order it already names.
            $existing = array_values(array_filter(
                glob($directory.'/reconcile-*.csv') ?: [],
                fn (string $file) => str_ends_with($file, '-apply.csv') === ($kind !== ''),
            ));
            sort($existing);
            $latest = end($existing);

            if ($latest !== false) {
                return [$latest, $this->read($latest)];
            }

            $this->components->warn('No report of this kind to resume. Starting a new one.');
        }

        // To the millisecond, so two runs never share a file and the newest
        // is always last by name, which is how --resume finds it.
        $name = fn () => $directory.'/reconcile-'.now()->format('Ymd-His-v').$kind.'.csv';
        $path = $name();

        while (file_exists($path)) {
            usleep(1000);
            $path = $name();
        }

        return [$path, []];
    }

    /** @return list<array<string, string>> */
    private function read(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '');

        while ($header && ($line = fgetcsv($handle, escape: '')) !== false) {
            if (count($line) === count($header)) {
                $rows[] = array_combine($header, $line);
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * The finished report: one line per order, worst first.
     *
     * An interrupted run and its resume can both have written a line for the
     * same order — the resume asks again about the ones Stripe did not answer.
     * The later line is the true one. Rewritten beside the original and moved
     * over it, so a failure here leaves the working file as it was.
     *
     * @param  list<array<string, string>>  $rows
     * @return list<array<string, string>>
     */
    private function tidy(string $path, array $rows): array
    {
        $latest = [];

        foreach ($rows as $row) {
            $latest[$row['order_id']] = $row;
        }

        $rows = array_values($latest);

        usort($rows, fn (array $a, array $b) => [
            ReconcileOutcome::from($a['outcome'])->rank(), (int) $a['legacy_sale_id'],
        ] <=> [
            ReconcileOutcome::from($b['outcome'])->rank(), (int) $b['legacy_sale_id'],
        ]);

        $temporary = $path.'.tmp';
        $handle = fopen($temporary, 'w');
        fputcsv($handle, self::COLUMNS, escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $c) => $row[$c] ?? '', self::COLUMNS), escape: '');
        }

        fclose($handle);
        rename($temporary, $path);

        return $rows;
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function summarise(array $rows, int $skipped, string $path): int
    {
        $counts = collect($rows)->countBy('outcome');

        $this->table(
            ['outcome', 'orders', 'meaning'],
            collect(ReconcileOutcome::cases())
                ->sortBy(fn (ReconcileOutcome $o) => $o->rank())
                ->filter(fn (ReconcileOutcome $o) => $counts->has($o->value))
                ->map(fn (ReconcileOutcome $o) => [$o->value, number_format($counts[$o->value]), $o->label()])
                ->values()
                ->all(),
        );

        $this->line('  <fg=gray>'.number_format($skipped).' abandoned checkouts never reached Stripe and were not checked.</>');

        if ($counts->has(ReconcileOutcome::AmountMismatch->value)) {
            // The one finding that can be about the import rather than about
            // an order: the total is recomputed from `_cost`, and if the old
            // checkout charged buyers something else, every paid order differs
            // from Stripe by the same rule.
            $this->newLine();
            $this->components->warn(
                number_format($counts[ReconcileOutcome::AmountMismatch->value]).' orders were charged a different amount in Stripe.'
            );
            $this->line('  If most of them differ the same way, the import\'s totals are not what the old');
            $this->line('  platform charged. Stop there: docs/CUTOVER.md, "Amount or currency differs".');
        }

        $findings = array_values(array_filter(
            $rows,
            fn (array $r) => $r['outcome'] !== ReconcileOutcome::Matched->value,
        ));

        if ($findings !== []) {
            $this->newLine();

            // The first screenful. The file has every one.
            $this->table(
                ['legacy id', 'order', 'outcome', 'here', 'stripe', 'action'],
                array_map(fn (array $r) => [
                    $r['legacy_sale_id'],
                    $r['order_reference'],
                    $r['outcome'],
                    "{$r['status_here']} {$r['total_here']} {$r['currency_here']}",
                    trim("{$r['stripe_status']} {$r['stripe_amount']} {$r['stripe_currency']}"),
                    $r['action'] !== '' ? $r['action'] : $r['detail'],
                ], array_slice($findings, 0, 40)),
            );

            if (count($findings) > 40) {
                $this->line('  <fg=gray>and '.number_format(count($findings) - 40).' more in the report.</>');
            }
        }

        $this->newLine();
        $this->line('  Report: '.$path);

        if ($counts->has(ReconcileOutcome::Unreachable->value)) {
            $this->components->warn('Some orders were not checked. Run again with --resume.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
