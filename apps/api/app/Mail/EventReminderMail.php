<?php

namespace App\Mail;

use App\Models\EmailPreference;
use App\Models\EventReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * "Your event is on Saturday."
 *
 * Carries the List-Unsubscribe headers as well as a link in the body. Gmail and
 * Outlook read those headers and offer their own unsubscribe control — and an
 * inbox that shows a one-click unsubscribe gets fewer spam reports than one
 * where the only way out is hunting through the footer. Spam reports are what
 * cost a sending domain its reputation, and a domain with no reputation cannot
 * deliver ticket confirmations either.
 */
class EventReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly EventReminder $reminder,
        public readonly EmailPreference $preference,
    ) {}

    public function envelope(): Envelope
    {
        $event = $this->reminder->event;

        // The subject says when, because the subject is the whole message for
        // most people — it is read in a notification and never opened.
        return new Envelope(
            subject: "{$event->title} is {$this->phrasing()}",
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
            // Declares that the URL accepts a POST, which is what lets the
            // mail client unsubscribe without opening a browser. Without it,
            // clients treat the link as a page to visit and some will prefetch
            // it — which, on a GET-only endpoint, unsubscribes people who never
            // clicked anything.
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        $event = $this->reminder->event;

        return new Content(
            markdown: 'mail.event-reminder',
            with: [
                'event' => $event,
                'when' => $this->phrasing(),
                'localTime' => $this->localTime(),
                // No page to link to while the event is held off sale by its
                // organization's suspension: the site answers 404 for a draft.
                'url' => $event->status === 'published'
                    ? rtrim(config('app.public_url', config('app.url')), '/').'/'.$event->slug
                    : null,
                'unsubscribeUrl' => $this->unsubscribeUrl(),
            ],
        );
    }

    /**
     * "on Saturday", "tomorrow", "in 2 hours" — as a person would say it.
     *
     * Named phrasing() rather than when(), because Mailable already has a
     * public when() from Conditionable and overriding it privately is a fatal
     * error rather than a subtle one.
     */
    private function phrasing(): string
    {
        $minutes = $this->reminder->offset_minutes;

        if ($minutes >= 1440) {
            $days = intdiv($minutes, 1440);

            return $days === 1
                ? 'tomorrow'
                : 'on '.$this->reminder->event->starts_at
                    ->timezone($this->reminder->event->timezone)
                    ->format('l');
        }

        $hours = intdiv($minutes, 60);

        return $hours >= 1
            ? ($hours === 1 ? 'in about an hour' : "in about {$hours} hours")
            : 'starting shortly';
    }

    /**
     * The start time in the event's own zone.
     *
     * Never the reader's, and never the server's. Somebody in Lagos holding a
     * ticket to a Toronto event needs to know when to be at the door in
     * Toronto, and the abbreviation is what makes that unambiguous.
     */
    private function localTime(): string
    {
        $event = $this->reminder->event;

        return $event->starts_at
            ->timezone($event->timezone)
            ->format('l j F, g:i a T');
    }

    private function unsubscribeUrl(): string
    {
        return route('unsubscribe', ['token' => $this->preference->token]);
    }
}
