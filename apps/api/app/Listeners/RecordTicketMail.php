<?php

namespace App\Listeners;

use App\Models\Order;
use App\Models\Ticket;
use App\Services\Disputes\ActivityLog;
use Illuminate\Mail\Events\MessageSent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Every email about an order or a ticket, written into the ticket history as
 * it leaves.
 *
 * Heard from the mailer itself (MessageSent) rather than at each place that
 * sends one, so a new email about an order is in the history without anybody
 * remembering to put it there, and so what is written is what actually went:
 * the address it went to and the id the mail provider gave it, not what a
 * caller meant to send. Which order or tickets it was about is read from the
 * email's own data — its order, its ticket, its list of tickets.
 *
 * Found by Laravel's listener discovery. It must never throw: this runs after
 * the email has gone, and a failure here would fail the job that sent it, and
 * the queue would send it again.
 */
class RecordTicketMail
{
    public function __construct(private readonly ActivityLog $log) {}

    public function handle(MessageSent $event): void
    {
        rescue(function () use ($event) {
            $data = $event->data;

            $order = ($data['order'] ?? null) instanceof Order ? $data['order'] : null;

            $tickets = collect(is_iterable($data['tickets'] ?? null) ? $data['tickets'] : [])
                ->push($data['ticket'] ?? null)
                ->filter(fn ($ticket) => $ticket instanceof Ticket)
                ->unique(fn (Ticket $ticket) => $ticket->id)
                ->values();

            if ($order === null && $tickets->isEmpty()) {
                return;
            }

            $message = $event->sent->getOriginalMessage();

            if (! $message instanceof Email) {
                return;
            }

            $this->log->emailed(
                order: $order,
                tickets: $tickets,
                mailable: $data['__laravel_mailable'] ?? $data['__laravel_notification'] ?? null,
                recipients: implode(', ', array_map(fn (Address $to) => $to->getAddress(), $message->getTo())),
                messageId: $event->sent->getMessageId(),
                subject: $message->getSubject(),
            );
        });
    }
}
