<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The categories an event can be filed under.
 *
 * Served rather than copied into the console, so adding one is a change to
 * config/events.php and nothing else.
 */
class EventCategoryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(['data' => config('events.categories')])
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
