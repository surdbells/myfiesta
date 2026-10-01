<?php

namespace App\Mail;

use App\Mail\Concerns\KeepsTypedTextPlain;
use App\Mail\Concerns\RepliesReachSupport;
use App\Models\Event;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your event is approved and on sale — here is the link."
 *
 * Or approved and waiting: for the time the organizer set for it to go on
 * sale, or, when the organization is suspended, for that to be lifted. Either
 * way it goes on sale by itself. Decided when it was approved and passed in,
 * rather than read from the event when this is sent, so the email says what
 * happened rather than whatever is true a minute later.
 */
class EventApproved extends Mailable implements ShouldQueue
{
    use KeepsTypedTextPlain, Queueable, RepliesReachSupport, SerializesModels;

    public function __construct(
        public readonly Event $event,
        public readonly bool $waitsForSuspension = false,
        public readonly ?CarbonInterface $goesOnSaleAt = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->waitsForSuspension || $this->goesOnSaleAt !== null
                ? "{$this->event->title} is approved"
                : "{$this->event->title} is approved and on sale",
            replyTo: $this->supportReplyTo(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.event-approved',
            with: [
                'event' => $this->event,
                'waitsForSuspension' => $this->waitsForSuspension,
                // In the venue's zone, with the zone named: the time the
                // organizer typed, as they typed it.
                'onSaleAt' => $this->goesOnSaleAt?->timezone($this->event->timezone)->format('l j F Y, g:ia T'),
                'link' => rtrim((string) config('app.public_url'), '/').'/'.$this->event->slug,
                'url' => rtrim((string) config('app.console_url'), '/').'/events/'.$this->event->id,
            ],
        );
    }
}
