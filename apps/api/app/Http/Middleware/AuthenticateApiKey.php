<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request from another system, holding one of an organization's keys.
 *
 * Deliberately separate from Sanctum: a key belongs to an organization, not to
 * a person, and it can do nothing a person's token can — no writing, no
 * refunding, no reading another organization. The routes behind it are read
 * only and scoped to the key's own organization, so the worst a leaked key can
 * do is what an export could, until somebody revokes it.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->bearerToken();
        $key = $plain === '' ? null : ApiKey::findUsable($plain);

        if ($key === null) {
            return response()->json([
                'message' => 'A valid API key is needed. Send it as "Authorization: Bearer mf_live_…"; a revoked key stops working at once.',
            ], 401);
        }

        // Once a minute is enough to say "still in use" on the settings
        // screen, and saves a write on every request of a busy sync.
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('api_key', $key);

        return $next($request);
    }
}
