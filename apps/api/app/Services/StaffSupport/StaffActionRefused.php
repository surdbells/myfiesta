<?php

namespace App\Services\StaffSupport;

use RuntimeException;

/**
 * Something a member of staff asked for that will not be done.
 *
 * Every message is written for the person at the admin panel and shown to them
 * as-is: the role cannot do it, the ticket is already used, the account is the
 * one they are signed in with.
 */
class StaffActionRefused extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
