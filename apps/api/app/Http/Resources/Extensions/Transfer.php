<?php

namespace App\Http\Resources\Extensions;

use App\Models\Event;
use App\Models\Ticket;
use App\Services\Tickets\TicketHandover;
use Illuminate\Support\Collection;

/**
 * Whether a ticket can be sent to somebody else from where it is shown.
 *
 * Belongs to the transfer feature. The handover's own rules (TicketHandover):
 * a working ticket nobody has come in on, not given back, before the night
 * starts. Where it is shown is already somewhere its holder is — their
 * phone, their order's link or the link it was sent with — so this is about
 * the ticket, not the viewer.
 *
 * The night's start is read once for the whole list: prime() keeps each
 * event's start, keyed by event, and replaces it on every call (TicketExtras).
 */
class Transfer
{
    /** @var array<string, Event> */
    private array $events = [];

    /** @param  Collection<int, Ticket>  $tickets */
    public function primeTickets(Collection $tickets): void
    {
        $this->events = [];
        $missing = [];

        foreach ($tickets as $ticket) {
            if ($ticket->relationLoaded('event') && $ticket->event !== null) {
                $this->events[$ticket->event_id] = $ticket->event;
            } else {
                $missing[$ticket->event_id] = true;
            }
        }

        $missing = array_diff_key($missing, $this->events);

        if ($missing !== []) {
            Event::query()
                ->whereIn('id', array_keys($missing))
                ->get(['id', 'starts_at'])
                ->each(function (Event $event) {
                    $this->events[$event->id] = $event;
                });
        }
    }

    public function transferable(Ticket $ticket): ?bool
    {
        $event = $this->events[$ticket->event_id]
            ?? ($ticket->relationLoaded('event') ? $ticket->event : $ticket->event()->first(['id', 'starts_at']));

        // From the container rather than the constructor, so this class can
        // still be built with no arguments, as MyTicketsTest builds its own.
        return app(TicketHandover::class)->refusal($ticket, $event) === null;
    }
}
