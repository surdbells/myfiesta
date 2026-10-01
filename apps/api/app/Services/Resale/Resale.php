<?php

namespace App\Services\Resale;

use App\Models\Event;
use App\Models\Order;
use App\Models\ResaleListing;
use App\Models\Ticket;
use App\Services\Audit\Auditor;
use App\Services\Organizations\Suspension;
use App\Services\Payments\PayLater;
use App\Services\Refunds\RefundRefused;
use App\Services\Refunds\RefundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Returning a ticket, and paying back whoever returned it.
 *
 * Three moves. Listing a ticket takes it out of its holder's hands — it stops
 * working at the door that moment, which is what makes the place safe to sell
 * again. The place then goes back into the event's ordinary stock, because
 * availability counts live tickets and a listed one is not live. And when
 * somebody buys that place through the ordinary checkout, the oldest listing
 * for that tier is matched to it and its seller is refunded what they paid.
 *
 * The buyer is never told whose ticket they got, because they did not get
 * anybody's: they bought a place from the organizer at the organizer's price,
 * and a stranger got their money back. Nobody is paying a stranger, nobody
 * sets a price, and there is nothing for a tout to list.
 */
class Resale
{
    public function __construct(
        private readonly RefundService $refunds,
        private readonly Auditor $auditor,
    ) {}

    /**
     * Whether this ticket can be handed back at all.
     *
     * Said as a sentence rather than a boolean, because every one of these is
     * something the holder needs to read on the screen where they tried.
     */
    public function refusal(Ticket $ticket, Event $event): ?string
    {
        if (! $event->resale_enabled) {
            return 'This organizer is not taking tickets back for this event.';
        }

        // A listed ticket stops working at the door the moment it is listed,
        // and nobody can buy the place while the organizer is suspended — so
        // listing one now would leave the holder with neither the ticket nor
        // the money. Keeping it is the better deal until sales resume.
        if (Suspension::inForce($event->organization_id)) {
            return 'This organizer is not selling tickets at the moment, so tickets cannot be handed back. Your ticket still works at the door.';
        }

        if ($ticket->status === 'listed') {
            return 'This one is already waiting for somebody to take it.';
        }

        if ($ticket->status !== 'valid') {
            return $ticket->status === 'checked_in'
                ? 'This ticket has already been used to get in.'
                : 'This ticket cannot be given back.';
        }

        // A table with some of its people inside is still `valid`. Given
        // back, its places would go on sale whole — the ones already in
        // sold a second time — and the holder repaid for all of them.
        if ($ticket->admitted_count > 0) {
            return 'Somebody has already come in on this ticket, so it cannot be given back.';
        }

        if ($event->starts_at === null || $event->starts_at->isPast()) {
            return 'This event has already happened.';
        }

        if ($event->starts_at->diffInHours(now()) > -$event->resale_closes_hours) {
            $hours = $event->resale_closes_hours;

            return "Tickets can be given back up to {$hours} hours before the doors, and it is later than that now.";
        }

        // Nothing was paid, so there is nothing to give back. A comp is the
        // organizer's to take back, not the holder's to return.
        if ($this->paidFor($ticket) <= 0) {
            return 'This ticket was free, so there is nothing to return.';
        }

        // Paid with Klarna or Affirm, whose window for taking money back
        // shuts before the night: a resale could be refused paying them back
        // after the ticket had stopped working (payBack).
        return app(PayLater::class)->resaleRefusal($ticket->order, $event);
    }

    /**
     * Hand it back.
     *
     * @throws ResaleRefused
     */
    public function list(Ticket $ticket): ResaleListing
    {
        $event = $ticket->event;
        $refusal = $this->refusal($ticket, $event);

        if ($refusal !== null) {
            throw new ResaleRefused($refusal);
        }

        return DB::transaction(function () use ($ticket, $event) {
            // Locked, so two taps on a slow connection cannot list it twice —
            // and the partial unique index would refuse the second anyway.
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->first();

            // Asked again under the lock: the door may have let somebody in
            // on it since the page was drawn.
            if ($locked->status !== 'valid' || $locked->admitted_count > 0) {
                throw new ResaleRefused('This ticket cannot be given back.');
            }

            $locked->update(['status' => 'listed']);

            $listing = ResaleListing::create([
                'ticket_id' => $locked->id,
                'event_id' => $locked->event_id,
                'ticket_type_id' => $locked->ticket_type_id,
                'order_id' => $locked->order_id,
                'price_amount' => $this->paidFor($locked),
                'currency' => $event->currency,
                'status' => 'listed',
                'listed_at' => now(),
            ]);

            $this->auditor->record('resale.listed', $locked, null, $event->organization_id, metadata: [
                'event_id' => $event->id,
                'listing_id' => $listing->id,
            ]);

            return $listing;
        });
    }

