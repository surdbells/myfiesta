<?php

namespace App\Services\Integrations;

use App\Models\Event;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Ticket;
use App\Services\Checkout\TaxLine;
use App\Services\Receipts\Receipt;
use App\Services\Settings\SellerOfRecord;

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
            // Each tax on its own, as the receipt shows it: GST and QST side
            // by side in Quebec, and the tax on the service charge where that
            // is taxed. Added beside the figures above rather than changing
            // them — `tax` is still the tickets' tax alone and
            // `service_charge` still has its own tax inside it — so nothing
            // an integration already adds up moves.
            'tax_lines' => array_map(fn (TaxLine $line) => [
                'name' => $line->name,
                'rate' => $line->percent(),
                'on' => $line->on,
                'included' => $line->inclusive,
                'amount' => $money($line->amount->amount),
            ], Receipt::taxes($order)),
            // How much of `service_charge` is tax.
            'service_charge_tax' => $money((int) $order->service_charge_tax_amount),
            // Who sold the tickets, in law, and so who files their tax: the
            // organizer, or the platform on their behalf.
            'seller_of_record' => (SellerOfRecord::tryFrom((string) $order->seller_of_record) ?? SellerOfRecord::Organizer)->value,
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
