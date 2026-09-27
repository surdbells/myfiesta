<?php

namespace App\Services\Disputes;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketTransfer;
use App\Services\Tickets\BuyersTickets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What happened to an order's tickets after the sale, written as it happens.
 *
 * The answer to "I never got my tickets" is a list: issued at 21:04, emailed
 * at 21:04 and taken by the mail provider under this id, the link opened at
 * 21:06 and again on the night, the QR on the phone at the door. Each line is
 * written here at the moment it happens, by our own server, into a table
 * nobody can edit (ticket_activity) — not reconstructed afterwards from logs
 * that were never meant to be evidence.
 *
 * The door is not repeated here: ticket_scans is the record of every scan,
 * and a dispute reads it beside this.
 *
 * An address and a browser name are written only on the rows that are
 * somebody opening something, and only as the request carried them (the
 * address as TRUSTED_PROXIES lets the application believe it). They are
 * deleted with the rest 18 months after the event. Nothing is worked out from
 * them: no location, no fingerprint. A ticket the buyer passed on is shown in
 * its new holder's app without their address — they are not the buyer, and a
 * bank has no reason to see where they were.
 *
 * No opening is written for a night past the dispute window
 * (EvidenceRetention): no dispute can come, so there is nothing for it to
 * answer. Anything else written about such a night — an old ticket sent on by
 * support — goes at the next prune, with the rest of that night's history.
 */
class ActivityLog
{
    /**
     * The order's tickets were minted. Written in the same transaction, so it
     * is true when it is read.
     *
     * @param  list<Ticket>  $tickets
     */
    public function issued(Order $order, array $tickets): void
    {
        if ($tickets === []) {
            return;
        }

        TicketActivity::query()->create([
            'event_id' => $order->event_id,
            'order_id' => $order->id,
            'kind' => TicketActivity::ISSUED,
            // Ids, never codes: a code opens a door, and this is read by
            // people answering a bank.
            'details' => [
                'count' => count($tickets),
                'tickets' => array_map(fn (Ticket $ticket) => $ticket->id, $tickets),
            ],
            'occurred_at' => now(),
        ]);
    }

    /**
     * An email about an order, or about tickets, left us for the mail provider.
     *
     * One row for an order, naming the tickets it listed; one per ticket for
     * an email about tickets alone — a ticket given away, or sent on to its
     * holder by support. The message id is the provider's own, which is what
     * its logs are searched by when a buyer says nothing arrived.
     *
     * @param  iterable<Ticket>  $tickets
     */
    public function emailed(
        ?Order $order,
        iterable $tickets,
        ?string $mailable,
        string $recipients,
        ?string $messageId,
        ?string $subject,
    ): void {
        $tickets = collect($tickets)->values();

        $common = [
            'kind' => TicketActivity::EMAILED,
            'mailable' => $mailable === null ? null : Str::limit(class_basename($mailable), 120, ''),
            'recipient_email' => Str::limit($recipients, 250, ''),
            'message_id' => $messageId === null ? null : Str::limit($messageId, 250, ''),
            'occurred_at' => now(),
        ];

        if ($order !== null) {
            TicketActivity::query()->create($common + [
                'event_id' => $order->event_id,
                'order_id' => $order->id,
                'details' => array_filter([
                    'subject' => $subject,
                    'tickets' => $tickets->map(fn (Ticket $ticket) => $ticket->id)->all() ?: null,
                ]),
            ]);

            return;
        }

        foreach ($tickets as $ticket) {
            TicketActivity::query()->create($common + [
                'event_id' => $ticket->event_id,
                'order_id' => $ticket->order_id,
                'ticket_id' => $ticket->id,
                'details' => array_filter(['subject' => $subject]),
            ]);
        }
    }

    /**
     * Somebody opened one of an order's pages: the ticket page, the older
     * signed link, or the calendar file.
     *
     * Never in the way of the page. A row that cannot be written is reported
     * and the page is answered all the same.
     */
    public function opened(Order $order, string $kind, Request $request): void
    {
        rescue(function () use ($order, $kind, $request) {
            if (EvidenceRetention::past([$order->event_id])->isNotEmpty()) {
                return;
            }

            [$ip, $agent] = $this->from($request);

            if ($this->recent($kind, $ip)->where('order_id', $order->id)->exists()) {
                return;
            }

            TicketActivity::query()->create([
                'event_id' => $order->event_id,
                'order_id' => $order->id,
                'kind' => $kind,
                'ip_address' => $ip,
                'user_agent' => $agent,
                'occurred_at' => now(),
            ]);
        });
    }

