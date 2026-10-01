<?php

namespace App\Http\Requests;

use App\Services\Events\DuplicateOptions;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The changes asked for on the way to a copy, read from a request.
 *
 * One reading for both ways a new event is made from an old one — copying an
 * event (EventCopyController) and starting from a template
 * (EventTemplateController) — so the two take the same body and refuse it in
 * the same words. Not a FormRequest: both controllers authorise before they
 * validate, so somebody who may not copy is told so rather than told their
 * date is wrong.
 */
final class CopyAdjustments
{
    /**
     * Validate the body and say what it asks for.
     *
     * @param  list<string>  $tiers  the ticket types that can be named, by key
     * @param  CarbonInterface|null  $defaultStart  when the copy starts if no date is given; a date is required without one
     * @return array{0: CarbonInterface, 1: DuplicateOptions}
     */
    public static function read(Request $request, array $tiers, ?CarbonInterface $defaultStart, string $from): array
    {
        $data = $request->validate([
            'starts_at' => [$defaultStart === null ? 'required' : 'nullable', 'date', 'after:now'],
            'ends_at' => ['nullable', 'date'],
            'title' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:20000'],
            'ticket_types' => ['sometimes', 'array', 'max:100'],
            'ticket_types.*' => ['array:id,include,name,price_amount,quantity_available'],
            'ticket_types.*.id' => ['required', 'string', 'distinct'],
            'ticket_types.*.include' => ['sometimes', 'boolean'],
            'ticket_types.*.name' => ['sometimes', 'nullable', 'string', 'max:80'],
            // Minor units, as everywhere: 2500 is $25.00.
            'ticket_types.*.price_amount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            // The column's ceiling, so a number too big to keep is refused here
            // rather than failing the copy half made.
            'ticket_types.*.quantity_available' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647'],
            'include' => ['sometimes', 'array:add_ons,questions,reminders'],
            'include.add_ons' => ['sometimes', 'boolean'],
            'include.questions' => ['sometimes', 'boolean'],
            'include.reminders' => ['sometimes', 'boolean'],
        ], [
            'starts_at.required' => 'Pick a date for the new event.',
            'starts_at.after' => 'Pick a date in the future for the copy.',
            'ticket_types.*.quantity_available.max' => 'How many is more than one tier can hold. Leave it empty for unlimited.',
        ]);

        $start = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : $defaultStart;
        $end = isset($data['ends_at']) ? Carbon::parse($data['ends_at']) : null;

        if ($end !== null && $start !== null && $end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['ends_at' => 'The new event has to end after it starts.']);
        }

        $adjustments = [];

        foreach ($data['ticket_types'] ?? [] as $index => $type) {
            // Refused rather than skipped: quietly ignoring an id from
            // elsewhere would let this confirm which ids exist.
            if (! in_array($type['id'], $tiers, true)) {
                throw ValidationException::withMessages([
                    "ticket_types.{$index}.id" => "That list includes a ticket type that is not on {$from}.",
                ]);
            }

            // The rule lets 0 and "0" through as well as false; all three
            // mean "leave it out", and DuplicateOptions reads a real bool.
            if (array_key_exists('include', $type)) {
                $type['include'] = filter_var($type['include'], FILTER_VALIDATE_BOOLEAN);
            }

            $adjustments[$type['id']] = $type;
        }

        $included = $data['include'] ?? [];

        // Never read as "the same": a null or empty description given on
        // purpose clears it, and an absent one keeps it.
        $replacesDescription = $request->exists('description');

        return [$start, new DuplicateOptions(
            title: isset($data['title']) && trim($data['title']) !== '' ? trim($data['title']) : null,
            replacesDescription: $replacesDescription,
            description: $replacesDescription ? ($data['description'] ?? null) : null,
            endsAt: $end,
            ticketTypes: $adjustments,
            addOns: (bool) ($included['add_ons'] ?? true),
            questions: (bool) ($included['questions'] ?? true),
            reminders: (bool) ($included['reminders'] ?? true),
        )];
    }
}
