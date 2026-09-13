<?php

namespace App\Services\Checkout;

use App\Models\TicketType;
use App\Support\Money;

/**
 * One ticket type on a quote, priced from the database.
 */
final readonly class QuoteLine
{
    public function __construct(
        public TicketType $ticketType,
        public int $quantity,
        public Money $unitPrice,
        public Money $lineTotal,
        // This line's share of the order's discount. Kept per line because a
        // code can discount some ticket types and not others, and a refund
        // has to know what each ticket actually cost.
        public Money $discount,
    ) {}

    public function withDiscount(Money $discount): self
    {
        return new self($this->ticketType, $this->quantity, $this->unitPrice, $this->lineTotal, $discount);
    }
}
