<?php

namespace App\Services\Disputes;

use App\Services\Checkout\TurnedAway;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Letting the dispute evidence go once no dispute can come.
 *
 * Eighteen months after a night, no card network lets anybody dispute a
 * payment for it, and what was kept only to answer one has no reason left to
 * exist: the address and browser on each order are cleared, the ticket
 * history is deleted, and so is the processor's record of the payment — and,
 * once a dispute on that night has closed, the answer that was given to it
 * (dispute_evidence, and the documents sent with it on the private disk).
 * Anything about an order whose dispute is still open waits until it closes.
 *
 * What is never touched, by design and by test: orders, tickets, scans, the
 * ledger and the audit trail, which are records; and the note that a night
 * took place, which names nobody.
 *
 * The two tables here refuse a delete from anybody else (see their
 * migrations). This says, for its own transaction only, that it is the prune.
 */
class EvidenceRetention
{
    /** @return array{orders: int, activity: int, payments: int, answers: int} what was cleared or deleted */
    public function prune(): array
    {
        $pruned = $this->pruneRecords();

        // The answers' documents, once their rows are gone: a file is not
        // part of any transaction, so it goes after the one that decided it.
        foreach ($pruned['answered'] as $disputeId) {
            Storage::disk(DisputeDesk::DISK)->deleteDirectory('disputes/'.$disputeId);
        }

        unset($pruned['answered']);

        return $pruned;
    }

    /**
     * The nights past the window: their listed end further back than the
     * retention period. Read whole, deleted or not: a night gone from every
     * list still had buyers.
     */
    public static function nightsPast(): Builder
    {
        return DB::table('events')
            ->whereRaw("coalesce(ends_at, starts_at + interval '".TurnedAway::HOURS_WITHOUT_AN_END." hours') < ?", [self::cutoff()])
            ->select('id');
    }

    /**
     * Which of these nights are past the window. Nothing more is written
     * about them (ActivityLog): no dispute can come, so there is nothing for
     * it to answer.
     *
     * @param  iterable<string|null>  $eventIds
     * @return Collection<int, string>
     */
    public static function past(iterable $eventIds): Collection
    {
        $ids = collect($eventIds)->filter()->unique()->values()->all();

        return $ids === []
            ? collect()
            : self::nightsPast()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (string) $id)->values();
    }

    private static function cutoff(): CarbonInterface
    {
        return now()->subMonths((int) config('disputes.retention_months', 18));
    }

    /** @return array{orders: int, activity: int, payments: int, answers: int, answered: list<string>} */
    private function pruneRecords(): array
    {
        $past = self::nightsPast();

        $disputed = DB::table('disputes')->where('status', 'open')->select('order_id');

        return DB::transaction(function () use ($past, $disputed) {
            DB::select("select set_config('myfiesta.retention_prune', 'on', true)");

            try {
                $orders = DB::table('orders')
                    ->whereIn('event_id', $past)
                    ->whereNotIn('id', $disputed)
                    ->where(fn (Builder $query) => $query->whereNotNull('purchase_ip')->orWhereNotNull('purchase_user_agent'))
                    ->update(['purchase_ip' => null, 'purchase_user_agent' => null]);

                // Everything written about those nights, whenever it was
                // written: a link opened the week after the night answers no
                // dispute once none can come, and keeping it its own eighteen
                // months would keep an app user's address for as long as they
                // kept opening old tickets.
                $activity = DB::table('ticket_activity')
                    ->whereIn('event_id', $past)
                    ->where(fn (Builder $query) => $query->whereNull('order_id')->orWhereNotIn('order_id', $disputed))
                    ->delete();

                $payments = DB::table('payment_evidence')
                    ->whereIn('event_id', $past)
                    ->whereNotIn('order_id', $disputed)
                    ->delete();

                // The answers to disputes on those nights, once each dispute
                // has closed. The dispute row stays — that it happened, and
                // how it ended, is a record; what was said to the bank names
                // the buyer and goes with the rest.
                $answered = DB::table('dispute_evidence')
                    ->whereIn('event_id', $past)
                    ->whereNotIn('dispute_id', DB::table('disputes')->where('status', 'open')->select('id'))
                    ->pluck('dispute_id')
                    ->map(fn ($id) => (string) $id)
                    ->all();

                $answers = DB::table('dispute_evidence')->whereIn('dispute_id', $answered)->delete();
            } finally {
                // The rest of whatever transaction this is inside is not the
                // prune, whatever it goes on to do. After a failed statement
                // the transaction is being rolled back, which undoes the
                // setting anyway, and saying so again would only hide why.
                rescue(fn () => DB::select("select set_config('myfiesta.retention_prune', 'off', true)"), report: false);
            }

            return ['orders' => $orders, 'activity' => $activity, 'payments' => $payments, 'answers' => $answers, 'answered' => $answered];
        });
    }
}
