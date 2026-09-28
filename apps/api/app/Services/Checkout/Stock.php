<?php

namespace App\Services\Checkout;

use App\Models\AddOn;
use App\Models\Code;
use App\Models\Order;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What is left to sell, counted one way by everybody who has to ask.
 *
 * Checkout asks before it takes a hold. Fulfilment asks again when a payment
 * arrives for an order whose hold has run out — a Paystack transfer that
 * confirms an hour later, a Stripe webhook that was retried — because by then
 * the places it held may have been sold to somebody else. If those two counted
 * differently, the second question would be answered by a different rule from
 * the first, and the answer that decides whether a stranger's money buys a
 * ticket that does not exist would be the less tested one.
 *
 * Both count under a row lock on the thing being sold, taken here, so two
 * buyers counting together cannot both be told the last place is there.
 *
 * The lock does not stop everything, though. An order paying on time, inside
 * its hold, never counts — its places were counted when the hold was taken —
 * and so never takes the lock: it becomes paid and gives its hold up in one
 * commit, whenever it likes. So what is sold and what is held are read
 * together, in one statement (taken()). Read in two, that commit could land
 * between them and the order be in neither: not yet sold when the first
 * looked, no longer held when the second did — and its place sold again.
 */
class Stock
{
    /**
     * Tickets that hold a place: issued and still admitting somebody. A ticket
     * handed back for resale is not among them, so its place is back on sale.
     */
    public const LIVE_TICKETS = ['valid', 'checked_in'];

    /**
     * Places left of a ticket type, or null when it has no limit.
     *
     * Issued tickets that still admit somebody, plus holds that have not
     * expired. A ticket handed back for resale is not live, so its place is
     * back on sale; a live hold is stock somebody is in the middle of buying.
     *
     * $besides leaves one order's own holds out of the count. That order is
     * the one asking, and the places it holds are the ones it wants.
     */
    public function ticketsLeft(TicketType $type, ?Order $besides = null): ?int
    {
        // Unlimited takes no lock. An on-sale for a free-for-all tier should
        // not queue every buyer behind one row for nothing.
        if ($type->quantity_available === null) {
            return null;
        }

        $locked = TicketType::query()->whereKey($type->id)->lockForUpdate()->first();

        // Removed while it was being asked about: none of it is for sale.
        if ($locked === null) {
            return 0;
        }

        if ($locked->quantity_available === null) {
            return null;
        }

        $issued = DB::table('tickets')
            ->selectRaw('count(*)')
            ->where('ticket_type_id', $locked->id)
            ->whereIn('status', self::LIVE_TICKETS);

        return $locked->quantity_available - $this->taken($issued, 'ticket_type_id', $locked->id, $besides);
    }

    /**
     * Places of a ticket type that are sold or held right now, for showing.
     *
     * The same count ticketsLeft() takes — live tickets plus live holds, read
     * in one statement — without the lock, because a badge on a page decides
     * nothing: checkout counts again, under the lock, before it takes a hold.
     * Read from the row when it was loaded through withTaken(), so a list of
     * forty events asks once rather than forty times.
     */
    public function ticketsTaken(TicketType $type): int
    {
        if (array_key_exists('stock_taken', $type->getAttributes())) {
            return (int) $type->getAttribute('stock_taken');
        }

        $issued = DB::table('tickets')
            ->selectRaw('count(*)')
            ->where('ticket_type_id', $type->id)
            ->whereIn('status', self::LIVE_TICKETS);

        return $this->taken($issued, 'ticket_type_id', $type->id, null);
    }

    /**
     * Load ticket types with what is taken of each already counted.
     *
     * For an eager load — `with(['ticketTypes' => $stock->withTaken(...)])` —
     * so every tier on a page of events carries its count in the query that
     * loads it. One expression, sold plus held, which is one snapshot for the
     * reason taken() gives.
     */
    public function withTaken(EloquentBuilder|Relation $query): EloquentBuilder|Relation
    {
        $builder = $query instanceof Relation ? $query->getQuery() : $query;

        if ($builder->getQuery()->columns === null) {
            $builder->select('ticket_types.*');
        }

        $builder->selectRaw('('.self::takenSql('ticket_types.id').') as stock_taken', [now()]);

        return $query;
    }

    /**
     * Sold plus held for the ticket type whose id column is named, as SQL.
     *
     * The one place the rule is written as a statement, for queries that
     * have to count a whole page of tiers inside themselves (withTaken, and
     * Availability's filter). One binding: the moment a hold is live until.
     */
    public static function takenSql(string $idColumn): string
    {
        $live = "'".implode("', '", self::LIVE_TICKETS)."'";

        return "(select count(*) from tickets where tickets.ticket_type_id = {$idColumn} and tickets.status in ({$live}))"
            .' + (select coalesce(sum(inventory_holds.quantity), 0) from inventory_holds'
            ." where inventory_holds.ticket_type_id = {$idColumn} and inventory_holds.expires_at > ?)";
    }

    /**
     * The same, for the thing that is not a ticket.
     *
     * Counted from what has been paid for rather than from what was minted:
     * an add-on mints nothing, so twenty tables have no rows of their own to
     * count and the orders that bought them are the count.
     */
    public function addOnsLeft(AddOn $addOn, ?Order $besides = null): ?int
    {
        if ($addOn->quantity_available === null) {
            return null;
        }

        $locked = AddOn::query()->whereKey($addOn->id)->lockForUpdate()->first();

        if ($locked === null) {
            return 0;
        }

        if ($locked->quantity_available === null) {
            return null;
        }

        $sold = DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->selectRaw('coalesce(sum(order_lines.quantity), 0)')
            ->where('order_lines.add_on_id', $locked->id)
            ->whereIn('orders.status', Code::PAID_STATUSES);

        return $locked->quantity_available - $this->taken($sold, 'add_on_id', $locked->id, $besides);
    }

    /**
     * Sold plus held, in one statement.
     *
     * One statement is one snapshot of the database. An order that pays on
     * time moves from held to sold in a single commit, and a single snapshot
     * sees it on one side of that commit or the other — counted once either
     * way, never zero times (see the class comment).
     */
    private function taken(Builder $sold, string $column, string $id, ?Order $besides): int
    {
        $row = DB::query()
            ->selectSub($sold, 'sold')
            ->selectSub($this->held($column, $id, $besides), 'held')
            ->first();

        return (int) $row->sold + (int) $row->held;
    }

    /**
     * Live holds on one thing, other than the asking order's own.
     *
     * A hold with no order at all counts as somebody else's. Those were taken
     * before holds said whose they were, and nothing can say otherwise.
     */
    private function held(string $column, string $id, ?Order $besides): Builder
    {
        return DB::table('inventory_holds')
            ->selectRaw('coalesce(sum(quantity), 0)')
            ->where($column, $id)
            ->where('expires_at', '>', now())
            ->when($besides !== null, fn ($query) => $query->where(fn ($query) => $query
                ->whereNull('order_id')
                ->orWhere('order_id', '!=', $besides->id)));
    }
}
