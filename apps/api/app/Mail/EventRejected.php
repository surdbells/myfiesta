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
 * "Your event needs changes before it goes on sale, and this is why."
 *
 * The reviewer's words exactly as they wrote them: a paraphrase is a second
 * reason the organizer has to reconcile with the first. Replies reach
 * support, because the useful answer to "why?" is a person.
 */
class EventRejected extends Mailable implements ShouldQueue
{
    use KeepsTypedTextPlain, Queueable, RepliesReachSupport, SerializesModels;

    public function __construct(
        public readonly Event $event,
        public readonly string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->event->title} needs changes before it goes on sale",
            replyTo: $this->supportReplyTo(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.event-rejected',
            with: [
                'event' => $this->event,
                'reason' => $this->reason,
                'url' => rtrim((string) config('app.console_url'), '/').'/events/'.$this->event->id,
            ],
        );
    }
}
