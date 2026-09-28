<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Organization;
use App\Services\Audit\Auditor;
use App\Services\Checkout\TaxLine;
use App\Services\Disputes\RiskSignals;
use App\Services\Receipts\Receipt;
use App\Services\Settings\SellerOfRecord;
use App\Support\Csv;
use App\Support\Listing;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every order the organization has taken, across all of its events.
 *
 * The console could already show orders, but only inside one event — which
 * answers "how did Friday go" and not the question support actually arrives
 * with: somebody is on the phone with a reference, or an email address, and
 * nobody knows which night they bought for. Searching per event means opening
 * events until one matches.
 *
 * Read-only. Refunds stay on the event's own screen, where the tickets being
 * returned are listed beside the order — money goes back one ticket at a
 * time, and a list this wide is the wrong place to start that.
 */
class OrderController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $filters = $this->filters($request);

        $orders = $this->sorted($this->filtered($organization, $filters), $request)
            ->with('event:id,title')
            ->withCount('tickets')
            ->withSum(['refunds as refunded_amount' => fn ($q) => $q->where('status', 'succeeded')], 'amount')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        // Worked out for the page in two queries rather than per row.
        $signals = app(RiskSignals::class)->forOrders($orders->getCollection());

        return response()->json([
            'data' => $orders->getCollection()->map(fn (Order $order) => [
                'id' => $order->id,
                'reference' => $order->reference,
                'buyer_name' => $order->buyer_name,
                'buyer_email' => $order->buyer_email,
                'status' => $order->status,
                'paid_at' => $order->paid_at,
                'tickets_count' => (int) $order->tickets_count,
                'event' => $order->event
                    ? ['id' => $order->event->id, 'title' => $order->event->title]
                    : null,
                'total' => ['amount' => (int) $order->total_amount, 'currency' => $order->currency],
                'refunded' => [
                    'amount' => (int) $order->refunded_amount,
                    'currency' => $order->currency,
                ],
                // Facts about this buyer worth knowing before the night, not a
                // score. Absent on most orders.
                'signals' => $signals[$order->id] ?? [],
            ])->values(),
            'meta' => [
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'summary' => $this->summary($orders->getCollection()),
            ],
        ]);
    }

    /**
     * The same orders as the screen, as a spreadsheet.
     *
     * Every order the current filter matches, not the page on screen — an
     * export of twenty-five rows is not what anybody asking for an export
     * means. Streamed, so a season of orders does not have to fit in memory.
     *
     * Bulk personal data leaving the platform, so it is recorded: who took it,
     * with which filter, and how many orders it held.
     */
    public function export(Request $request): StreamedResponse
    {
        $organization = $this->organization($request);
        $filters = $this->filters($request);

        // In the order the screen showed them, so a spreadsheet of "the
        // biggest first" is the biggest first.
        $query = $this->sorted($this->filtered($organization, $filters), $request)
            // The rate, for an order from before orders kept their own tax
            // lines: its one tax is named from the row it points at.
            ->with(['event:id,title,timezone', 'taxRate'])
            ->withCount('tickets')
            ->withSum(['refunds as refunded_amount' => fn ($q) => $q->where('status', 'succeeded')], 'amount');

        $this->auditor->record(
            'orders.exported',
            $organization,
            $request->user(),
            $organization->id,
            // Counted on the bare filter: Postgres refuses a count over a query
            // that still carries its ORDER BY.
            metadata: ['filters' => array_filter($filters), 'count' => $this->filtered($organization, $filters)->count()],
        );

        $rows = (function () use ($query) {
            foreach ($query->lazy(500) as $order) {
                $zone = $order->event?->timezone ?? 'UTC';

                yield [
                    $order->reference,
                    // In the event's own zone, because that is the evening the
                    // accountant is reconciling — with the zone beside it, so
                    // two cities in one file cannot be read as one clock.
                    $order->paid_at?->copy()->setTimezone($zone)->format('Y-m-d H:i'),
                    $zone,
                    Csv::text($order->event?->title),
                    Csv::text($order->buyer_name),
                    Csv::text($order->buyer_email),
                    $order->status,
                    (int) $order->tickets_count,
                    $order->currency,
                    Csv::money($order->subtotal_amount),
                    Csv::money($order->discount_amount),
                    Csv::money($order->tax_amount),
                    Csv::money($order->service_charge_amount),
                    Csv::money($order->total_amount),
                    Csv::money((int) $order->refunded_amount),
                    // What the organizer is paid for this order: the ticket
                    // money, before tax and before the buyer's service charge.
                    Csv::money($order->net_revenue_amount),
                    // New columns go after the old ones, so a spreadsheet or
                    // an import built on the columns above still lines up.
                    //
                    // The part of Service charge that is tax. Inside that
                    // figure, not beside it: the sum of the columns above is
                    // unchanged.
                    Csv::money((int) $order->service_charge_tax_amount),
                    // Each tax with its rate, as the receipt shows it — GST
                    // and QST are filed separately, and one Tax figure cannot
                    // be taken apart again.
                    Csv::text(self::taxLines($order)),
                    // Who sold the tickets in law, and so who files their tax.
                    (SellerOfRecord::tryFrom((string) $order->seller_of_record) ?? SellerOfRecord::Organizer)->value,
                ];
            }
        })();

        return Csv::download(
            'myfiesta-orders-'.now()->format('Y-m-d').'.csv',
            ['Reference', 'Paid at', 'Time zone', 'Event', 'Buyer', 'Email', 'Status', 'Tickets', 'Currency', 'Subtotal', 'Discount', 'Tax', 'Service charge', 'Total paid', 'Refunded', 'Owed to organizer', 'Tax in service charge', 'Tax lines', 'Seller of record'],
            $rows,
        );
    }

    /**
     * "GST 5%: 10.00; QST 9.975%: 19.95; GST 5% on the service charge: 0.80"
     *
     * In one cell, because how many taxes an order has depends on where the
     * event was, and a column per tax would move the columns about from one
     * export to the next. Amounts are written like every other money column,
     * without a symbol; the currency is in its own column.
     */
    private static function taxLines(Order $order): string
    {
        return collect(Receipt::taxes($order))
            ->map(fn (TaxLine $line) => $line->name.' '.$line->percent().'%'
                .($line->on === TaxLine::ON_SERVICE_CHARGE ? ' on the service charge' : '')
                .($line->inclusive ? ', included' : '')
                .': '.Csv::money($line->amount->amount))
            ->implode('; ');
    }

    /** @return array<string, mixed> */
    /** What an order list can be sorted by, and what each is in SQL. */
    private const SORTS = [
        'paid_at' => 'coalesce(paid_at, created_at)',
        'total' => 'total_amount',
        'buyer' => 'lower(buyer_name)',
        'reference' => 'reference',
    ];

    /**
     * Newest first unless the reader asked otherwise, with the id last so a
     * page break never falls between two orders of the same moment and shows
     * one twice.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private function sorted(Builder $query, Request $request): Builder
    {
        return Listing::sort($query, $request, self::SORTS, ['paid_at', 'desc'], [['created_at', 'desc'], ['id', 'asc']]);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            // One value or several: a single select and a multi-select both
            // land here, read by Listing::many below.
            'event_id' => ['nullable'],
            'status' => ['nullable'],
            // What the buyer paid, in the smallest unit of the currency, both
            // ends inclusive. "Over $500" is how a finance person looks for
            // the orders worth a second look.
            'min_total' => ['nullable', 'integer', 'min:0'],
            'max_total' => ['nullable', 'integer', 'min:0', Rule::when($request->filled('min_total'), ['gte:min_total'])],
            // When it happened, as whole days. An organizer asking about
            // Friday is not asking about 00:00:00Z, and both ends are
            // inclusive because that is what a person means by "to the 14th".
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            // Whose days those are. The console and the phone count "Today"
            // on the reader's own calendar, so they say which calendar that
            // is. Old names too (America/Montreal, Etc/UTC), because that is
            // what some browsers still report.
            'timezone' => ['nullable', 'timezone:all_with_bc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]) + [
            'event_ids' => Listing::ids($request, 'event_id', 200),
            'statuses' => Listing::many($request, 'status', ['paid', 'partially_refunded', 'refunded', 'pending', 'failed', 'cancelled']),
            // The rows somebody ticked, for exporting only those.
            'ids' => Listing::ids($request, 'ids'),
        ];
    }

    /**
     * The orders a filter matches, before paging or presenting.
     *
     * One definition for the screen and the export, so a spreadsheet can never
     * hold a different set of orders from the list it was exported from.
     *
     * @return Builder<Order>
     */
    private function filtered(Organization $organization, array $filters): Builder
    {
        return Order::query()
            ->where('organization_id', $organization->id)
            // Abandoned baskets are not orders anybody wants to read. Pending
            // is present because a payment stuck confirming is exactly the
            // thing somebody rings about.
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded', 'pending'])
            ->when(
                $filters['event_ids'],
                fn ($q, $ids) => $q->whereIn('event_id', $ids),
            )
            ->when(
                $filters['statuses'],
                fn ($q, $statuses) => $q->whereIn('status', $statuses),
            )
            ->when(
                $filters['ids'],
                fn ($q, $ids) => $q->whereIn('id', $ids),
            )
            ->when(
                isset($filters['min_total']),
                fn ($q) => $q->where('total_amount', '>=', (int) $filters['min_total']),
            )
            ->when(
                isset($filters['max_total']),
                fn ($q) => $q->where('total_amount', '<=', (int) $filters['max_total']),
            )
            /*
             * Dated by when the money arrived, falling back to when the order
             * was placed.
             *
             * Not paid_at alone: a pending order has none, and filtering it
             * out of a date range is how an organizer misses the one payment
             * stuck confirming — which is the order they were looking for.
             *
             * Each end is a whole day in the zone the reader named, turned
             * back into Greenwich because that is how the rows are written.
             * Read as Greenwich days instead, "Today" for a Toronto organizer
             * ended in the early evening, and in Lagos it began at one in the
             * morning. No zone means Greenwich, as it always did.
             */
            ->when(
                $filters['from'] ?? null,
                fn ($q, $from) => $q->whereRaw('coalesce(paid_at, created_at) >= ?', [
                    Carbon::parse($from, $filters['timezone'] ?? 'UTC')->startOfDay()->utc(),
                ]),
            )
            ->when(
                $filters['to'] ?? null,
                fn ($q, $to) => $q->whereRaw('coalesce(paid_at, created_at) <= ?', [
                    Carbon::parse($to, $filters['timezone'] ?? 'UTC')->endOfDay()->utc(),
                ]),
            )
            ->when($filters['q'] ?? null, function ($q, $term) {
                // Three ways in, because support is handed whichever one the
                // caller has: the reference off the confirmation, a name, or
                // the address the tickets went to. Case-insensitive on all
                // three — a reference read aloud arrives in lower case.
                $like = '%'.str_replace('%', '\%', mb_strtolower($term)).'%';

                $q->where(function ($inner) use ($like) {
                    $inner->whereRaw('lower(reference) LIKE ?', [$like])
                        ->orWhereRaw('lower(buyer_name) LIKE ?', [$like])
                        ->orWhereRaw('lower(buyer_email) LIKE ?', [$like]);
                });
            });
    }

    /**
     * What this page of orders comes to.
     *
     * Deliberately the page rather than the whole filtered set, and labelled
     * that way in the console: a figure that silently means something other
     * than the rows under it is worse than no figure.
     *
     * Null where the page spans currencies. An organization running Toronto
     * and Lagos nights has two sets of money, and adding them produces a
     * number that is true of nothing.
     *
     * @param  Collection<int, Order>  $orders
     * @return array<string, mixed>|null
     */
    private function summary($orders): ?array
    {
        $currencies = $orders->pluck('currency')->unique();

        if ($currencies->count() !== 1) {
            return null;
        }

        $currency = (string) $currencies->first();
        $gross = (int) $orders->sum('total_amount');
        $refunded = (int) $orders->sum(fn (Order $order) => (int) $order->refunded_amount);

        return [
            'gross' => ['amount' => $gross, 'currency' => $currency],
            'refunded' => ['amount' => $refunded, 'currency' => $currency],
            'net' => ['amount' => $gross - $refunded, 'currency' => $currency],
        ];
    }

    /**
     * The organization this request is about, and whether this person may
     * read its money at all.
     *
     * Refused outright rather than served an emptier version: an order list
     * with the totals stripped out is a list of names and addresses, which is
     * a different disclosure rather than a smaller one.
     */
    private function organization(Request $request): Organization
    {
        $memberships = $request->user()->organizations()->get();

        $asked = $request->header('X-Organization');

        $organization = $asked
            ? $memberships->firstWhere('id', $asked)
            : $memberships->first();

        abort_unless($organization !== null, 403, 'No organization.');

        abort_unless(
            $request->user()->hasPermissionIn($organization->id, Permission::MoneyView),
            403,
            'You cannot see the money for this organization.',
        );

        return $organization;
    }
}
