<?php

namespace App\Services\Resale;

use RuntimeException;

/** Why a ticket cannot be handed back, in words its holder can read. */
class ResaleRefused extends RuntimeException
{
    public int $status = 422;
}
