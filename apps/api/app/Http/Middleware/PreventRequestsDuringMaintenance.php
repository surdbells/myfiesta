<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as Middleware;
use Illuminate\Http\Request;
use Throwable;

/**
 * Maintenance mode, as the framework has it, except for the readiness check
 * when nobody can tell whether the platform is down.
 *
 * Whether it is down is kept in Redis (APP_MAINTENANCE_DRIVER=cache), so that
 * `php artisan down` holds for every API container. With Redis unreachable
 * that cannot be read, and every request fails with it — as it should: a
 * switch-over under way must not start taking webhooks because the one place
 * that says it is under way stopped answering. But GET /api/health/ready is
 * how the operator hears that Redis is down, and a 500 before it runs says
 * nothing. So it alone goes through, to answer 503 naming the cache. When the
 * answer can be read, it is refused during maintenance like everything else.
 *
 * /up needs nothing here: the framework never checks maintenance for it.
 */
class PreventRequestsDuringMaintenance extends Middleware
{
    public const READINESS = 'api/health/ready';

    public function handle($request, Closure $next)
    {
        if ($request instanceof Request && $request->is(self::READINESS)) {
            try {
                $this->app->maintenanceMode()->active();
            } catch (Throwable) {
                return $next($request);
            }
        }

        return parent::handle($request, $next);
    }
}
