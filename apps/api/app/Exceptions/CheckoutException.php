<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Something the buyer can understand and act on.
 *
 * Messages here are shown as-is, so they are written for a person at a checkout
 * rather than for a log: what went wrong, and what to do about it.
 */
class CheckoutException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function soldOut(string $ticketName, int $remaining): self
    {
        return new self($remaining === 0
            ? "{$ticketName} has sold out."
            : "Only {$remaining} of {$ticketName} left.");
    }
}
