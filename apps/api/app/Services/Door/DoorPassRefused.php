<?php

namespace App\Services\Door;

use RuntimeException;

/** A door pass that cannot be made or opened, with the reason to show. */
class DoorPassRefused extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function because(string $message, int $status = 422): self
    {
        return new self($message, $status);
    }
}
