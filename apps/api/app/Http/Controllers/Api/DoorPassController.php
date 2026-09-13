<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DoorPass;
use App\Models\Event;
use App\Services\Door\DoorPasses;
use App\Services\Door\DoorPassRefused;
use Illuminate\Http\JsonResponse;

/**
 * The phone's side of a door pass: what it is for, and opening it.
 *
 * No account. The link is the credential, exactly once.
 */
class DoorPassController extends Controller
{
    public function __construct(private readonly DoorPasses $passes) {}

    /**
     * What the link opens, before it is opened.
     *
     * Opening is a separate tap rather than something the page does on load:
     * chat apps fetch links to draw previews, and a preview must not be the
     * thing that uses up the one claim.
     */
    public function show(string $secret): JsonResponse
    {
        $pass = DoorPass::findBySecret($secret);

        abort_if($pass === null, 404, 'This door link is not valid.');

        return response()->json([
            'label' => $pass->label,
            'state' => $pass->state(),
            'expires_at' => $pass->expires_at,
            'event' => $this->event($pass->event),
        ]);
    }

    public function claim(string $secret): JsonResponse
    {
        try {
            ['pass' => $pass, 'token' => $token] = $this->passes->claim($secret);
        } catch (DoorPassRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], $refused->status);
        }

        return response()->json([
            'token' => $token,
            'label' => $pass->label,
            'expires_at' => $pass->expires_at,
            'event' => $this->event($pass->event),
        ]);
    }

    /**
     * Enough to put the right night on screen, and the rule the door enforces.
     *
     * @return array<string, mixed>
     */
    private function event(Event $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'starts_at' => $event->starts_at,
            'ends_at' => $event->ends_at,
            'timezone' => $event->timezone,
            'venue' => $event->venue?->name,
            'city' => $event->venue?->city ?? $event->city,
            'min_age' => $event->min_age,
            'id_required' => (bool) $event->id_required,
        ];
    }
}
