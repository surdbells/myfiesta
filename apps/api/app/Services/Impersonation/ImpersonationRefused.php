<?php

namespace App\Services\Impersonation;

use RuntimeException;

/** Starting or opening a staff session was refused, in a sentence meant to be shown. */
class ImpersonationRefused extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 403)
    {
        parent::__construct($message);
    }

    public static function because(string $message, int $status = 403): self
    {
        return new self($message, $status);
    }
}
