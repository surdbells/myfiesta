<?php

namespace App\Mail;

use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A ticket somebody was given rather than bought.
 *
 * Deliberately not the order confirmation. There is no order, no reference and
 * no amount, and an email thanking somebody for a purchase they did not make
 * reads as a billing error — which is the last thing a comped guest should have
 * to think about.
 */
class TicketIssued extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param  list<Ticket>  $tickets */
    public function __construct(
        public readonly Event $event,
        public readonly array $tickets,
        public readonly ?string $note = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're on the list for {$this->event->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.ticket-issued',
            with: [
                'event' => $this->event,
                'tickets' => $this->tickets,
                'note' => $this->note,
                'organizer' => $this->event->organization->name,
            ],
        );
    }
}
