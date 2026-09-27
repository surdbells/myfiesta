<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Ticket;
use App\Services\Tickets\BuyersTickets;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The buyer's confirmation, sent again by support.
 *
 * The same email and the same link as the one checkout sent, listing only
 * the tickets that still get the buyer in. Checkout's email lists every
 * ticket on the order, which is right when they have just been minted. By the
 * time somebody rings support, some may have been refunded or voided, which
 * would put dead codes under "confirmed". Others may have been handed to
 * somebody else, and after a reissue that would mail the new code back to the
 * address it was taken from.
 *
 * Built from TicketsIssued rather than extending it, so the two cannot drift
 * apart, and so the queue can restore this one: a subclass cannot set the
 * parent's readonly order when a worker unserializes it. The tickets are
 * chosen when it is sent rather than when it is queued, so a ticket refunded
 * in between is left out as well.
 */
class TicketsResent extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Tickets that still admit somebody. */
    public const ADMITTING = ['valid', 'checked_in'];

    public function __construct(public readonly Order $order) {}

    public function envelope(): Envelope
    {
        return $this->original()->envelope();
    }

    public function content(): Content
    {
        $content = $this->original()->content();

        $content->with['tickets'] = BuyersTickets::of($this->order, $this->order->tickets)
            ->filter(fn (Ticket $ticket) => in_array($ticket->status, self::ADMITTING, true))
            ->values();

        return $content;
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return $this->original()->attachments();
    }

    private function original(): TicketsIssued
    {
        return new TicketsIssued($this->order);
    }
}
