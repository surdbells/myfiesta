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
 * inboxes it reached. The event leads the subject and the organizer's name
 * signs the message, which is what actually matters to the reader.
 *
 * No Reply-To, so a reply goes to the platform's from address. An organization
 * has no address it has chosen for replies. contact_email is only ever written
 * by the legacy import — from the old brand settings, or else the account
 * holder's own sign-in address — and nothing in the console shows it or lets
 * anybody change it, so buyers' replies sent there would land in an inbox
 * nobody picked for them. Nor the address of whoever pressed send: the message
 * goes out in the organization's name, not theirs. When an owner can name an
 * address for replies, that is the Reply-To this should carry.
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
