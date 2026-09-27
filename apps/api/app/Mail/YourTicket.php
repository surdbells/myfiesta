<?php

namespace App\Mail;

use App\Mail\Concerns\RepliesReachSupport;
use App\Models\Ticket;
use App\Services\Events\CalendarFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One ticket, sent to whoever holds it, by somebody at myFiesta.
 *
 * Used when support resends a ticket to its holder, or moves it to a
 * different address. Not the order confirmation: the person reading this may
 * not be the person who paid, and a receipt with somebody else's name and
 * total on it is the wrong thing to put in their inbox.
 */
class YourTicket extends Mailable implements ShouldQueue
{
    use Queueable, RepliesReachSupport, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly bool $reissued = false,
        public readonly bool $newCode = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your ticket for {$this->ticket->event->title}",
            replyTo: $this->supportReplyTo(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.your-ticket',
            with: [
                'ticket' => $this->ticket,
                'event' => $this->ticket->event,
                'organizer' => $this->ticket->event->organization?->name,
                'reissued' => $this->reissued,
                'newCode' => $this->newCode,
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $calendar = app(CalendarFile::class);
        $event = $this->ticket->event;

        return [
            Attachment::fromData(fn () => $calendar->for($event), $calendar->filename($event))
                ->withMime('text/calendar'),
        ];
    }
}
