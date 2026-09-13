<?php

namespace App\Mail;

use App\Models\WaitlistEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Tickets are available", to somebody who asked to be told.
 *
 * Says plainly that it is first come, first served: a waitlist email that
 * reads like a reservation sends somebody to a sold-out page twice.
 */
class WaitlistOpenedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly WaitlistEntry $entry,
        public readonly ?string $note = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Tickets are available: {$this->entry->event->title}");
    }

    public function content(): Content
    {
        $event = $this->entry->event;

        return new Content(
            markdown: 'mail.waitlist-opened',
            with: [
                'event' => $event,
                'name' => $this->entry->name,
                'note' => $this->note,
                'localTime' => $event->starts_at->timezone($event->timezone)->format('l j F, g:i a T'),
                'url' => rtrim(config('app.public_url'), '/').'/'.$event->slug.'/tickets',
                'leaveUrl' => route('waitlist.leave', ['token' => $this->entry->token]),
            ],
        );
    }
}
