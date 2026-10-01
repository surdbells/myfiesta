<?php

namespace App\Mail;

use App\Mail\Concerns\KeepsTypedTextPlain;
use App\Mail\Concerns\RepliesReachSupport;
use App\Models\Order;
use App\Models\Refund;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Money went back on one of your orders, from the Stripe dashboard."
 *
 * To the people who can refund on the organization. Somebody refunded outside
 * the platform — often one of them, sometimes whoever else holds the Stripe or
 * Paystack login — and the platform has now written it down: the balance no
 * longer counts that money, and the order shows it.
 *
 * What it says about the tickets is the part that matters. When the whole
 * order went back, they have stopped working, and it says so. When only part
 * of it did, they all still work: which tickets the money was for is not
 * something the processor knows, and guessing would turn away somebody who
 * paid. So it asks the organizer to say, by replying — and warns them that
 * refunding those tickets here as well would send the money a second time.
 *
 * Also sent when myFiesta support returned the money themselves, outside the
 * processor, and recorded it: an order paid with Klarna or Affirm longer ago
 * than the lender takes refunds back for. Then it says that instead.
 *
 * A reply reaches support (RepliesReachSupport), who can cancel the right
 * tickets. The buyer's name was typed at checkout, so it stays words
 * (KeepsTypedTextPlain).
 */
class RefundMadeElsewhere extends Mailable implements ShouldQueue
{
    use KeepsTypedTextPlain, Queueable, RepliesReachSupport, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly Refund $refund,
        public readonly bool $wholeOrder,
        public readonly string $processor,
        // Said when support returned the money outside the processor, and
        // recorded it (RefundService::recordMadeElsewhere's $how): then it
        // was not made in the processor's dashboard, and the email says so.
        public readonly ?string $how = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: $this->supportReplyTo(),
            subject: $this->how !== null
                ? "myFiesta support returned money on order {$this->order->reference}"
                : "A refund on order {$this->order->reference} was made in {$this->processor}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.refund-made-elsewhere',
            with: [
                'order' => $this->order,
                'event' => $this->order->event,
                'processor' => $this->processor,
                'how' => $this->how,
                'whole' => $this->wholeOrder,
                'amount' => (new Money((int) $this->refund->amount, $this->refund->currency))->format(),
                'url' => rtrim((string) config('app.console_url'), '/').'/events/'.$this->order->event_id.'/orders',
            ],
        );
    }
}
