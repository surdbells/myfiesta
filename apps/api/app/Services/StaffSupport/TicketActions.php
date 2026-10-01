<?php

namespace App\Services\StaffSupport;

use App\Mail\TicketsResent;
use App\Mail\YourTicket;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Tickets\BuyersTickets;
use App\Services\Tickets\TicketHandover;
use App\Services\Tickets\TicketHandoverRefused;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * What support does with orders and tickets, short of sending money back.
 *
 * Refunds are not here. They go through RefundService, which owns the
 * arithmetic, the gateway and the ledger; the panel calls it directly.
 */
class TicketActions
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly TicketHandover $handover,
    ) {}

    /**
     * The buyer's confirmation, again.
     *
     * The same email checkout sent, with the same link, so somebody who cannot
     * find it gets what they would have had rather than a staff-written
     * substitute. It lists only the tickets that still admit somebody and are
     * still the buyer's (TicketsResent): not refunded or voided ones, and not
     * one handed to somebody else, whose new code must not come back here.
     */
    public function resendOrder(Order $order, User $staff): void
    {
        StaffAction::Resend->authorize($staff);

        if (! in_array($order->status, ['paid', 'partially_refunded'], true)) {
            throw StaffActionRefused::because('Only a paid order has tickets to send. This one is '.str_replace('_', ' ', $order->status).'.');
        }

        if (blank($order->buyer_email)) {
            throw StaffActionRefused::because('Nobody gave an address for this order — it was sold at the door.');
        }

        // Read whole: a list hands over an order loaded with only the columns
        // it displays, and the email needs the venue, the times and the rest.
        $order = $order->fresh(['event.venue', 'tickets.ticketType']);

        $admitting = $order->tickets->whereIn('status', TicketsResent::ADMITTING);

        if ($admitting->isEmpty()) {
            throw StaffActionRefused::because('No ticket on this order still admits anybody.');
        }

        if (BuyersTickets::of($order, $admitting)->isEmpty()) {
            throw StaffActionRefused::because('Every ticket on this order that still works has been moved to somebody else. Resend those from the Tickets screen, to whoever holds them.');
        }

        Mail::to($order->buyer_email)->send(new TicketsResent($order));

        $this->auditor->record('order.tickets_resent', $order, $staff);
    }

    /** One ticket, to whoever holds it now. */
    public function resendTicket(Ticket $ticket, User $staff): void
    {
        StaffAction::Resend->authorize($staff);

        if ($ticket->status !== 'valid') {
            throw StaffActionRefused::because(match ($ticket->status) {
                'checked_in' => 'This ticket has already been used at the door.',
                'listed' => 'This ticket is listed for resale, so it has no working code to send.',
                default => 'This ticket no longer admits anybody.',
            });
        }

        $to = $ticket->owner_email ?: $ticket->order?->buyer_email;

        if (blank($to)) {
            throw StaffActionRefused::because('There is no address on this ticket to send it to.');
        }

        Mail::to($to)->send(new YourTicket($ticket->fresh(['event.venue', 'event.organization', 'ticketType'])));

        $this->auditor->record('ticket.resent', $ticket, $staff);
    }

    /**
     * Stop a ticket working, without sending money back.
     *
     * For a ticket that should never have existed — issued twice, sold on
     * after a chargeback, obtained by somebody it was not meant for. A ticket
     * somebody paid for and should be repaid for is refunded instead, which
     * voids it as part of the same act.
     */
    public function void(Ticket $ticket, User $staff, string $reason): Ticket
    {
        StaffAction::Void->authorize($staff);

        $reason = trim($reason);

        if ($reason === '') {
            throw StaffActionRefused::because('Say why this ticket is being voided.');
        }

        $previous = DB::transaction(function () use ($ticket) {
            /** @var Ticket $locked */
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'checked_in' || $locked->checked_in_at !== null || $locked->admitted_count > 0) {
                throw StaffActionRefused::because('Somebody has already come in on this ticket. It cannot be voided.');
            }

            if ($locked->status === 'listed') {
                throw StaffActionRefused::because('This ticket is listed for resale. The listing has to end before it can be voided.');
            }

            if ($locked->status !== 'valid') {
                throw StaffActionRefused::because('This ticket is already '.$locked->status.'.');
            }

            $locked->update(['status' => 'void']);

            return 'valid';
        });

        $this->auditor->record('ticket.voided', $ticket, $staff, metadata: [
            'reason' => $reason,
            'from' => $previous,
            'order_id' => $ticket->order_id,
        ]);

        return $ticket->refresh();
    }

    /**
     * Move a ticket to somebody else's address.
     *
     * The same handover as an attendee's own transfer (TicketHandover) — the
     * lock, the transfer row with the new holder's own link, the account
     * found or made unclaimed, the audit and the email — done for them. With
     * a new code by default, so the email that went to the old address stops
     * opening the door. Unlike a holder's, not closed once the night starts:
     * support moving a ticket at the door is what this is for.
     */
    public function reissue(Ticket $ticket, User $staff, string $email, string $name, bool $newCode = true, ?string $reason = null): Ticket
    {
        StaffAction::Reissue->authorize($staff);

        $email = Str::lower(trim($email));
        $name = trim($name);

        if (Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:255']])->fails()) {
            throw StaffActionRefused::because('That is not an email address.');
        }

        if ($name === '') {
            throw StaffActionRefused::because('Give the name of the person it is going to.');
        }

        try {
            $this->handover->reissue($ticket, $email, $name, $staff, $newCode, $reason);
        } catch (TicketHandoverRefused $refused) {
            // The same refusals, in the words the panel has always used.
            throw StaffActionRefused::because(match ($refused->reason) {
                TicketHandoverRefused::USED => 'This ticket has already been used at the door.',
                TicketHandoverRefused::LISTED => 'This ticket is listed for resale and cannot be moved until the listing ends.',
                TicketHandoverRefused::PARTLY_USED => 'Somebody has already come in on this ticket.',
                TicketHandoverRefused::SAME_ADDRESS => 'This ticket is already held by that address. Resend it instead.',
                default => 'This ticket no longer admits anybody.',
            });
        }

        return $ticket->refresh();
    }

    /**
     * A note on an order, for the next person who opens it.
     *
     * Written to the audit trail rather than a column: it is a statement by a
     * named person at a moment, it is never edited, and that is exactly what
     * the trail already is.
     */
    public function addOrderNote(Order $order, User $staff, string $note): void
    {
        StaffAction::Note->authorize($staff);

        $note = trim($note);

        if (mb_strlen($note) < 3) {
            throw StaffActionRefused::because('Write the note.');
        }

        $this->auditor->record('order.staff_note', $order, $staff, metadata: [
            'note' => Str::limit($note, 2000, ''),
            'channel' => $order->channel,
        ]);
    }
}
