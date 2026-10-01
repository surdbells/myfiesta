<?php

namespace App\Http\Resources\Extensions;

use App\Models\Code;
use App\Models\Event;
use App\Models\Order;
use App\Models\ShareLink;
use App\Models\Ticket;
use App\Services\Checkout\Quote;
use App\Services\Settings\PlatformSettings;
use App\Services\Sharing\ShareLinks;
use App\Services\Sharing\ShareOffers;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Friend discounts: the offer a night makes, on its event page; the holder's
 * own link to share it, on the ticket; the discount a friend's link takes off,
 * on a quote; and the offer as its organizer set it, in the console.
 *
 * Null wherever a night has no offer, which reads as no offer and no link.
 */
class Share
{
    /**
     * Each primed ticket's link, by ticket id, as the contract's ShareLink or
     * null. Replaced on every primeTickets(), never added to (TicketExtras).
     *
     * @var array<string, array<string, mixed>|null>
     */
    private array $primed = [];

    public function __construct(
        private readonly ShareLinks $links,
        private readonly ShareOffers $offers,
        private readonly PlatformSettings $settings,
    ) {}

    /** @return array<string, mixed>|null */
    public function offerFor(Event $event, Request $request): ?array
    {
        $bps = $this->links->bpsFor($event);

        // The page is cached for everybody alike (the public-read header), so
        // it says only what the night offers; whether this visitor came by a
        // friend's link is for the page to know from its own address.
        return $bps === null ? null : ['discount_bps' => $bps];
    }

    /**
     * The links for a list of tickets, in a fixed number of queries whatever
     * its length: the nights with an offer, the orders, the links.
     *
     * A ticket its buyer still holds, from a paid order, has its buyer's link
     * made here if the queued listener has not made it yet — the tickets
     * page is often open before the queue has caught up. Anybody else holding
     * one (passed on to them) asks for theirs from the phone.
     *
     * @param  Collection<int, Ticket>  $tickets
     */
    public function primeTickets(Collection $tickets): void
    {
        $this->primed = $this->linksFor($tickets);
    }

    /** @return array<string, mixed>|null */
    public function linkFor(Ticket $ticket): ?array
    {
        if (array_key_exists($ticket->id, $this->primed)) {
            return $this->primed[$ticket->id];
        }

        return $this->linksFor(collect([$ticket]))[$ticket->id] ?? null;
    }

    /**
     * On a quote: the friend's discount, when a friend's link priced it.
     *
     * @return array<string, mixed>|null
     */
    public function forQuote(Quote $quote): ?array
    {
        if ($quote->code === null || ! $quote->code->isFriendDiscount()) {
            return null;
        }

        return [
            'discount_bps' => (int) $quote->code->discount_value,
            'amount' => ['amount' => $quote->discount->amount, 'currency' => $quote->discount->currency],
        ];
    }

    /**
     * The code a quote names as applied. Here rather than in the quote,
     * because the one a friend's link applies is hidden: it is shown as the
     * friend's discount, never as a code somebody typed and can remove.
     */
    public function codeShown(Quote $quote): ?string
    {
        if ($quote->code?->isFriendDiscount()) {
            return null;
        }

        return $quote->code?->code;
    }

    /**
     * In the console: the offer as the organizer set it, `discount_bps` null
     * while there is none (ShareOffers::forOrganizer).
     *
     * @return array<string, mixed>
     */
    public function offerForOrganizer(Event $event, Request $request): array
    {
        return $this->offers->forOrganizer($event, $this->settings->shareMaxBps());
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @return array<string, array<string, mixed>|null>
     */
    private function linksFor(Collection $tickets): array
    {
        $answers = $tickets->mapWithKeys(fn (Ticket $ticket) => [$ticket->id => null])->all();

        if ($tickets->isEmpty()) {
            return $answers;
        }

        /** @var \Illuminate\Database\Eloquent\Collection<string, Event> $events */
        $events = Event::query()
            ->whereIn('id', $tickets->pluck('event_id')->unique()->values())
            ->whereNotNull('share_discount_bps')
            ->with('organization:id,name')
            ->get(['id', 'slug', 'organization_id', 'starts_at', 'ends_at', 'share_discount_bps', 'share_max_rewards'])
            ->filter(fn (Event $event) => $this->links->bpsFor($event) !== null)
            ->keyBy('id');

        if ($events->isEmpty()) {
            return $answers;
        }

        $offered = $tickets->filter(fn (Ticket $ticket) => $events->has($ticket->event_id));

        /** @var \Illuminate\Database\Eloquent\Collection<string, Order> $orders */
        $orders = Order::query()
            ->whereIn('id', $offered->pluck('order_id')->filter()->unique()->values())
            ->get(['id', 'event_id', 'buyer_email', 'user_id', 'status'])
            ->keyBy('id');

        $holderOf = fn (Ticket $ticket) => Str::lower(trim((string) ($ticket->owner_email
            ?? $orders->get((string) $ticket->order_id)?->buyer_email)));

        // By address, and by account for somebody whose link was made under
        // the address they have now rather than the one on the ticket.
        $holders = $offered->pluck('owner_user_id')->filter()->unique()->values();

        $found = ShareLink::query()
            ->whereIn('event_id', $events->keys())
            ->where(fn ($query) => $query
                ->whereIn('owner_email', $offered->map($holderOf)->filter()->unique()->values())
                ->when($holders->isNotEmpty(), fn ($q) => $q->orWhereIn('user_id', $holders)))
            ->get();

        $links = $found->keyBy(fn (ShareLink $link) => $link->event_id.'|'.$link->owner_email);
        $byAccount = $found->whereNotNull('user_id')->keyBy(fn (ShareLink $link) => $link->event_id.'|'.$link->user_id);

        foreach ($offered as $ticket) {
            // A ticket waiting at the door or given back says nothing about sharing.
            if (! in_array($ticket->status, ShareLinks::COMING, true)) {
                continue;
            }

            $event = $events->get($ticket->event_id);
            $holder = $holderOf($ticket);
            $link = $links->get($ticket->event_id.'|'.$holder)
                ?? ($ticket->owner_user_id ? $byAccount->get($ticket->event_id.'|'.$ticket->owner_user_id) : null);

            if ($link === null && $holder !== '') {
                $order = $orders->get((string) $ticket->order_id);

                // The buyer's own, from a paid order: made now if need be.
                if ($order !== null
                    && in_array($order->status, Code::PAID_STATUSES, true)
                    && Str::lower((string) $order->buyer_email) === $holder) {
                    $link = $this->links->forHolder($event, $holder, $order->user_id, $order->id);
                    $links->put($ticket->event_id.'|'.$holder, $link);
                }
            }

            $answers[$ticket->id] = $this->links->present($link, $event, $event->organization?->name);
        }

        return $answers;
    }
}
