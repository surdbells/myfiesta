<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Audit\Auditor;
use App\Services\Integrations\UnsafeWebhookTarget;
use App\Services\Integrations\Webhooks;
use App\Services\Integrations\WebhookTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Pointing an organization's data at another system.
 *
 * Webhooks go out, keys let something read in. Both send buyers' names and
 * addresses somewhere this platform does not control for as long as they stay
 * switched on, so both are owner-only, both are recorded in the audit trail
 * when they are made and when they are taken away, and neither ever carries a
 * ticket code.
 *
 * Secrets are shown exactly once. A webhook's signing secret and an API key's
 * token appear in the response that creates them and never again — the list
 * shows a key's last four characters and nothing of a secret. Lost means
 * replaced, which is the only version of this where a leaked screen of the
 * console is not a leaked credential.
 */
class IntegrationController extends Controller
{
    /** Enough for a CRM, an accounting system and a Zapier; short of a firehose. */
    public const MAX_ENDPOINTS = 5;

    public const MAX_KEYS = 10;

    public function __construct(
        private readonly Auditor $auditor,
        private readonly WebhookTarget $target,
        private readonly Webhooks $webhooks,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        return response()->json([
            'events' => WebhookEndpoint::EVENTS,
            'endpoints' => WebhookEndpoint::query()
                ->where('organization_id', $organization->id)
                ->orderBy('created_at')
                ->get()
                ->map(fn (WebhookEndpoint $endpoint) => $this->presentEndpoint($endpoint))
                ->values(),
            'keys' => ApiKey::query()
                ->where('organization_id', $organization->id)
                ->whereNull('revoked_at')
                ->orderBy('created_at')
                ->get()
                ->map(fn (ApiKey $key) => $this->presentKey($key))
                ->values(),
        ]);
    }

    // --- webhooks -------------------------------------------------------------

