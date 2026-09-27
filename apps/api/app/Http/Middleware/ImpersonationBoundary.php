<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ImpersonationController;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Impersonation\Impersonation;
use App\Services\Impersonation\WhileImpersonating;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The edge of what myFiesta staff can do while acting as an organization.
 *
 * On the whole api group, so an endpoint added later is covered without
 * anybody remembering to list it here. It does nothing unless the request
 * authenticates with a staff session's token, and resolving the token is what
 * auth:sanctum was about to do anyway — the guard keeps the answer.
 *
 * For a staff session it:
 *
 * - refuses the endpoints WhileImpersonating names, before the controller
 *   runs, with a message that says why. The permissions behind most of them
 *   are withheld as well (see User::permissionsFor), so this is the clear
 *   answer rather than the only one;
 * - ends the session, and closes it for good, if the staff member lost the
 *   role that allowed it — a support agent moved off the team mid-session
 *   stops at their next click, not an hour later;
 * - records signing out as ending the session, so the trail has an end for
 *   it whichever button was pressed;
 * - writes `impersonation.request` for every request that could change
 *   something (anything but GET, HEAD and OPTIONS) and for every refusal,
 *   under the staff member's name. Most organizer endpoints keep no audit
 *   entry of their own — editing an event, adding a code, a ticket type or
 *   an image — and "everything you change is recorded under your name" has
 *   to hold for those too, not only for the ones a controller happens to
 *   record. Where a controller does record, both entries are written: this
 *   one says a request was made, that one what it did.
 */
class ImpersonationBoundary
{
    /**
     * Requests that end the session, and record that themselves as
     * impersonation.ended.
     */
    private const ENDS_THE_SESSION = [
        AuthController::class.'@logout',
        ImpersonationController::class.'@destroy',
    ];

    public function __construct(
        private readonly Impersonation $impersonation,
        private readonly Auditor $auditor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null || ! $this->usesTokens($request)) {
            return $next($request);
        }

        // The same guard auth:sanctum resolves, so this is the instance the
        // controller will see and costs no second lookup.
        $user = $request->user('sanctum');

        if (! $user instanceof User || $user->impersonationTokenId() === null) {
            return $next($request);
        }

        $session = $this->impersonation->forToken($user->currentAccessToken());

        if ($session === null) {
            return $this->problem(401, 'This staff session has ended. Start a new one from the admin panel.');
        }

        if (! $this->impersonation->mayImpersonate($user)) {
            $this->impersonation->end($session, 'staff_role_removed');

            return $this->problem(401, 'Your staff access has changed, so this staff session has ended.');
        }

        $action = $request->route()?->getActionName();

        if (($refusal = WhileImpersonating::refusalFor($action, $request, $user->platform_role)) !== null) {
            $this->record($request, $user, $session, $action, 403, refused: true);

            return $this->problem(403, $refusal);
        }

        $response = $next($request);

        if ($action === AuthController::class.'@logout' && $response->isSuccessful()) {
            $this->impersonation->end($session, 'signed_out', $user);
        } elseif (! $request->isMethodSafe() && ! in_array($action, self::ENDS_THE_SESSION, true)) {
            $this->record($request, $user, $session, $action, $response->getStatusCode());
        }

        return $response;
    }

    /**
     * One entry per request, against the organization acted for.
     *
     * What was asked and how it was answered, never what was sent: bodies
     * carry buyers' names and addresses, and the entry's job is to say who
     * did what, to which record — which the route and its identifiers do.
     * The actor is the staff member; Auditor adds the session.
     */
    private function record(Request $request, User $staff, ImpersonationSession $session, ?string $action, int $status, bool $refused = false): void
    {
        $route = $request->route();

        $this->auditor->record('impersonation.request', $session->organization, $staff, $session->organization_id, array_filter([
            'method' => $request->method(),
            'route' => $route?->uri(),
            'action' => $action,
            'parameters' => $route instanceof Route ? $this->identifiers($route) : [],
            'status' => $status,
            'refused' => $refused ?: null,
        ], fn ($value) => $value !== null && $value !== []));
    }

    /**
     * The route's parameters as they arrived, keeping only identifiers.
     *
     * Record ids are what somebody tracing an entry needs. Anything else in
     * a path — an invitation's token, a pass's secret — is a credential, and
     * is named but not kept.
     *
     * @return array<string, string>
     */
    private function identifiers(Route $route): array
    {
        return collect($route->originalParameters())
            ->map(fn ($value) => is_string($value) && (Str::isUuid($value) || ctype_digit($value)) ? $value : '[not recorded]')
            ->all();
    }

    /** Only routes that authenticate with a token can be reached with one. */
    private function usesTokens(Request $request): bool
    {
        return in_array('auth:sanctum', $request->route()?->gatherMiddleware() ?? [], true);
    }

    private function problem(int $status, string $detail): Response
    {
        return response()->json([
            'type' => 'https://myfiesta.ca/problems/not-while-acting-as-an-organization',
            'title' => 'Not while acting as an organization',
            'status' => $status,
            'detail' => $detail,
            // The console shows `message` from any 4xx; `detail` is the
            // problem+json field other clients read.
            'message' => $detail,
        ], $status, ['Content-Type' => 'application/problem+json']);
    }
}
