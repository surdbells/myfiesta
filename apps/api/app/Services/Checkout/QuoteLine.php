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
    ) {}
}
