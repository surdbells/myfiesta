<?php

namespace App\Http\Resources;

use App\Http\Resources\Extensions\TicketExtras;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Receipts\Receipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin Ticket */
class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status,
            'holder_name' => $this->holder_name,
            'checked_in_at' => $this->checked_in_at,
            'type' => $this->whenLoaded('ticketType', fn () => $this->ticketType->name),
            'event' => $this->whenLoaded('event', fn () => [
                'slug' => $this->event->slug,
                'title' => $this->event->title,
                'starts_at' => $this->event->starts_at,
                'timezone' => $this->event->timezone,
                'city' => $this->event->city,
                // Where to go, as the ticket page on the site says it. The
                // phone said only the city — "Where: Toronto" — at a door.
                'venue' => $this->event->relationLoaded('venue') && $this->event->venue !== null ? [
                    'name' => $this->event->venue->name,
                    'address' => $this->event->venue->address_line,
                ] : null,
            ]),
            /*
             * The receipt for the order this ticket came from — only to the
             * account that bought it.
             *
             * A ticket handed on belongs to its new holder, and what the
             * buyer paid, and under which name, is not theirs to read. Null
             * for those, for comps, and wherever the order was not loaded.
             */
            'receipt' => $this->whenLoaded('order', fn () => $this->boughtBy($request->user())
                ? Receipt::for($this->order)->toArray()
                : null),

            // Perks, a friend-discount link and whether it can be sent on:
            // each feature's own field, from a class of its own
            // (TicketExtras), so none of them edits this.
            ...app(TicketExtras::class)->for($this->resource),
        ];
    }

    /**
     * Whether this account bought the order and holds this ticket from it.
     *
     * Not `order.user_id` alone. Nearly every order is placed without an
     * account — the web checkout sends no token and the app pays in the
     * system browser — so it stays null, and the tickets go to the account
     * for the buyer's address instead (TicketIssuer). Such an order is this
     * account's when it was placed under this account's address, or when the
     * ticket has never been handed on, which keeps it for an account that
     * has since moved to a new address. Either way the account must hold the
     * ticket: one passed to a friend has another holder, and a friend with
     * another address.
     */
    private function boughtBy(?User $user): bool
    {
        $order = $this->order;

        if ($order === null || $user === null || $this->owner_user_id !== $user->id) {
            return false;
        }

        if ($order->user_id !== null) {
            return $order->user_id === $user->id;
        }

        if (Str::lower((string) $order->buyer_email) === Str::lower((string) $user->email)) {
            return true;
        }

        // Read with the ticket where the list asked for it (withExists), and
        // asked here otherwise.
        $handedOn = $this->resource->hasAttribute('transfers_exists')
            ? (bool) $this->resource->getAttribute('transfers_exists')
            : $this->transfers()->exists();

        return ! $handedOn;
    }
}
