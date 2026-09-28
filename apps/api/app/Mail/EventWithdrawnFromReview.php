<?php

namespace App\Mail;

use App\Mail\Concerns\KeepsTypedTextPlain;
use App\Mail\Concerns\RepliesReachSupport;
use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "You took your event back from review. It is a draft you can change."
 *
 * A confirmation, to the same people who were told it was sent: otherwise
 * whoever is still waiting for an answer waits for one that is not coming.
 */
class EventWithdrawnFromReview extends Mailable implements ShouldQueue
{
    use KeepsTypedTextPlain, Queueable, RepliesReachSupport, SerializesModels;

    public function __construct(public readonly Event $event) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->event->title} was taken back from review",
            replyTo: $this->supportReplyTo(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.event-withdrawn-from-review',
            with: [
                'event' => $this->event,
                'url' => rtrim((string) config('app.console_url'), '/').'/events/'.$this->event->id,
            ],
        );
    }
}
