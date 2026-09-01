<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'event_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:paid,partially_refunded,refunded,pending,failed,cancelled'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = Order::query()
            ->where('organization_id', $organization->id)
            // Abandoned baskets are not orders anybody wants to read. Pending
            // is present because a payment stuck confirming is exactly the
            // thing somebody rings about.
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded', 'pending'])
            ->when(
                $filters['event_id'] ?? null,
                fn ($q, $id) => $q->where('event_id', $id),
            )
            ->when(
                $filters['status'] ?? null,
                fn ($q, $status) => $q->where('status', $status),
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
            })
            ->with('event:id,title')
            ->withCount('tickets')
            ->withSum(['refunds as refunded_amount' => fn ($q) => $q->where('status', 'succeeded')], 'amount')
            // Newest first, and the unpaid ones have no paid_at to sort by.
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

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
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
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
