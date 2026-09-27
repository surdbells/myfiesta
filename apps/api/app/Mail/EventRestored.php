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
 * The takedown is lifted, and what state the event was left in.
 *
 * Three ways it can be left: back on sale; a draft the organizer can publish;
 * or a draft waiting for its organization's suspension to be lifted, when it
 * goes back on sale by itself (EventModeration). The last is decided when the
 * takedown is lifted and passed in, rather than read from the event when this
 * is sent, because telling somebody to publish from the console when the
 * console would refuse them is worse than saying nothing.
 */
class EventRestored extends Mailable implements ShouldQueue
{
    use Queueable, RepliesReachSupport, SerializesModels;

    public function __construct(
        public readonly Event $event,
        public readonly bool $waitsForSuspension = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: match (true) {
                $this->waitsForSuspension => "The takedown on {$this->event->title} is lifted",
                $this->event->status === 'published' => "{$this->event->title} is back on sale",
                default => "You can publish {$this->event->title} again",
            },
            replyTo: $this->supportReplyTo(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.event-restored',
            with: [
                'event' => $this->event,
                'waitsForSuspension' => $this->waitsForSuspension,
                'url' => rtrim((string) config('app.console_url'), '/').'/events/'.$this->event->id,
            ],
        );
    }
}
