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
 * "A chargeback on order K7QX3M9A: answer by Friday", to Admin and Finance.
 *
 * Staff only — never the buyer, and never the organizer, who hears through
 * their own channel (the order.disputed webhook). Says what staff need to
 * decide whether to open it now: how much, why, for which night, how long is
 * left, and how much of the evidence the records already hold. The buyer is
 * named by the order's reference and nothing else; the page has the rest.
 *
 * Built from what was true when the dispute opened, as plain values, so the
 * queue carries no model — and so the listener that writes a buyer's emails
 * into their ticket history (RecordTicketMail) finds no order in it and leaves
 * this out of the buyer's history, where it does not belong.
 */
class DisputeOpened extends Mailable implements ShouldQueue
{
    use KeepsTypedTextPlain, Queueable;

    public readonly string $reference;

    public readonly string $amount;

    public readonly string $reason;

    public readonly string $claim;

    public readonly string $night;

    public readonly string $organizer;

    public readonly string $processor;

    public readonly ?string $due;

    public readonly ?int $daysLeft;

    public readonly ?int $found;

    public readonly int $checks;

    public readonly int $cautions;

    public readonly string $link;

    public function __construct(Dispute $dispute)
    {
        $dispute->loadMissing(['order:id,reference', 'event:id,title', 'organization:id,name', 'evidence']);

        $this->reference = (string) ($dispute->order->reference ?? '—');
        $this->amount = Money::of((int) $dispute->amount, (string) $dispute->currency)->format();
        $this->reason = Reasons::label($dispute->reason);
        $this->claim = Reasons::claim($dispute->reason);
        $this->night = (string) ($dispute->event->title ?? 'an event');
        $this->organizer = (string) ($dispute->organization->name ?? 'an organizer');
        $this->processor = ucfirst((string) $dispute->gateway);
        $this->due = $dispute->evidence_due_at?->format('l j F Y, H:i \U\T\C');
        $this->daysLeft = $dispute->evidence_due_at === null ? null : max(0, (int) ceil(now()->diffInHours($dispute->evidence_due_at, false) / 24));

        $evidence = $dispute->evidence;
        $this->found = $evidence?->found();
        $this->checks = count($evidence->checklist ?? []);
        $this->cautions = count($evidence->cautions ?? []);
        $this->link = DisputeResource::getUrl('view', ['record' => $dispute->id], panel: 'admin');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Chargeback on order {$this->reference}: {$this->amount}".($this->due ? ', answer by '.explode(',', $this->due)[0] : ''),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.dispute-opened');
    }
}
