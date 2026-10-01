<?php

namespace App\Mail;

use App\Models\EmailPreference;
use App\Models\SurveyInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * "How was Afro Fest?", the morning after, to somebody who came.
 *
 * Mail they did not ask for, so it is the marketing kind: an address that
 * has said no to that is never sent one, and the way out here — in the footer
 * and in the headers a mail client draws its own button from — stops this
 * and nothing about a ticket somebody holds.
 */
class SurveyInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly SurveyInvitation $invitation,
        public readonly EmailPreference $preference,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "How was {$this->invitation->survey->event->title}?",
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        $survey = $this->invitation->survey;
        $event = $survey->event;

        return new Content(
            markdown: 'mail.survey-invitation',
            with: [
                'event' => $event,
                'organizer' => $event->organization->name,
                // The night in its own zone, as the ticket said it.
                'night' => $event->starts_at->timezone($event->timezone)->format('l j F'),
                'questions' => count($survey->questions ?? []),
                'url' => self::link($this->invitation->token),
                'unsubscribeUrl' => $this->unsubscribeUrl(),
            ],
        );
    }

    /** Where the survey is answered: the site's page for it, not the API. */
    public static function link(string $token): string
    {
        return rtrim((string) config('app.public_url', config('app.url')), '/').'/tickets/feedback/'.$token;
    }

    private function unsubscribeUrl(): string
    {
        return route('unsubscribe', [$this->preference->token, 'kind' => 'marketing']);
    }
}
