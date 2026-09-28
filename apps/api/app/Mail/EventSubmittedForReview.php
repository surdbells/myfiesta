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
 * "We have your event, and this is what happens next."
 *
 * To everybody at the organization who can put events on sale, not only the
 * person who pressed the button: whoever reads the approval, or the reason it
 * was sent back, should already know it was sent.
 */
class EventSubmittedForReview extends Mailable implements ShouldQueue
{
    use KeepsTypedTextPlain, Queueable, RepliesReachSupport, SerializesModels;

    public function __construct(public readonly Event $event) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->event->title} is waiting for review",
            replyTo: $this->supportReplyTo(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.event-submitted-for-review',
            with: [
                'event' => $this->event,
                'wait' => (string) config('events.review.typical_wait', 'one working day'),
                'url' => rtrim((string) config('app.console_url'), '/').'/events/'.$this->event->id,
            ],
        );
    }
}