    /**
     * The app's ticket list was drawn, and with it each ticket's QR.
     *
     * One row per ticket shown, in one insert, leaving out the ones the same
     * phone already showed in the last few minutes. Only sold tickets: one an
     * organizer gave away has no payment behind it to dispute.
     *
     * Whoever holds the ticket now may not be the buyer. A ticket passed on
     * (BuyersTickets) is written as shown, which says the buyer's ticket was
     * delivered and used, but without the new holder's address or browser.
     *
     * @param  iterable<Ticket>  $tickets
     */
    public function shownInApp(iterable $tickets, Request $request): void
    {
        rescue(function () use ($tickets, $request) {
            $tickets = collect($tickets)
                ->filter(fn (Ticket $ticket) => $ticket->order_id !== null && in_array($ticket->status, ['valid', 'checked_in'], true))
                ->keyBy('id');

            $past = EvidenceRetention::past($tickets->pluck('event_id'))->flip();
            $tickets = $tickets->reject(fn (Ticket $ticket) => $past->has($ticket->event_id));

            if ($tickets->isEmpty()) {
                return;
            }

            [$ip, $agent] = $this->from($request);
            $buyers = $this->stillWithTheBuyer($tickets);

            // Each against a row as it would be written: the buyer's with
            // this address, a passed-on ticket's with none.
            $lately = fn (Collection $ids, ?string $address) => $ids->isEmpty() ? collect() : $this->recent(TicketActivity::QR_IN_APP, $address)
                ->whereIn('ticket_id', $ids->values()->all())
                ->pluck('ticket_id');

            [$own, $passedOn] = $tickets->keys()->partition(fn (string $id) => $buyers->has($id));
            $shown = $lately($own, $ip)->merge($lately($passedOn, null))->flip();

            $rows = $tickets
                ->reject(fn (Ticket $ticket) => $shown->has($ticket->id))
                ->map(fn (Ticket $ticket) => [
                    'id' => (string) Str::uuid(),
                    'event_id' => $ticket->event_id,
                    'order_id' => $ticket->order_id,
                    'ticket_id' => $ticket->id,
                    'kind' => TicketActivity::QR_IN_APP,
                    'ip_address' => $buyers->has($ticket->id) ? $ip : null,
                    'user_agent' => $buyers->has($ticket->id) ? $agent : null,
                    'occurred_at' => now(),
                ])
                ->values()
                ->all();

            if ($rows !== []) {
                TicketActivity::query()->insert($rows);
            }
        });
    }

    /**
     * A ticket was handed to somebody else.
     *
     * The transfer's own row says from whom, to whom, who asked and when;
     * this points at it, so the ticket's history reads in one place without
     * a second copy of any of that.
     */
    public function transferred(TicketTransfer $transfer): void
    {
        $ticket = $transfer->ticket()->first(['id', 'event_id', 'order_id']);

        if ($ticket === null) {
            return;
        }

        TicketActivity::query()->create([
            'event_id' => $ticket->event_id,
            'order_id' => $ticket->order_id,
            'ticket_id' => $ticket->id,
            'ticket_transfer_id' => $transfer->id,
            'kind' => TicketActivity::TRANSFERRED,
            'occurred_at' => $transfer->transferred_at ?? now(),
        ]);
    }

    /**
     * Where the request came from, as the application believes it, and the
     * browser's own name for itself, cut to what the column keeps.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function from(Request $request): array
    {
        $agent = $request->userAgent();

        return [
            $request->ip(),
            $agent === null || $agent === '' ? null : mb_substr($agent, 0, 512),
        ];
    }

    /**
     * The same thing opened from the same address lately.
     *
     * By address alone, not by browser: the browser's name is whatever the
     * request says it is, and a history that counted each new one as a new
     * opening could be padded by anybody holding the link, in rows nobody can
     * delete. A passed-on ticket's rows carry no address, so the app showing
     * it is written once per few minutes whoever's phone it is on.
     *
     * @return Builder<TicketActivity>
     */
    private function recent(string $kind, ?string $ip)
    {
        $query = TicketActivity::query()
            ->where('kind', $kind)
            ->where('occurred_at', '>=', now()->subMinutes((int) config('disputes.activity.repeat_minutes', 10)));

        return $ip === null ? $query->whereNull('ip_address') : $query->where('ip_address', $ip);
    }

    /**
     * The ones still the buyer's own, by id, as the buyer's link decides it.
     *
     * @param  Collection<string, Ticket>  $tickets
     * @return Collection<string, int>
     */
    private function stillWithTheBuyer(Collection $tickets): Collection
    {
        $orders = Order::query()
            ->whereIn('id', $tickets->pluck('order_id')->unique()->values()->all())
            ->get(['id', 'buyer_email'])
            ->keyBy('id');

        return $tickets
            ->groupBy('order_id')
            ->flatMap(fn (Collection $theirs, string $orderId) => isset($orders[$orderId])
                ? BuyersTickets::of($orders[$orderId], $theirs->values())->all()
                : [])
            ->mapWithKeys(fn (Ticket $ticket) => [$ticket->id => 1]);
    }
}
