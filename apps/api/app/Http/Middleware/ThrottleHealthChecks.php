<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `throttle`, for GET /api/health/ready, except that it gives way when the
 * cache it counts in is down.
 *
 * Requests are counted in Redis. When Redis is unreachable the ordinary
 * limiter throws before the check runs, and the uptime monitor gets a bare
 * 500 instead of the 503 that says the cache is what failed. So a request
 * that cannot be counted is answered uncounted: for as long as Redis is down,
 * which is also as long as the answer is 503. A limit that is reached is
 * still a 429.
 */
class ThrottleHealthChecks extends ThrottleRequests
{
    /**
     * @param  Request  $request
     * @param  array<int, object>  $limits
     * @return Response
     */
    protected function handleRequest($request, Closure $next, array $limits)
    {
        $answered = false;
        $response = null;

        try {
            return parent::handleRequest($request, function ($request) use ($next, &$answered, &$response) {
                $answered = true;

                return $response = $next($request);
            }, $limits);
        } catch (ThrottleRequestsException $e) {
            throw $e;
        } catch (Throwable $e) {
            // The check itself failed: that is not the limiter's to hide.
            if ($answered && $response === null) {
                throw $e;
            }

            // Counting failed after the check answered (the headers), or
            // before it ran. Either way the answer is the check's.
            return $response ?? $next($request);
        }
    }
}
