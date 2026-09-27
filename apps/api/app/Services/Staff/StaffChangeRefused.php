<?php

namespace App\Services\Staff;

use RuntimeException;

/**
 * A change to who works here that will not be made, with the reason.
 *
 * A sentence rather than a code, because it is read by exactly one person:
 * whoever is at the admin or the terminal and needs to know what to do instead.
 */
class StaffChangeRefused extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
