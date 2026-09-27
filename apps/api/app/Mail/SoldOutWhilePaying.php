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
 * "It sold out while you were paying, and your money is on its way back."
 *
 * Somebody paid, and by the time the payment reached us the last places had
 * gone to somebody else. They will see a charge, and without this they would
 * see nothing else: no tickets, and an order page that has moved on. So it
 * says what happened, how much is coming back, and that there is nothing they
 * need to do.
 *
 * Sent once the processor has accepted the refund, and not before — "on its
 * way back" has to be true when it is read. A refund that got no answer is
 * sent when the processor confirms it, which may be minutes later; one the
 * processor refused is a person's job to chase, and they are told about it
 * (see RefundService).
 *
 * A reply reaches the support inbox (RepliesReachSupport): somebody charged for
 * nothing who wants a person should get one. The buyer's name was typed by
 * whoever started the checkout, so it stays words (KeepsTypedTextPlain).
 */
class SoldOutWhilePaying extends Mailable implements ShouldQueue
{
    use KeepsTypedTextPlain, Queueable, RepliesReachSupport, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly Refund $refund,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: $this->supportReplyTo(),
            subject: "Your money for {$this->order->event->title} is on its way back",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.sold-out-while-paying',
            with: [
                'order' => $this->order,
                'event' => $this->order->event,
                'amount' => (new Money((int) $this->refund->amount, $this->refund->currency))->format(),
            ],
        );
    }
}
