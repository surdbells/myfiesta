<?php

namespace App\Services\Checkout;

use App\Models\AddOn;
use App\Models\TicketType;
use App\Support\Money;

/**
 * One thing on a quote, priced from the database.
 *
 * Either a ticket type or an add-on, never both and never neither — the same
 * rule the order_lines table holds as a check constraint. Which one it is
 * decides two things that matter: whether buying it mints a ticket, and
 * whether a discount code can touch it.
 */
final readonly class QuoteLine
{
    public function __construct(
        public ?TicketType $ticketType,
        public int $quantity,
        public Money $unitPrice,
        public Money $lineTotal,
        // This line's share of the order's discount. Kept per line because a
        // code can discount some ticket types and not others, and a refund
        // has to know what each ticket actually cost.
        public Money $discount,
        public ?AddOn $addOn = null,
    ) {}

    public function withDiscount(Money $discount): self
    {
        return new self(
            $this->ticketType,
            $this->quantity,
            $this->unitPrice,
            $this->lineTotal,
            $discount,
            $this->addOn,
        );
    }

    /** Whether this is a thing somebody walks through a door on. */
    public function isTicket(): bool
    {
        return $this->ticketType !== null;
    }

    /** What it is called, now, before the order snapshots it. */
    public function name(): string
    {
        return $this->ticketType?->name ?? $this->addOn?->name ?? '';
    }

    public function id(): string
    {
        return $this->ticketType?->id ?? $this->addOn?->id ?? '';
    }
}
