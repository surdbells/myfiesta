<?php

namespace App\Mail;

use App\Filament\Resources\Disputes\DisputeResource;
use App\Mail\Concerns\KeepsTypedTextPlain;
use App\Models\Dispute;
use App\Services\Disputes\Reasons;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Two days left to answer the chargeback on order K7QX3M9A", to Admin and
 * Finance, at five days and again at two (DisputeDesk::remindDue).
 *
 * Only for a dispute nobody has answered yet — neither sent evidence nor
 * accepted. After the deadline the processor takes nothing, so this is the
 * last thing standing between an unanswered dispute and a lost one. Plain
 * values only, for the reason DisputeOpened gives.
 */
class DisputeDueSoon extends Mailable implements ShouldQueue
{
    use KeepsTypedTextPlain, Queueable;

    public readonly string $reference;

    public readonly string $amount;

    public readonly string $reason;

    public readonly string $night;

    public readonly string $due;

    public readonly int $daysLeft;

    public readonly bool $drafted;

    public readonly string $link;

    public function __construct(Dispute $dispute)
    {
        $dispute->loadMissing(['order:id,reference', 'event:id,title', 'evidence:id,dispute_id']);

        $this->reference = (string) ($dispute->order->reference ?? '—');
        $this->amount = Money::of((int) $dispute->amount, (string) $dispute->currency)->format();
        $this->reason = Reasons::label($dispute->reason);
        $this->night = (string) ($dispute->event->title ?? 'an event');
        $this->due = (string) $dispute->evidence_due_at?->format('l j F Y, H:i \U\T\C');
        // Rounded up, so the reminder sent at the five-day mark says five.
        $this->daysLeft = max(0, (int) ceil(now()->diffInHours($dispute->evidence_due_at, false) / 24));
        $this->drafted = $dispute->evidence !== null;
        $this->link = DisputeResource::getUrl('view', ['record' => $dispute->id], panel: 'admin');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: ($this->daysLeft <= 1 ? 'A day' : $this->daysLeft.' days').' left to answer the chargeback on order '.$this->reference,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.dispute-due-soon');
    }
}
