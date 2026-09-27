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

/**
 * "We have taken your event off sale, and this is why."
 *
 * Replies reach support, because the only useful thing an organizer can do
 * with this email is answer it — and an answer that lands in a no-reply inbox
 * turns a fixable problem into a lost organizer.
 */
class EventTakenDown extends Mailable implements ShouldQueue
{
    use Queueable, RepliesReachSupport, SerializesModels;

    public function __construct(
        public readonly Event $event,
        public readonly string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->event->title} has been taken off sale",
            replyTo: $this->supportReplyTo(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.event-taken-down',
            with: [
                'event' => $this->event,
                'reason' => $this->reason,
                'url' => rtrim((string) config('app.console_url'), '/').'/events/'.$this->event->id,
            ],
        );
    }
}
