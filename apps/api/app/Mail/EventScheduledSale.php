<?php

namespace App\Mail;

use App\Mail\Concerns\KeepsTypedTextPlain;
use App\Mail\Concerns\RepliesReachSupport;
use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * What became of a night at the time set for it to go on sale
 * (events:go-live, ScheduledGoLive).
 *
 * Nobody pressed anything, so nobody saw it happen, and each of the three is
 * something to know: it is on sale, and here is the link; it changed since it
 * was approved, so it went to myFiesta for review instead; or it could not be
 * sent at all, and why. To everybody at the organization who can put events
 * on sale, as every review email is.
 *
 * Several dates of one repeating night that reached their time together —
 * all of them at once, when the organizer has just told its dates to go on
 * sale as soon as they are made — are one email listing each (`dates`),
 * rather than one email a date.
 */
class EventScheduledSale extends Mailable implements ShouldQueue
{
    use KeepsTypedTextPlain, Queueable, RepliesReachSupport, SerializesModels;

    public const ON_SALE = 'on_sale';

    public const IN_REVIEW = 'in_review';

    public const NOT_SENT = 'not_sent';

    /**
     * `$dates` is every date of a series that went at once, the first being
     * `$event`; empty for a night on its own.
     *
     * @param  list<string>  $reasons  why it was not sent, as the organizer can act on them
     * @param  list<array{when: string, link: string, outcome: string, reasons: list<string>}>  $dates
     */
    public function __construct(
        public readonly Event $event,
        public readonly string $outcome,
        public readonly array $reasons = [],
        public readonly array $dates = [],
    ) {}

    public function envelope(): Envelope
    {
        $title = $this->event->title;
        $count = count($this->dates);

        $subject = match (true) {
            $count > 1 && collect($this->dates)->every(fn (array $date) => $date['outcome'] === self::ON_SALE) => "{$count} dates of {$title} are on sale",
            $count > 1 => "What happened to {$count} dates of {$title}",
            $this->outcome === self::ON_SALE => "{$title} is on sale",
            $this->outcome === self::IN_REVIEW => "{$title} went for review at the time you set",
            default => "{$title} did not go on sale",
        };

        return new Envelope(subject: $subject, replyTo: $this->supportReplyTo());
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.event-scheduled-sale',
            with: [
                'event' => $this->event,
                'outcome' => $this->outcome,
                'reasons' => $this->reasons,
                'listed' => count($this->dates) > 1 ? $this->dates : [],
                'wait' => (string) config('events.review.typical_wait', 'one working day'),
                'link' => rtrim((string) config('app.public_url'), '/').'/'.$this->event->slug,
                'url' => rtrim((string) config('app.console_url'), '/').'/events/'.$this->event->id,
            ],
        );
    }
}
