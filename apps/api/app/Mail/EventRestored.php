<?php

namespace App\Mail;

use App\Mail\Concerns\RepliesReachSupport;
use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The takedown is lifted, and what state the event was left in. */
class EventRestored extends Mailable implements ShouldQueue
{
    use Queueable, RepliesReachSupport, SerializesModels;

    public function __construct(public readonly Event $event) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->event->status === 'published'
                ? "{$this->event->title} is back on sale"
                : "You can publish {$this->event->title} again",
            replyTo: $this->supportReplyTo(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.event-restored',
            with: [
                'event' => $this->event,
                'url' => rtrim((string) config('app.console_url'), '/').'/events/'.$this->event->id,
            ],
        );
    }
}
