<?php

namespace App\Mail;

use App\Models\Campaign;
use App\Models\EmailPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * An organizer's campaign, to one person.
 *
 * Says who it is from, why this person is getting it, and how to stop — the
 * three things CASL asks of a commercial email, and the three things that
 * decide whether somebody unsubscribes or presses "spam". The way out is the
 * marketing one: it stops campaigns and announcements, never reminders about
 * tickets they hold.
 *
 * No tracking pixel. What a campaign did is counted by the orders that came
 * through its link, which is the number an organizer actually wants and
 * needs nothing from the reader's mail client.
 */
class CampaignMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    private const WHY = [
        'followers' => 'You are getting this because you follow :name on myFiesta.',
        'past_attendees' => 'You are getting this because you went to a :name event.',
        'abandoned' => 'You are getting this because you started buying tickets for this event.',
    ];

    public function __construct(
        public readonly Campaign $campaign,
        public readonly string $email,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->campaign->subject);
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
        $event = $this->campaign->event;
        $organizer = $this->campaign->organization->name;

        return new Content(
            markdown: 'mail.campaign',
            with: [
                'body' => $this->campaign->body,
                'organizer' => $organizer,
                'event' => $event,
                'localTime' => $event?->starts_at->timezone($event->timezone)->format('l j F, g:i a T'),
                'where' => $event ? ($event->venue?->name ? $event->venue->name.', '.$event->city : $event->city) : null,
                // The ref is how a sale is traced back to this email.
                'url' => $event
                    ? rtrim(config('app.public_url'), '/').'/'.$event->slug.'?ref='.$this->campaign->ref
                    : null,
                'why' => str_replace(':name', $organizer, self::WHY[$this->campaign->audience]),
                'unsubscribeUrl' => $this->unsubscribeUrl(),
            ],
        );
    }

    private function unsubscribeUrl(): string
    {
        return route('unsubscribe', [EmailPreference::forEmail($this->email)->token, 'kind' => 'marketing']);
    }
}
