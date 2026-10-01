<?php

namespace App\Mail;

use App\Models\Order;
use App\Services\Events\CalendarFile;
use App\Services\Receipts\Receipt;
use App\Services\Tickets\BuyersTickets;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
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
                // Read again when the queue sends it, by which time the buyer
                // may have sent one on: that one has a new code now, which
                // is for its new holder's email only (BuyersTickets).
                'tickets' => BuyersTickets::of($this->order, $this->order->tickets),
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
                /*
                 * What was paid, to whom, and the tax on it, line by line.
                 *
                 * This email is the one a buyer keeps, forwards to an
                 * accountant, or prints for an expense claim, so it carries
                 * the receipt rather than a link to one. Read from the order's
                 * own copy of its pricing, so a resend next month says what
                 * was charged, not what would be charged now. Only for an
                 * order that exists: there is nothing to account for in one
                 * that was never saved.
                 */
                'receipt' => $this->order->exists ? Receipt::for($this->order) : null,
            ],
        );
    }

    /**
     * The night, as a calendar file.
     *
     * Attached rather than linked: Gmail and Outlook both offer "add to
     * calendar" on an .ics that arrives with the message, which is one tap
     * at the moment somebody has just bought and is thinking about the date.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $calendar = app(CalendarFile::class);
        $event = $this->order->event;

        return [
            Attachment::fromData(fn () => $calendar->for($event), $calendar->filename($event))
                ->withMime('text/calendar'),
        ];
    }
}
