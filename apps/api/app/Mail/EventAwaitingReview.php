<?php

namespace App\Mail;

use App\Filament\Resources\Events\EventResource;
use App\Mail\Concerns\KeepsTypedTextPlain;
use App\Mail\Concerns\RepliesReachSupport;
use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "A new event is waiting for review", to the staff who review them.
 *
 * Built from what was true when it was sent, as plain values, so the queue
 * carries no model and the email says what arrived even if the organizer
 * takes it back before it is read. The link goes to the review page, which
 * says whether it is still waiting.
 */
class EventAwaitingReview extends Mailable implements ShouldQueue
{
    use KeepsTypedTextPlain, Queueable, RepliesReachSupport;

    public readonly string $title;

    public readonly string $organizer;

    public readonly string $starts;

    public readonly string $where;

    /** Sent back before, or approved before and changed since. */
    public readonly bool $again;

    public readonly string $link;

    public function __construct(Event $event)
    {
        $event->loadMissing('organization:id,name');

        $this->title = (string) $event->title;
        $this->organizer = (string) ($event->organization->name ?? 'An organizer');
        $this->starts = $event->starts_at->timezone($event->timezone)->format('l j F Y, g:ia').' ('.$event->timezone.')';
        $this->where = collect([$event->city, $event->country])->filter()->implode(', ');
        $this->again = $event->approved_fingerprint !== null
            || $event->reviews()->where('action', 'rejected')->exists();
        $this->link = EventResource::getUrl('review', ['record' => $event->id], panel: 'admin');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "To review: {$this->title} from {$this->organizer}",
            replyTo: $this->supportReplyTo(),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.event-awaiting-review');
    }
}
