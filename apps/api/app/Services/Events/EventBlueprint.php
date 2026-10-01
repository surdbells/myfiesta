<?php

namespace App\Services\Events;

use App\Models\AddOn;
use App\Models\Event;
use App\Models\EventQuestion;
use App\Models\TicketType;
use DateTimeInterface;

/**
 * An event's shape, as plain values a new event can be made from.
 *
 * What EventDuplicator copies, read off the event once: the details, every
 * tier with its sales window and its place in a price ladder, the extras, the
 * questions, the reminder times and the poster. Nothing that happened — no
 * sales, no scans, no codes, no gallery — because none of that belongs to the
 * next night.
 *
 * One shape for both ways a new event is made from an old one. A copy reads
 * it from the event and builds straight away; a template keeps it in a
 * jsonb column (EventTemplate) and builds from it later, after the event it
 * came from may have changed or gone. So everything in it is a value: the
 * tiers name each other by key (the id the tier had where it was read), and
 * the times are the ones it had, to be moved by however far the new event
 * moves (EventDuplicator).
 *
 * Every list is in the order the event's own snapshot reads it — tiers by
 * position, then when made, then id; extras and questions by id
 * (EventSnapshot) — so a new event that
 * keeps that order lists its tiers, extras and questions the way the source
 * did, and a series date reads as the approved night.
 */
final class EventBlueprint
{
    /** Raised when the shape changes, so a stored template can be read the way it was written. */
    public const VERSION = 1;

    /** @return array<string, mixed> */
    public static function of(Event $event): array
    {
        $types = $event->ticketTypes()->orderBy('sort_order')->orderBy('created_at')->orderBy('id')->get();
        $banner = $event->banner()->first();

        return [
            'version' => self::VERSION,
            'starts_at' => self::instant($event->starts_at),
            'ends_at' => self::instant($event->ends_at),
            'event' => [
                'title' => (string) $event->title,
                'kind' => (string) ($event->kind ?? 'ticketed'),
                'description' => $event->description,
                'currency' => (string) $event->currency,
                'venue_id' => $event->venue_id,
                'timezone' => (string) $event->timezone,
                'city' => (string) $event->city,
                'subdivision' => $event->subdivision,
                'country' => (string) $event->country,
                'category' => $event->category,
                'dress_code' => $event->dress_code,
                'min_age' => $event->min_age === null ? null : (int) $event->min_age,
                'id_required' => (bool) $event->id_required,
            ],
            'ticket_types' => $types->map(fn (TicketType $type) => [
                'key' => (string) $type->id,
                'name' => (string) $type->name,
                'description' => $type->description,
                'price_amount' => (int) $type->price_amount,
                'admits' => (int) ($type->admits ?? 1),
                'quantity_available' => $type->quantity_available === null ? null : (int) $type->quantity_available,
                'max_per_order' => $type->max_per_order === null ? null : (int) $type->max_per_order,
                'sales_start_at' => self::instant($type->sales_start_at),
                'sales_end_at' => self::instant($type->sales_end_at),
                'status' => (string) $type->status,
                'sort_order' => (int) $type->sort_order,
                'opens_after' => $type->opens_after_id,
            ])->values()->all(),
            'add_ons' => $event->addOns()->orderBy('id')->get()->map(fn (AddOn $addOn) => [
                'name' => (string) $addOn->name,
                'description' => $addOn->description,
                'price_amount' => (int) $addOn->price_amount,
                'quantity_available' => $addOn->quantity_available === null ? null : (int) $addOn->quantity_available,
                'max_per_order' => $addOn->max_per_order === null ? null : (int) $addOn->max_per_order,
                'status' => (string) $addOn->status,
                'sort_order' => (int) $addOn->sort_order,
            ])->values()->all(),
            'questions' => $event->questions()->orderBy('id')->get()->map(fn (EventQuestion $question) => [
                'label' => (string) $question->label,
                'type' => (string) $question->type,
                'options' => $question->options === null ? null : array_values((array) $question->options),
                'required' => (bool) $question->required,
                'per_attendee' => (bool) $question->per_attendee,
                'sort_order' => (int) $question->sort_order,
            ])->values()->all(),
            // Offsets, not state: a reminder that went out went out about
            // the night it was read from.
            'reminders' => $event->reminders()
                ->where('status', '!=', 'cancelled')
                ->pluck('offset_minutes')
                ->map(fn ($minutes) => (int) $minutes)
                ->unique()
                ->values()
                ->all(),
            'banner' => $banner === null ? null : [
                'path' => $banner->path,
                'renditions' => (array) ($banner->renditions ?? []),
                // The picture it was first uploaded as, so the poster on what
                // is made from this is the same poster (EventSnapshot).
                'original_path' => $banner->original_path ?? $banner->path,
                'width' => $banner->width,
                'height' => $banner->height,
                'byte_size' => $banner->byte_size,
                'mime' => $banner->mime,
                'uploaded_by' => $banner->uploaded_by,
            ],
        ];
    }

    /**
     * To the microsecond, so a window moved by the difference between two
     * starts lands exactly where the source's was relative to its own.
     */
    private static function instant(?DateTimeInterface $at): ?string
    {
        return $at?->format('Y-m-d\TH:i:s.uP');
    }
}
