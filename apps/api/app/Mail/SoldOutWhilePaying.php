<?php

namespace App\Mail;

use App\Mail\Concerns\KeepsTypedTextPlain;
use App\Mail\Concerns\RepliesReachSupport;
use App\Models\Event;
use App\Models\Order;
use App\Models\Refund;
use App\Services\Checkout\TurnedAway;
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
 * Or the night itself was off by then — cancelled, over, taken down, or its
 * organizer's sales stopped — and it says that instead. A suspension is told
 * the way checkout tells it, as an organizer not selling on myFiesta: why is
 * between the platform and the organizer, not something to put in a buyer's
 * inbox. Which one is read from the refund's reason, where
 * fulfilment wrote it when it decided (TurnedAway), rather than from the
 * event as it is when this is sent: an event can be restored between the two,
 * and the buyer is owed the reason their money came back, not today's news.
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

    /** Why nothing was issued, as the refund recorded it. */
    public function why(): TurnedAway
    {
        return TurnedAway::fromRefundReason($this->refund->reason);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: $this->supportReplyTo(),
            subject: "Your money for {$this->event()->title} is on its way back",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.sold-out-while-paying',
            with: [
                'order' => $this->order,
                'event' => $this->event(),
                'why' => $this->why()->value,
                'amount' => (new Money((int) $this->refund->amount, $this->refund->currency))->format(),
            ],
        );
    }

    /**
     * Deleted or not. An event taken away entirely is still the one the
     * money was for, and still has the title the buyer knows it by.
     */
    private function event(): Event
    {
        /** @var Event */
        return Event::withTrashed()->findOrFail($this->order->event_id);
    }
}
