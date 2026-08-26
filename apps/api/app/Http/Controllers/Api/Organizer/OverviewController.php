<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What an organizer opens the console to find out.
 *
 * There was no such screen. The console opened on a list of events, which
 * answers "what have I got on" and none of the questions somebody actually
 * signs in with: am I owed anything, is the next one selling, and is there
 * something I have forgotten to do.
 *
 * Deliberately a single endpoint. A dashboard assembled from six requests is
 * six chances to render half a page, and the figures below have to agree with
 * each other — they are computed in one pass against one moment.
 */
class OverviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        if (! $organization) {
            return response()->json(['message' => 'No organization.'], 403);
        }

        // The same check the event policy makes, rather than a Gate ability —
        // permissions here are held per organization on the membership, and
        // `can()` against a model would look for a policy this has no model for.
        $maySeeMoney = $request->user()->hasPermissionIn($organization->id, Permission::MoneyView);

        return response()->json([
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'currency' => $this->currency($organization),
            // Money is a permission, not a screen. Marketing and door staff get
            // the same dashboard with the takings absent rather than a
            // different one.
            'money' => $maySeeMoney ? $this->money($organization) : null,
            'selling' => $this->selling($organization),
            'next_event' => $this->nextEvent($organization),
            'attention' => $this->attention($organization),
        ]);
    }

    /**
     * The organization this request is about.
     *
     * Taken from the header the console sends, and checked against membership
     * rather than trusted. Falling back to the first membership keeps a client
     * that has not sent one working instead of failing blank.
     */
    private function organization(Request $request): ?Organization
    {
        $memberships = $request->user()->organizations()->get();

        $asked = $request->header('X-Organization');

        if ($asked) {
            return $memberships->firstWhere('id', $asked);
        }

        return $memberships->first();
    }

    /**
     * The currency this organization sells in.
     *
     * Read from its events rather than assumed, and singular: an organization
     * running events in two currencies cannot have its takings added up, and
     * the honest answer there is to report the one it uses most rather than a
     * sum that means nothing.
     */
    private function currency(Organization $organization): string
    {
        return (string) (Event::query()
            ->where('organization_id', $organization->id)
            ->select('currency', DB::raw('count(*) as n'))
            ->groupBy('currency')
            ->orderByDesc('n')
            ->value('currency') ?? 'CAD');
    }

    /**
     * What the organizer is owed, and what has already gone out.
     *
     * The balance is the ledger's own sum — the same figure settlements are
     * paid from — rather than a total of orders. Those two disagreed for every
     * Canadian sale until the sale entry was corrected, and the ledger is the
     * one that decides what anybody is actually paid.
     *
     * @return array<string, mixed>
     */
    private function money(Organization $organization): array
    {
        $currency = $this->currency($organization);

        $entries = LedgerEntry::query()
            ->where('organization_id', $organization->id)
            ->where('currency', $currency);

        $since = fn (int $days) => Order::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'paid')
            ->where('paid_at', '>=', now()->subDays($days));

        // Money leaves this API as an amount and a currency together, never as
        // a bare number. The platform this replaces returned formatted strings
        // from some endpoints and integers from others, and the same value
        // meant different things at different layers.
        $money = fn (int $amount) => ['amount' => $amount, 'currency' => $currency];

        return [
            'balance' => $money((int) (clone $entries)->sum('amount')),
            'settled' => $money((int) abs((clone $entries)->where('type', 'settlement')->sum('amount'))),
            'sold_7d' => $money((int) $since(7)->sum('net_revenue_amount')),
            'sold_30d' => $money((int) $since(30)->sum('net_revenue_amount')),
            'orders_7d' => (int) $since(7)->count(),
        ];
    }

    /**
     * Tickets, across everything still to happen.
     *
     * @return array<string, int>
     */
    private function selling(Organization $organization): array
    {
        $upcoming = Event::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'published')
            ->where('starts_at', '>=', now());

        return [
            'upcoming_events' => (int) (clone $upcoming)->count(),
            'draft_events' => (int) Event::query()
                ->where('organization_id', $organization->id)
                ->where('status', 'draft')
                ->count(),
            'tickets_upcoming' => (int) DB::table('tickets')
                ->whereIn('event_id', (clone $upcoming)->select('id'))
                ->whereIn('status', ['valid', 'checked_in'])
                ->count(),
        ];
    }

    /**
     * The next event, with enough to know whether it is going well.
     *
     * @return array<string, mixed>|null
     */
    private function nextEvent(Organization $organization): ?array
    {
        $event = Event::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'published')
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->first();

        if (! $event) {
            return null;
        }

        $issued = (int) $event->tickets()->whereIn('status', ['valid', 'checked_in'])->count();

        // Null where any type is unlimited: a percentage against a capacity
        // that does not exist is a number somebody would plan against.
        $types = $event->ticketTypes()->get();
        $capacity = $types->contains(fn ($t) => $t->quantity_available === null)
            ? null
            : (int) $types->sum('quantity_available');

        return [
            'id' => $event->id,
            'title' => $event->title,
            'starts_at' => $event->starts_at,
            'timezone' => $event->timezone,
            'city' => $event->city,
            'tickets_issued' => $issued,
            'capacity' => $capacity,
        ];
    }

    /**
     * Things that need doing, each with the reason and a way to it.
     *
     * Only ever real conditions. A dashboard that always has something in this
     * list is a dashboard whose list nobody reads, so an organization with
     * nothing outstanding gets an empty array and a screen that says so.
     *
     * @return list<array<string, string>>
     */
    private function attention(Organization $organization): array
    {
        $items = [];

        // Published, on soon, and nothing on sale. The single worst state an
        // event can be in: it is listed, people can see it, and they cannot buy.
        $noTickets = Event::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'published')
            ->whereBetween('starts_at', [now(), now()->addDays(30)])
            ->whereDoesntHave('ticketTypes', fn ($q) => $q->where('status', 'on_sale'))
            ->get();

        foreach ($noTickets as $event) {
            $items[] = [
                'event_id' => $event->id,
                'title' => $event->title,
                'reason' => 'Published with nothing on sale',
                'detail' => 'People can see this event and cannot buy a ticket.',
                'severity' => 'danger',
            ];
        }

        // Drafts whose date is approaching. Not an error, but the thing an
        // organizer most often means to do and forgets.
        $stuckDrafts = Event::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'draft')
            ->whereBetween('starts_at', [now(), now()->addDays(21)])
            ->get();

        foreach ($stuckDrafts as $event) {
            $items[] = [
                'event_id' => $event->id,
                'title' => $event->title,
                'reason' => 'Still a draft',
                'detail' => 'It is not visible to anybody yet.',
                'severity' => 'warning',
            ];
        }

        return $items;
    }
}
