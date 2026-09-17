<?php

namespace App\Mail;

use App\Models\Event;
use App\Models\OrganizationFollow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use App\Models\EmailPreference;
use App\Support\RichText;
use Illuminate\Support\Str;

/**
 * "Lagos Nights announced a night", to somebody who asked to hear it.
 *
 * Two ways out, because they mean different things: stop following this
 * organizer, for somebody who is done with them, and the blanket unsubscribe,
 * for somebody who is done with mail like this from anyone. Offering only the
 * second loses a reader who liked one organizer and not another.
 */
class EventAnnouncedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Event $event,
        public readonly OrganizationFollow $follow,
        public readonly string $email,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->event->organization->name} announced {$this->event->title}",
        );
    }

    /**
     * The header a mail client reads to draw its own unsubscribe button.
     *
     * Gmail and Outlook show it beside the sender, which is where most people
     * look first — and a reader who finds the way out there does not press
     * "spam" instead.
     */
    public function headers(): Headers
    {
        $preference = EmailPreference::forEmail($this->email);

        return new Headers(text: [
            'List-Unsubscribe' => '<'.route('unsubscribe', $preference->token).'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.event-announced',
            with: [
                'event' => $this->event,
                'organizer' => $this->event->organization,
                'localTime' => $this->event->starts_at
                    ->timezone($this->event->timezone)
                    ->format('l j F, g:i a T'),
                'where' => $this->event->venue?->name
                    ? $this->event->venue->name.', '.$this->event->city
                    : $this->event->city,
                // The first couple of lines of the organizer's own words.
                // Enough to tell somebody whether this is their kind of night;
                // the page is where the rest of it lives.
                'blurb' => Str::limit(RichText::toText($this->event->description), 220),
                'url' => rtrim(config('app.public_url'), '/').'/'.$this->event->slug,
                'stopUrl' => route('follows.leave', ['token' => $this->follow->token]),
            ],
        );
    }
}
