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
                // Signed and expiring. A guest with no account still needs a
                // way back to their tickets, and a guessable URL is how the
                // previous platform let anyone read anyone else's.
                'url' => URL::temporarySignedRoute(
                    'orders.show',
                    now()->addDays(90),
                    ['order' => $this->order->id],
                ),
            ],
        );
    }
}
