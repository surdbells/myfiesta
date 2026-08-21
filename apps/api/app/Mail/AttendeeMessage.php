<?php

namespace App\Mail;

use App\Models\EmailPreference;
use App\Models\EventMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * News about an event, from the organizer running it.
 *
 * Sent from the platform rather than from the organizer's own address, because
 * the platform's domain is the one with the SPF and DKIM records — a message
 * claiming to be from a venue's Gmail would be filed as spam by half the
 * inboxes it reached. The organizer's name is in the subject and the reply-to,
 * which is what actually matters to the reader.
 */
class AttendeeMessage extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly EventMessage $message,
        public readonly EmailPreference $preference,
    ) {}

    public function envelope(): Envelope
    {
        $event = $this->message->event;

        return new Envelope(
            // The event name leads. Somebody holding tickets to four things
            // needs to know which one this is about from the notification.
            subject: "{$event->title}: {$this->message->subject}",
        );
    }

    public function headers(): Headers
    {
        // Only on ordinary news. An important message is one somebody needs
        // regardless — offering a one-click way out of a venue change is
        // offering to let them turn up at the wrong building.
        if ($this->message->important) {
            return new Headers;
        }

        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        $event = $this->message->event;

        return new Content(
            markdown: 'mail.attendee-message',
            with: [
                'event' => $event,
                'organizer' => $event->organization->name,
                'body' => $this->message->body,
                'important' => $this->message->important,
                'localTime' => $event->starts_at
                    ->timezone($event->timezone)
                    ->format('l j F, g:i a T'),
                'url' => rtrim(config('app.public_url'), '/').'/'.$event->slug,
                'unsubscribeUrl' => $this->unsubscribeUrl(),
            ],
        );
    }

    private function unsubscribeUrl(): string
    {
        return route('unsubscribe', ['token' => $this->preference->token]);
    }
}
