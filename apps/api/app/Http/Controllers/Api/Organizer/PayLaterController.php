<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Audit\Auditor;
use App\Services\Payments\PayLater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The organizer's opt-in to letting buyers pay for a night later, with
 * Klarna or Affirm, from the event's Settings.
 *
 * Its own route rather than a field of the event's edit, because it is a
 * decision about money, not about the listing: the console asks first, naming
 * what the lenders charge over a card, and the organizer pays that difference
 * on every order paid this way. Editing the event is the permission it needs,
 * as with the other settings on that page. It does not change what buyers see
 * on the listing, so it is not refused while staff review the night.
 */
class PayLaterController extends Controller
{
    public function show(Event $event, PayLater $payLater): JsonResponse
    {
        $this->authorize('viewInConsole', $event);

        return response()->json($this->setting($event, $payLater));
    }

    public function update(Request $request, Event $event, PayLater $payLater, Auditor $auditor): JsonResponse
    {
        $this->authorize('viewInConsole', $event);
        $this->authorize('update', $event);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $data['enabled'];

        // Turning it off is always allowed: a night offered nothing can
        // still say it wants nothing.
        if ($enabled && ! $payLater->available($event)) {
            return response()->json([
                'message' => strtoupper((string) $event->currency) !== 'CAD'
                    ? 'Paying later is only for nights priced in Canadian dollars.'
                    : 'Paying later is switched off on myFiesta at the moment.',
            ], 422);
        }

        if ((bool) $event->pay_later_enabled !== $enabled) {
            $event->forceFill(['pay_later_enabled' => $enabled])->save();

            $auditor->record($enabled ? 'event.pay_later_on' : 'event.pay_later_off', $event, $request->user(), metadata: [
                'title' => $event->title,
            ]);
        }

        return response()->json($this->setting($event->refresh(), $payLater));
    }

    /**
     * The opt-in as the console shows it, with what the organizer is told
     * before they change it.
     *
     * @return array<string, mixed>
     */
    private function setting(Event $event, PayLater $payLater): array
    {
        return [
            'enabled' => (bool) $event->pay_later_enabled,
            'available' => $payLater->available($event),
            'offered_now' => $payLater->offeredFor($event),
            'max_days_before_event' => $payLater->maxDaysBeforeEvent(),
            'currency' => strtoupper((string) $event->currency),
            'fees' => $payLater->fees(),
        ];
    }
}