    public function storeEndpoint(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookEndpoint::EVENTS)],
            'description' => ['nullable', 'string', 'max:120'],
        ]);

        if (WebhookEndpoint::where('organization_id', $organization->id)->count() >= self::MAX_ENDPOINTS) {
            return response()->json([
                'message' => 'An organization can have up to '.self::MAX_ENDPOINTS.' webhook endpoints. Remove one to add another.',
            ], 422);
        }

        // Checked when it is saved, so a mistake is said while somebody is
        // looking at the form — and again on every delivery, because what a
        // name resolves to can change.
        try {
            $this->target->check($data['url']);
        } catch (UnsafeWebhookTarget $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['url' => [$e->getMessage()]]], 422);
        }

        $secret = 'whsec_'.Str::random(40);

        $endpoint = WebhookEndpoint::create([
            'organization_id' => $organization->id,
            'url' => $data['url'],
            'secret' => $secret,
            'events' => array_values(array_unique($data['events'])),
            'description' => $data['description'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $this->auditor->record('webhook.created', $endpoint, $request->user(), $organization->id, metadata: [
            'endpoint_id' => $endpoint->id,
            'host' => parse_url($endpoint->url, PHP_URL_HOST),
            'events' => $endpoint->events,
        ]);

        return response()->json([
            'data' => $this->presentEndpoint($endpoint),
            // Once. Not stored anywhere it can be read back from the API.
            'secret' => $secret,
        ], 201);
    }

    public function updateEndpoint(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        $organization = $this->organization($request);

        abort_unless($endpoint->organization_id === $organization->id, 404);

        $data = $request->validate([
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookEndpoint::EVENTS)],
            'description' => ['sometimes', 'nullable', 'string', 'max:120'],
            // Switching back on clears the reason and the count: somebody has
            // looked at it, which is the point of having turned it off.
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $changes = array_intersect_key($data, array_flip(['description']));

        if (isset($data['events'])) {
            $changes['events'] = array_values(array_unique($data['events']));
        }

        if (array_key_exists('enabled', $data)) {
            $changes += $data['enabled']
                ? ['disabled_at' => null, 'disabled_reason' => null, 'consecutive_failures' => 0]
                : ['disabled_at' => now(), 'disabled_reason' => 'Turned off by hand.'];
        }

        $endpoint->update($changes);

        return response()->json(['data' => $this->presentEndpoint($endpoint->fresh())]);
    }

    public function destroyEndpoint(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        $organization = $this->organization($request);

        abort_unless($endpoint->organization_id === $organization->id, 404);

        $this->auditor->record('webhook.removed', $endpoint, $request->user(), $organization->id, metadata: [
            'endpoint_id' => $endpoint->id,
            'host' => parse_url($endpoint->url, PHP_URL_HOST),
        ]);

        $endpoint->delete();

        return response()->json(['message' => 'Removed. Nothing more will be sent there.']);
    }

    /**
     * Send a harmless example, so somebody wiring up a receiver can see one
     * arrive without waiting for a real sale.
     */
    public function test(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        $organization = $this->organization($request);

        abort_unless($endpoint->organization_id === $organization->id, 404);

        $delivery = $this->webhooks->queue($endpoint, 'ping', [
            'message' => 'A test from myFiesta. Nothing happened; this is what a delivery looks like.',
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
        ]);

        return response()->json(['data' => $this->presentDelivery($delivery->fresh())], 201);
    }

    /** What was sent and what came back — the first thing anybody debugging a receiver needs. */
    public function deliveries(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        $organization = $this->organization($request);

        abort_unless($endpoint->organization_id === $organization->id, 404);

        return response()->json([
            'data' => $endpoint->deliveries()
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (WebhookDelivery $delivery) => $this->presentDelivery($delivery))
                ->values(),
        ]);
    }

    // --- keys -----------------------------------------------------------------

    public function storeKey(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $data = $request->validate([
            // Named for where it lives, so the list says what revoking it breaks.
            'name' => ['required', 'string', 'max:80'],
        ]);

        if (ApiKey::where('organization_id', $organization->id)->whereNull('revoked_at')->count() >= self::MAX_KEYS) {
            return response()->json([
                'message' => 'An organization can have up to '.self::MAX_KEYS.' keys. Revoke one you no longer use.',
            ], 422);
        }

        ['key' => $key, 'plain' => $plain] = ApiKey::issue($organization, $data['name'], $request->user());

        $this->auditor->record('api_key.created', $key, $request->user(), $organization->id, metadata: [
            'key_id' => $key->id,
            'name' => $key->name,
            'last_four' => $key->last_four,
        ]);

        return response()->json([
            'data' => $this->presentKey($key),
            // Once. Only the hash is kept.
            'key' => $plain,
        ], 201);
    }

    public function revokeKey(Request $request, ApiKey $key): JsonResponse
    {
        $organization = $this->organization($request);

        abort_unless($key->organization_id === $organization->id, 404);

        $key->update(['revoked_at' => now()]);

        $this->auditor->record('api_key.revoked', $key, $request->user(), $organization->id, metadata: [
            'key_id' => $key->id,
            'name' => $key->name,
            'last_four' => $key->last_four,
        ]);

        return response()->json(['message' => 'Revoked. Anything using it stops working now.']);
    }

    // --- shapes ---------------------------------------------------------------

    /**
     * The selected organization, for an owner.
     *
     * Refused outright for anybody else — even reading: the list names where
     * the organization's data is being sent, which is not a thing to show a
     * member who could not change it.
     */
    private function organization(Request $request): Organization
    {
        $memberships = $request->user()->organizations()->get();
        $asked = $request->header('X-Organization');

        $organization = $asked ? $memberships->firstWhere('id', $asked) : $memberships->first();

        abort_if($organization === null, 403, 'No organization.');

        if (! $request->user()->hasPermissionIn($organization->id, Permission::IntegrationsManage)) {
            throw new AccessDeniedHttpException('Only an owner can connect other systems.');
        }

        return $organization;
    }

    /** @return array<string, mixed> */
    private function presentEndpoint(WebhookEndpoint $endpoint): array
    {
        $last = $endpoint->deliveries()->latest()->first();

        return [
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'description' => $endpoint->description,
            'enabled' => $endpoint->isActive(),
            'disabled_reason' => $endpoint->disabled_reason,
            'consecutive_failures' => $endpoint->consecutive_failures,
            // Enough to tell at a glance whether it is working.
            'last_delivery' => $last ? [
                'status' => $last->status,
                'event' => $last->event,
                'response_status' => $last->response_status,
                'at' => $last->created_at?->toIso8601String(),
            ] : null,
            'created_at' => $endpoint->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentKey(ApiKey $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'last_four' => $key->last_four,
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'created_at' => $key->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentDelivery(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'event' => $delivery->event,
            'status' => $delivery->status,
            'attempts' => $delivery->attempts,
            'response_status' => $delivery->response_status,
            'response_excerpt' => $delivery->response_excerpt,
            'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String(),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'created_at' => $delivery->created_at?->toIso8601String(),
        ];
    }
}
