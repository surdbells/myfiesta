<?php

namespace App\Mail;

use App\Mail\Concerns\KeepsTypedTextPlain;
use App\Mail\Concerns\RepliesReachSupport;
use App\Models\Ticket;
use App\Services\Events\CalendarFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * One ticket, sent to whoever holds it.
 *
 * Used when support resends a ticket to its holder, when support moves it to
 * a different address, and when its holder sends it to somebody themselves
 * (TicketHandover). Not the order confirmation: the person reading this may
 * not be the person who paid, and a receipt with somebody else's name and
 * total on it is the wrong thing to put in their inbox.
 *
 * A ticket that was moved comes with `link`, the new holder's own page for it
 * (that ticket, and nothing else of the order's). `sender` is who sent it, when
 * its holder did: an email that says whose ticket it was is one somebody can
 * tell from a phishing attempt.
 *
 * Both names were typed by the sender, and the address is any they chose, so
 * what they typed stays words and never becomes a link (KeepsTypedTextPlain).
 */
class YourTicket extends Mailable implements ShouldQueue
{
    use KeepsTypedTextPlain, Queueable, RepliesReachSupport, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly bool $reissued = false,
        public readonly bool $newCode = false,
        public readonly ?string $link = null,
        public readonly ?string $sender = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your ticket for {$this->ticket->event->title}",
            replyTo: $this->supportReplyTo(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.your-ticket',
            with: [
                'ticket' => $this->ticket,
                'event' => $this->ticket->event,
                'organizer' => $this->ticket->event->organization?->name,
                'reissued' => $this->reissued,
                'newCode' => $this->newCode,
                'link' => $this->link,
                'sender' => $this->sender,
                'stillTheirs' => $this->stillTheirs(),
            ],
        );
    }

    /**
     * Whether the ticket is still at the address this is going to.
     *
     * The email is queued and the ticket read again when it is sent. A ticket
     * that moved on in between — sent to a friend who passed it straight on —
     * now carries the next holder's code, and this email must not show it.
     * Only asked of a ticket that was moved: a resend goes to whoever holds it.
     */
    private function stillTheirs(): bool
    {
        if ($this->link === null) {
            return true;
        }

        $holder = Str::lower(trim((string) $this->ticket->owner_email));

        return collect($this->to)->contains(fn (array $to) => Str::lower(trim((string) $to['address'])) === $holder);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $calendar = app(CalendarFile::class);
        $event = $this->ticket->event;

        return [
            Attachment::fromData(fn () => $calendar->for($event), $calendar->filename($event))
                ->withMime('text/calendar'),
        ];
    }
}
