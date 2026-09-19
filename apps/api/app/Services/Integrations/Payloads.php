<?php

namespace App\Services\Integrations;

use App\Models\Event;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Ticket;

/**
 * What another system is told about an order, an event, or a person at a door.
 *
 * One shape, used by both directions — the webhook we push and the key-read
 * API somebody pulls — so an integration built against one reads the other
 * without translation.
 *
 * What is never in it: a ticket code. A code is the thing that opens a door,
 * and an integration is an export by another name. The guest list and the
 * spreadsheet have never carried codes, and a CRM is not somewhere that rule
 * gets to lapse.
 */
class Payloads
{
    /** @return array<string, mixed> */
    public function order(Order $order): array
    {
        $order->loadMissing(['event', 'lines']);

        $money = fn (int $amount) => ['amount' => $amount, 'currency' => $order->currency];

        return [
            'reference' => $order->reference,
            'status' => $order->status,
            // Online or at a door, and how a door sale was paid. An accounting
            // system reconciling against a bank feed needs to know which of
            // these money it should expect to find there.
            'channel' => $order->channel,
            'payment_method' => $order->payment_method,
            'event' => $this->eventRef($order->event),
            // Null for a walk-up who gave nothing, which is allowed only at a
            // door. Everything downstream should expect it.
            'buyer' => [
                'name' => $order->buyer_name,
                'email' => $order->buyer_email,
            ],
            'lines' => $order->lines->map(fn (OrderLine $line) => [
                'kind' => $line->isTicket() ? 'ticket' : 'add_on',
                'name' => $line->name,
                'quantity' => $line->quantity,
                'unit_price' => $money((int) $line->unit_price_amount),
                'line_total' => $money((int) $line->line_total_amount),
                'discount' => $money((int) $line->discount_amount),
            ])->values()->all(),
            'subtotal' => $money((int) $order->subtotal_amount),
            'discount' => $money((int) $order->discount_amount),
            'tax' => $money((int) $order->tax_amount),
            'service_charge' => $money((int) $order->service_charge_amount),
            'total' => $money((int) $order->total_amount),
            // What the organizer earned, the figure a finance system cares
            // about: the ticket price after their own discount, net of tax.
            'net_revenue' => $money((int) $order->net_revenue_amount),
            // The discount code the buyer typed, never a ticket's.
            'discount_code' => $order->code?->code,
            'ref' => $order->ref_slug,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function event(Event $event): array
    {
        return [
            'id' => $event->id,
            'slug' => $event->slug,
            'title' => $event->title,
            'status' => $event->status,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'timezone' => $event->timezone,
            'city' => $event->city,
            'country' => $event->country,
            'currency' => $event->currency,
        ];
    }

    /**
     * One person with a ticket, as a door or a guest list sees them.
     *
     * @return array<string, mixed>
     */
    public function attendee(Ticket $ticket): array
    {
        $ticket->loadMissing(['ticketType:id,name', 'order:id,reference']);

        return [
            'id' => $ticket->id,
            'name' => $ticket->holder_name,
            'email' => $ticket->owner_email,
            'ticket_type' => $ticket->ticketType?->name,
            'admits' => $ticket->admits,
            'admitted' => $ticket->admitted_count,
            'status' => $ticket->status,
            'checked_in_at' => $ticket->checked_in_at?->toIso8601String(),
            'order_reference' => $ticket->order?->reference,
        ];
    }

    /** @return array<string, string|null> */
    private function eventRef(?Event $event): array
    {
        return [
            'id' => $event?->id,
            'slug' => $event?->slug,
            'title' => $event?->title,
            'starts_at' => $event?->starts_at?->toIso8601String(),
        ];
    }
}
