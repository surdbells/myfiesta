<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\Code;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Discount and promoter codes.
 *
 * One object covers both, because in nightlife the discount code *is* the
 * promoter's attribution — it is how they prove they drove the sale. A code
 * may discount, attribute, or do both.
 */
class CodeController extends Controller
{
    public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manageCodes', $event);

        $codes = Code::query()
            ->where('organization_id', $event->organization_id)
            ->where(fn ($q) => $q->whereNull('event_id')->orWhere('event_id', $event->id))
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $codes->map(fn (Code $code) => $this->present($code, $event))->values(),
        ]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manageCodes', $event);

        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:64', 'alpha_dash',
                // Unique within the organization, case-insensitively — CODE and
                // code being different codes would be a support ticket a week.
                Rule::unique('codes')
                    ->where('organization_id', $event->organization_id)
                    ->whereNull('deleted_at'),
            ],
            'label' => ['nullable', 'string', 'max:120'],
            'discount_type' => ['nullable', 'in:percentage,fixed', 'required_with:discount_value'],
            // Percentage in basis points, fixed in minor units. Both integers,
            // because a rate that multiplies money must not be a float.
            //
            // Required alongside the type. A percentage code with no percentage
            // used to be accepted — the check constraint meant to stop it was
            // defeated by NULL comparing to unknown rather than false — and then
            // returned a 500 to every buyer who typed it.
            'discount_value' => ['nullable', 'integer', 'min:1', 'required_with:discount_type'],
            'promoter_name' => ['nullable', 'string', 'max:120'],
            'ref_slug' => ['nullable', 'string', 'max:64', 'alpha_dash'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'max_per_customer' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'event_scoped' => ['nullable', 'boolean'],
        ], [
            // The defaults here read as if somebody else claimed a username.
            'code.unique' => 'You already have a code with that name.',
            'discount_value.required_with' => 'Say how much comes off.',
            'discount_type.required_with' => 'Say whether that is a percentage or an amount.',
        ]);

        // A code has to do something. The database enforces this too, but a
        // constraint violation is not a sentence anyone wants to read.
        if (blank($data['discount_type'] ?? null) && blank($data['ref_slug'] ?? null)) {
            return response()->json([
                'message' => 'A code needs either a discount or a tracking slug — otherwise it does nothing.',
            ], 422);
        }

        if (($data['discount_type'] ?? null) === 'percentage' && $data['discount_value'] > 10000) {
            return response()->json([
                'message' => 'A percentage cannot exceed 100%.',
            ], 422);
        }

        $code = Code::create([
            'organization_id' => $event->organization_id,
            // Scoped to this event unless explicitly made organization-wide.
            'event_id' => ($data['event_scoped'] ?? true) ? $event->id : null,
            'code' => $data['code'],
            'label' => $data['label'] ?? null,
            'discount_type' => $data['discount_type'] ?? null,
            'discount_value' => $data['discount_value'] ?? null,
            // Fixed amounts carry a currency and cannot cross into an event
            // priced in another; percentages travel freely and must not.
            'discount_currency' => ($data['discount_type'] ?? null) === 'fixed'
                ? $event->currency
                : null,
            'ref_slug' => $data['ref_slug'] ?? null,
            'promoter_name' => $data['promoter_name'] ?? null,
            'max_redemptions' => $data['max_redemptions'] ?? null,
            'max_per_customer' => $data['max_per_customer'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'is_active' => true,
        ]);

        // Refreshed so the response carries what the database actually holds.
        // redemption_count defaults to 0 there and is absent from the model we
        // just built, so without this a new code reports a null usage count and
        // a reload silently changes it to 0.
        return response()->json($this->present($code->refresh(), $event), 201);
    }

    /**
     * Turn a code off.
     *
     * Deactivated rather than deleted: orders point at it, and removing it
     * would make those orders impossible to explain — and lose the attribution
     * a promoter is owed for.
     */
    public function destroy(Request $request, Event $event, Code $code): JsonResponse
    {
        $this->authorize('manageCodes', $event);

        abort_unless($code->organization_id === $event->organization_id, 404);

        $code->update(['is_active' => false]);

        return response()->json([
            'message' => 'Turned off. Orders already placed with it keep their discount.',
        ]);
    }

    private function present(Code $code, Event $event): array
    {
        return [
            'id' => $code->id,
            'code' => $code->code,
            'label' => $code->label,
            'discount_type' => $code->discount_type,
            'discount_value' => $code->discount_value,
            'discount_currency' => $code->discount_currency,
            'ref_slug' => $code->ref_slug,
            'promoter_name' => $code->promoter_name,
            'redemption_count' => $code->redemption_count,
            'max_redemptions' => $code->max_redemptions,
            'is_active' => $code->is_active,
            'event_scoped' => $code->event_id !== null,
            // Whether it would actually work right now, which is the question
            // an organizer is really asking when they look at this list.
            'usable' => $code->is_active
                && ($code->max_redemptions === null || $code->redemption_count < $code->max_redemptions)
                && ($code->starts_at === null || $code->starts_at->isPast())
                && ($code->ends_at === null || $code->ends_at->isFuture())
                && $code->appliesTo($event),
        ];
    }
}
