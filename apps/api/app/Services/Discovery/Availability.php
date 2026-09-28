<?php

namespace App\Services\Discovery;

use App\Models\Event;
use App\Models\TicketType;
use App\Services\Checkout\Stock;
use App\Services\Settings\PlatformSettings;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Whether there is anything left to buy, said the way a buyer reads it:
 * available, almost sold out, sold out, or unlimited.
 *
 * The count underneath is the one checkout takes (Stock): tickets that still
 * admit somebody, plus holds that have not run out. A badge counted any other
 * way would say "Only 2 left" to somebody checkout then turns away, or "Sold
 * out" while three baskets are about to expire and put their places back.
 *
 * A tier:
 *  - sold out when the organizer marked it so or every place is taken;
 *  - closed when the organizer closed it or its sales ended with places
 *    still in it: it cannot be bought, and it did not sell out;
 *  - unlimited when it has no capacity;
 *  - almost sold out once something has sold and what is left is at or under
 *    the admin's threshold (Scarcity);
 *  - available otherwise.
 *
 * An event, over the tiers a stranger is shown (on sale or sold out; hidden
 * presale tiers are nobody's business):
 *  - sold out when every one of them is. The waitlist takes names then, for
 *    tickets a refund or a new release could bring back;
 *  - closed when none of them can still sell but not because they all went:
 *    online sales stopped at the door, or there is nothing to show. Called
 *    sold out, a night that sold two tickets said "Sold out", and the front
 *    page's sold-out shelf filled with nights that had simply stopped
 *    selling. What it costs is the night whose early bird ended with places
 *    left and whose general tier then sold out: it reads "Sales closed",
 *    which is true, rather than "Sold out", which nearly is;
 *  - almost sold out when the cheapest tier still selling is — the one the
 *    card's "From" price names — or when what is left across every tier is
 *    under the threshold of their combined capacity;
 *  - unlimited when every tier still selling has no capacity;
 *  - available otherwise.
 *
 * Written twice, deliberately: here in PHP for the badges, which read the
 * tiers already loaded for the page, and as one SQL expression (stateSql) for
 * the filters and the home page's sections, which have to choose events
 * before any are loaded. AvailabilityTest runs both over the same cases.
 */
class Availability
{
    public const AVAILABLE = 'available';

    public const ALMOST_SOLD_OUT = 'almost_sold_out';

    public const SOLD_OUT = 'sold_out';

    public const UNLIMITED = 'unlimited';

    /** Nothing can be bought, and not because it all went (see above). */
    public const CLOSED = 'closed';

    public const STATES = [self::AVAILABLE, self::ALMOST_SOLD_OUT, self::SOLD_OUT, self::UNLIMITED, self::CLOSED];

    /** The tiers a stranger is shown. Hidden ones open with a code; closed ones are gone. */
    public const PUBLIC_STATUSES = ['on_sale', 'sold_out'];

    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly Stock $stock,
    ) {}

    /**
     * The thresholds, read once per request.
     *
     * Kept on the request rather than on this object: a page of forty cards
     * builds forty resources, and each asking the settings store again is
     * forty round trips for one answer. A new request reads them afresh, so
     * a change in the admin shows on the next page anybody opens.
     */
    public function scarcity(): Scarcity
    {
        $request = app('request');
        $known = $request->attributes->get(self::class);

        if ($known instanceof Scarcity) {
            return $known;
        }

        $scarcity = Scarcity::from($this->settings->scarcity());
        $request->attributes->set(self::class, $scarcity);

        return $scarcity;
    }

    /**
     * The eager load that makes a list cost one query for all its tiers:
     * `->with(['ticketTypes' => $availability->tiers()])`.
     */
    public function tiers(): Closure
    {
        return fn (Builder|Relation $query) => $this->stock->withTaken($query);
    }

    public function tier(TicketType $type): TierAvailability
    {
        $capacity = $type->quantity_available;

        if ($type->status === 'sold_out') {
            return new TierAvailability(self::SOLD_OUT, 0, $capacity);
        }

        $taken = $capacity === null ? 0 : $this->stock->ticketsTaken($type);
        $remaining = $capacity === null ? null : max(0, $capacity - $taken);

        // Every place gone is sold out even once its sales have ended: a night
        // that sold its last ticket before the doors opened stays sold out.
        if ($remaining === 0) {
            return new TierAvailability(self::SOLD_OUT, 0, $capacity);
        }

        if ($type->status === 'closed' || $type->salesEnded()) {
            return new TierAvailability(self::CLOSED, null, $capacity);
        }

        if ($remaining === null) {
            return new TierAvailability(self::UNLIMITED, null, null);
        }

        $near = $taken > 0 && $remaining <= $this->scarcity()->near($capacity);

        return new TierAvailability($near ? self::ALMOST_SOLD_OUT : self::AVAILABLE, $remaining, $capacity);
    }

    public function event(Event $event): EventAvailability
    {
        if (! $event->relationLoaded('ticketTypes')) {
            $event->load(['ticketTypes' => $this->tiers()]);
        }

        /** @var Collection<int, TicketType> $public */
        $public = $event->ticketTypes->whereIn('status', self::PUBLIC_STATUSES)->values();

        $open = [];
        $gone = 0;
        $capacity = 0;
        $remaining = 0;
        $unlimited = false;

        foreach ($public as $type) {
            $tier = $this->tier($type);
            $capacity += $tier->capacity ?? 0;

            if ($tier->soldOut()) {
                $gone++;

                continue;
            }

            if ($tier->state === self::CLOSED) {
                continue;
            }

            $open[] = [$type, $tier];

            if ($tier->remaining === null) {
                $unlimited = true;
            } else {
                $remaining += $tier->remaining;
            }
        }

        if ($open === []) {
            $everyOneWent = $public->isNotEmpty() && $gone === $public->count();

            return new EventAvailability($everyOneWent ? self::SOLD_OUT : self::CLOSED, 0, null);
        }

        // The cheapest way in, the organizer's order breaking a tie.
        usort($open, fn (array $a, array $b) => [$a[0]->price_amount, $a[0]->sort_order, $a[0]->id]
            <=> [$b[0]->price_amount, $b[0]->sort_order, $b[0]->id]);

        [$cheapest, $cheapestTier] = $open[0];
        $left = $unlimited ? null : $remaining;

        if ($cheapestTier->state === self::ALMOST_SOLD_OUT) {
            return new EventAvailability(self::ALMOST_SOLD_OUT, $left, $cheapest);
        }

        if (! $unlimited && $remaining <= $this->scarcity()->near($capacity) && $remaining < $capacity) {
            return new EventAvailability(self::ALMOST_SOLD_OUT, $left, $cheapest);
        }

        $allUnlimited = collect($open)->every(fn (array $pair) => $pair[1]->remaining === null);

        return new EventAvailability($allUnlimited ? self::UNLIMITED : self::AVAILABLE, $left, $cheapest);
    }

    /**
     * Each tier a buyer can see on this event, and any in their basket, as the
     * quote reports them.
     *
     * The ticket page shows badges from when it loaded; somebody choosing for
     * five minutes can be choosing a tier that sold out while they did. The
     * quote is asked on every change, so it carries the answer, and the page
     * takes a tier that has gone out of the basket before checkout refuses it.
     * A tier the organizer closed, or whose sales ended, is reported closed,
     * as the event page reports it: not sold out, which it is not.
     *
     * A hidden tier in the basket is reported only when this request's code
     * opens it. Checkout answers a hidden tier without its code exactly as it
     * answers an id that does not exist (Pricer); reporting it here anyway
     * told anybody holding the id that the presale exists, and how it sells.
     *
     * @param  list<string>  $inBasket  ticket type ids
     * @param  list<string>  $unlocked  ticket type ids the request's code opens
     * @return list<array{ticket_type_id: string, state: string, left: int|null}>
     */
    public function forQuote(Event $event, array $inBasket, array $unlocked = []): array
    {
        $types = $event->ticketTypes()
            ->where(fn (Builder $query) => $query
                ->whereIn('status', self::PUBLIC_STATUSES)
                ->orWhere(fn (Builder $basket) => $basket
                    ->whereIn('id', $inBasket)
                    ->where(fn (Builder $shown) => $shown
                        ->where('status', '!=', 'hidden')
                        ->orWhereIn('id', $unlocked))));

        // Counted in the same query that loads them.
        $this->stock->withTaken($types);

        return $types->get()
            ->map(fn (TicketType $type) => [
                'ticket_type_id' => $type->id,
                ...$this->tier($type)->toArray($this->scarcity()),
            ])
            ->values()
            ->all();
    }

    /**
     * Narrow a query of events to those in one of these states.
     *
     * @param  Builder<Event>  $events
     * @param  list<string>  $states
     * @return Builder<Event>
     */
    public function whereState(Builder $events, array $states): Builder
    {
        $states = array_values(array_intersect($states, self::STATES));

        if ($states === []) {
            return $events;
        }

        [$sql, $bindings] = $this->stateSql();
        $marks = implode(', ', array_fill(0, count($states), '?'));

        return $events->whereRaw("({$sql}) in ({$marks})", [...$bindings, ...$states]);
    }

    /**
     * The event's state as one SQL expression over `events`, with its bindings.
     *
     * The same rules as event() and tier(), step for step: the innermost
     * select counts each public tier the way Stock does, the next decides
     * whether it can still sell and whether every place in it has gone, the
     * next finds the cheapest still selling and whether each is nearly gone,
     * and the outer one says what that makes the event. Integer division
     * throughout, as in Scarcity.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function stateSql(): array
    {
        $scarcity = $this->scarcity();
        $percent = $scarcity->percent;
        $floor = $scarcity->floor;
        $near = fn (string $capacity) => "greatest({$floor}, ({$capacity} * {$percent} + 99) / 100)";
        $public = "'".implode("', '", self::PUBLIC_STATUSES)."'";
        $taken = Stock::takenSql('tt.id');

        $sql = <<<SQL
            select case
                when coalesce(bool_or(s.open), false) = false then
                    case when coalesce(bool_and(s.gone), false) then 'sold_out' else 'closed' end
                when coalesce(bool_or(s.main and s.near), false) then 'almost_sold_out'
                when not bool_or(s.open and s.cap is null)
                    and sum(s.rem) <= {$near('sum(coalesce(s.cap, 0))')}
                    and sum(s.rem) < sum(coalesce(s.cap, 0)) then 'almost_sold_out'
                when bool_and(not s.open or s.cap is null) then 'unlimited'
                else 'available'
            end
            from (
                select o.*,
                    o.open and row_number() over (order by o.open desc, o.price_amount, o.sort_order, o.id) = 1 as main,
                    o.open and o.cap is not null and o.taken > 0 and o.cap - o.taken <= {$near('o.cap')} as near,
                    case when o.open and o.cap is not null then o.cap - o.taken else 0 end as rem
                from (
                    select x.*,
                        x.status = 'on_sale'
                            and (x.sales_end_at is null or x.sales_end_at > ?)
                            and (x.cap is null or x.taken < x.cap) as open,
                        x.status = 'sold_out' or (x.cap is not null and x.taken >= x.cap) as gone
                    from (
                        select tt.id, tt.status, tt.price_amount, tt.sort_order, tt.sales_end_at,
                            tt.quantity_available as cap, {$taken} as taken
                        from ticket_types tt
                        where tt.event_id = events.id
                            and tt.deleted_at is null
                            and tt.status in ({$public})
                    ) x
                ) o
            ) s
            SQL;

        return [$sql, [now(), now()]];
    }
}