    /** Changed their mind, before anybody took the place. */
    public function cancel(ResaleListing $listing): void
    {
        DB::transaction(function () use ($listing) {
            $claimed = ResaleListing::query()
                ->whereKey($listing->id)
                ->where('status', 'listed')
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            if ($claimed === 0) {
                throw new ResaleRefused('That place has already gone to somebody else.');
            }

            $listing->ticket()->update(['status' => 'valid']);
        });
    }

    /**
     * Somebody bought places; pay back whoever gave them up.
     *
     * Called once an order is paid, with its lines. Oldest listing first, so
     * returning a ticket early is worth something — a queue anybody can see
     * the rules of, rather than a lottery.
     *
     * Never throws. A refund that fails is a person to chase, not a reason to
     * fail the order of the buyer who has just paid.
     */
    public function matchPaidOrder(Order $order): void
    {
        try {
            foreach ($order->lines()->whereNotNull('ticket_type_id')->get() as $line) {
                for ($i = 0; $i < (int) $line->quantity; $i++) {
                    $listing = $this->claimOldest($line->ticket_type_id, $order);

                    if ($listing === null) {
                        break;
                    }

                    $this->payBack($listing, $order);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Take the oldest waiting listing off the queue, for this order.
     *
     * Claimed with a conditional update: two buyers paying at the same moment
     * must not both be matched to one returned ticket.
     */
    private function claimOldest(string $ticketTypeId, Order $order): ?ResaleListing
    {
        return DB::transaction(function () use ($ticketTypeId, $order) {
            $listing = ResaleListing::query()
                ->where('ticket_type_id', $ticketTypeId)
                ->where('status', 'listed')
                // Not their own: somebody who returns a ticket and then buys
                // another place has not resold anything.
                ->where('order_id', '!=', $order->id)
                ->orderBy('listed_at')
                ->lockForUpdate()
                ->first();

            if ($listing === null) {
                return null;
            }

            $listing->update([
                'status' => 'sold',
                'sold_at' => now(),
                'sold_to_order_id' => $order->id,
            ]);

            return $listing;
        });
    }

    /**
     * The seller's money, through the ordinary refund machinery.
     *
     * Everything they paid for that ticket, including the booking fee: they
     * are not getting a service, they are getting their evening back. The
     * platform earns its fee from the person who took the place instead.
     *
     * The refund marks the ticket refunded itself. Doing it here first would
     * leave the refund machinery with nothing it considers refundable, and a
     * seller owed money that was never sent.
     */
    private function payBack(ResaleListing $listing, Order $order): void
    {
        try {
            $refund = $this->refunds->refund(
                $listing->order,
                [$listing->ticket_id],
                null,
                'Ticket given back and resold',
            );

            $listing->update(['refund_id' => $refund->id]);
        } catch (RefundRefused $e) {
            // The place has still been sold and the seller is still owed. Left
            // loud rather than silently swallowed: somebody has to send this
            // money by hand.
            Log::alert('A resold ticket could not be refunded automatically.', [
                'listing' => $listing->id,
                'seller_order' => $listing->order_id,
                'buyer_order' => $order->reference,
                'why' => $e->getMessage(),
            ]);
        }
    }

    /**
     * What this ticket actually cost, as the refund machinery would work it out.
     *
     * Its line's unit price less its share of that line's discount. A ticket
     * bought with a code is worth what was paid for it, not its list price.
     */
    private function paidFor(Ticket $ticket): int
    {
        $line = $ticket->order?->lines()
            ->where('ticket_type_id', $ticket->ticket_type_id)
            ->first();

        if ($line === null || (int) $line->quantity === 0) {
            return 0;
        }

        return max(0, (int) $line->unit_price_amount - intdiv((int) $line->discount_amount, (int) $line->quantity));
    }
}
