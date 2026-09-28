<?php

namespace App\Services\Events;

use RuntimeException;

/**
 * An organizer's review request that cannot go ahead, with the sentences that
 * say why.
 *
 * Carries every reason at once. "Add a ticket", fixed, then "the date has
 * passed", fixed, then "write a description" is three round trips for one
 * checklist.
 */
final class ReviewRefused extends RuntimeException
{
    /** @param  list<string>  $reasons */
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly array $reasons = [],
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return array_filter([
            'message' => $this->getMessage(),
            'reasons' => $this->reasons ?: null,
            'code' => $this->errorCode,
        ], fn ($value) => $value !== null);
    }
}
