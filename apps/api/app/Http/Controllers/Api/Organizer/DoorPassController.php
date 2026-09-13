<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\DoorPass;
use App\Models\Event;
use App\Services\Door\DoorPasses;
use App\Services\Door\DoorPassRefused;
use App\Services\Tickets\QrEncoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The door links an event has handed out.
 *
 * The link itself is returned once, when the pass is made, and never again:
 * only its hash is kept. Somebody who lost it makes another.
 */
class DoorPassController extends Controller
{
    public function __construct(
        private readonly DoorPasses $passes,
        private readonly QrEncoder $qr,
    ) {}

    public function index(Event $event): JsonResponse
    {
        $this->authorize('issueDoorPasses', $event);

        $passes = DoorPass::query()
            ->where('event_id', $event->id)
            ->with(['token', 'issuer'])
            ->withCount(['scans', 'scans as admitted_count' => fn ($query) => $query->where('admitted', '>', 0)])
            ->latest()
            // A night rarely needs more than a handful of doors; a festival
            // weekend a few dozen. Past that the older ones are history.
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $passes->map(fn (DoorPass $pass) => $this->present($pass))->values(),
            'expires_at' => $this->passes->expiryFor($event),
        ]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $this->authorize('issueDoorPasses', $event);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
        ], [
            'label.required' => 'Name the pass after the door or the person, so you can tell them apart later.',
        ]);

        try {
            ['pass' => $pass, 'secret' => $secret] = $this->passes->issue($event, $request->user(), $data['label']);
        } catch (DoorPassRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], $refused->status);
        }

        $link = rtrim((string) config('app.console_url'), '/').'/door-pass/'.$secret;

        return response()->json([
            'data' => $this->present($pass->load(['token', 'issuer'])->loadCount(['scans', 'scans as admitted_count' => fn ($query) => $query->where('admitted', '>', 0)])),
            // Once. Only the hash is stored.
            'link' => $link,
            // For the phone to scan off the organizer's screen, which beats
            // typing a link or finding each other in a chat at 10pm.
            'qr' => $this->qr->dataUri($link, 240, 'Door pass for '.$pass->label),
            'message' => 'Door pass made. Send the link to one phone.',
        ], 201);
    }

    public function destroy(Request $request, Event $event, DoorPass $pass): JsonResponse
    {
        $this->authorize('issueDoorPasses', $event);

        abort_unless($pass->event_id === $event->id, 404);

        if ($pass->revoked_at === null) {
            $this->passes->revoke($pass, $request->user());
        }

        return response()->json(['message' => "“{$pass->label}” can no longer scan."]);
    }

    /** @return array<string, mixed> */
    private function present(DoorPass $pass): array
    {
        return [
            'id' => $pass->id,
            'label' => $pass->label,
            'state' => $pass->state(),
            'issued_by' => $pass->issuer?->name,
            'created_at' => $pass->created_at,
            'claimed_at' => $pass->claimed_at,
            'last_used_at' => $pass->token?->last_used_at,
            'expires_at' => $pass->expires_at,
            'revoked_at' => $pass->revoked_at,
            'scans' => (int) $pass->scans_count,
            'admitted_scans' => (int) $pass->admitted_count,
        ];
    }
}
