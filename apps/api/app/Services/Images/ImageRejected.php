<?php

namespace App\Services\Images;

use RuntimeException;

/**
 * A file the platform will not publish.
 *
 * Every message is written to be shown to whoever uploaded it, so it says what
 * to do differently rather than what went wrong internally.
 */
class ImageRejected extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
