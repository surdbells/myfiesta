<?php

namespace App\Services\Door;

use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Support\Facades\Cache;

/**
 * Everything a door phone needs to keep admitting people with no signal.
 *
 * Venue connections fail constantly — basements, rooftops, a thousand phones
 * on one cell — and a door that stops when they do stops the whole night. So
 * the phone downloads the event's tickets while it still has a connection and
 * decides from that list when it has none.
 *
 * What it downloads is not the tickets. A ticket code is the thing that opens
 * the door, and a list of them on a phone makes a lost or borrowed phone into
 * a book of working tickets. The phone gets a one-way hash of each code
 * instead: enough to recognise a code somebody shows it, never enough to show
 * one. Hashing a scanned code and finding it in the list proves it is real;
 * the list itself cannot be turned back into anything that scans.
 *
 * Iterated rather than a single SHA-256, because the codes are short — twelve
 * characters from a 24-letter alphabet — and a single hash of something that
 * short can be searched exhaustively in about half an hour on one graphics
 * card. A thousand rounds turns that into weeks for a single night's entry,
 * for a cost of about a millisecond per scan on the phone. It raises the
 * price of forging a ticket from a stolen list; it does not make it
 * impossible, which is why the list is also only served to a door token or
 * an organizer allowed to scan this event.
 */
class DoorList
{
    public const ITERATIONS = 1000;

    /** Bumped if the hashing ever changes, so cached hashes are not reused. */
    private const VERSION = 'v1';

    /**
     * The event's salt: stable, so hashes can be cached and a phone's list
     * survives a refresh; per event, so a hash from one event says nothing
     * about another. It does not need to be secret, only to differ.
     */
    public function salt(Event $event): string
    {
        return substr(hash_hmac('sha256', 'door-list:'.self::VERSION.':'.$event->id, (string) config('app.key')), 0, 32);
    }

    /**
     * A code as the phone must hash it: trimmed and upper-cased, exactly as
     * the check-in service normalises what it is given.
     */
    public function hash(string $code, string $salt): string
    {
        return hash_pbkdf2('sha256', strtoupper(trim($code)), $salt, self::ITERATIONS, 64);
    }

    /**
     * @return array<string, mixed>
     */
    public function for(Event $event): array
    {
        $salt = $this->salt($event);

        $tickets = Ticket::query()
            ->where('event_id', $event->id)
            ->with('ticketType:id,name')
            ->orderBy('id')
            ->get(['id', 'code', 'status', 'admits', 'admitted_count', 'holder_name', 'ticket_type_id']);

        return [
            'event_id' => $event->id,
            'salt' => $salt,
            'iterations' => self::ITERATIONS,
            // The phone shows how old its list is, so somebody on the door can
            // tell a fresh list from one downloaded before doors opened.
            'generated_at' => now()->toIso8601String(),
            'tickets' => $tickets->map(fn (Ticket $ticket) => [
                'hash' => $this->cachedHash($ticket, $salt),
                // Refused states travel too: without them a refunded ticket is
                // simply absent, and "not recognised" is the wrong conversation
                // to have with somebody holding a real, cancelled ticket.
                'status' => $ticket->status,
                'admits' => $ticket->admits,
                'admitted_count' => $ticket->admitted_count,
                'holder_name' => $ticket->holder_name,
                'type' => $ticket->ticketType?->name,
            ])->values()->all(),
        ];
    }

    /**
     * A ticket's code never changes — a transfer keeps it — so its hash is
     * worked out once. Without this, a 2,000-ticket event costs nearly two
     * seconds of hashing every time any door phone refreshes.
     */
    private function cachedHash(Ticket $ticket, string $salt): string
    {
        return Cache::remember(
            'door-hash:'.self::VERSION.':'.$ticket->id,
            now()->addDays(14),
            fn () => $this->hash($ticket->code, $salt),
        );
    }
}
