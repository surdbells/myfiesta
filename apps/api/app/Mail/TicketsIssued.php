<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * The buyer's tickets.
 *
 * Queued, like everything else that sends mail. The previous platform looped
 * the guest list inside the HTTP request and called the mail API once per
 * person, so a large event timed out mid-send with no record of who had been
 * reached.
 */
class TicketsIssued extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your tickets for {$this->order->event->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.tickets-issued',
            with: [
                'order' => $this->order,
                'event' => $this->order->event,
                'tickets' => $this->order->tickets,
                /*
                 * The ticket page on the public site, carrying the order's own
                 * token.
                 *
                 * This used to be a signed URL into the API, which returned
                 * JSON — so a buyer following it got a wall of braces instead
                 * of their ticket. A signed URL also could not be moved to the
                 * site, because the signature only validates against the exact
                 * URL it was computed for.
                 *
                 * And it no longer expires. The previous platform's links went
                 * stale before some of its events had happened, and a ticket
                 * you cannot open on the night is not a ticket.
                 */
                'url' => rtrim(config('app.public_url'), '/')
                    .'/tickets/'.$this->order->access_token,
            ],
        );
    }
}
