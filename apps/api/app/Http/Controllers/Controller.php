<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Laravel no longer includes this by default, and every organizer route
     * depends on it: authorisation here is against the organization owning the
     * record, not against the token. Without the trait, $this->authorize()
     * fatals rather than denying — which fails open in the worst way, since a
     * 500 on an ownership check still means the check did not happen.
     */
    use AuthorizesRequests;
}
