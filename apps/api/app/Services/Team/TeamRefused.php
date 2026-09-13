<?php

namespace App\Services\Team;

use RuntimeException;

/** A team change that will not be made, with the sentence to show. */
class TeamRefused extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
