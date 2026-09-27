<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Operations\Readiness;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/health/ready — whether this platform can take an order and see it
 * through, for an uptime monitor to watch.
 *
 * 200 when every part answers, 503 when any does not, with which one and a
 * fixed sentence about it. Never an exception's message, a host name or a
 * count of anything a stranger has no business knowing: anybody can ask this.
 *
 * Not what a load balancer should route on. That is /up, which only asks
 * whether this process is serving; a queue worker being down is a reason to
 * page somebody, not to take the site away from the people buying on it.
 */
class ReadinessController extends Controller
{
    public function __invoke(Readiness $readiness): JsonResponse
    {
        $checks = $readiness->run();
        $healthy = Readiness::healthy($checks);

        return response()
            ->json(['status' => $healthy ? 'ok' : 'failing', 'checks' => $checks], $healthy ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
