<?php

namespace App\Services\Events;

use App\Models\AddOn;
use App\Models\Event;
use App\Models\EventImage;
use App\Models\EventQuestion;
use App\Models\TicketType;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Everything a buyer sees of an event, as plain values.
 *
 * What a review approves. Staff look at an event and say yes to what it said
 * then; the snapshot is that "what it said", kept with the approval, and its
 * fingerprint is how "has anything changed since?" is answered without
 * looking at it again: an event taken off sale and put back unchanged goes
 * straight back on sale, and one that changed goes back through review.
 *
 * Only what a buyer sees or pays. The title, the description, the dates and
 * zone, where it is, its category, the age and ID rules, the poster and the
 * gallery, and every ticket type, add-on and question as the checkout offers
 * them. Not the sales figures, the reminders, the team or the codes — those
 * move every day without the listing changing.
 *
 * Nothing in it names a row by its id. Two events with the same content have
 * the same snapshot, which is what lets the next date of an approved series be
 * recognised as the approved night on another date. Pictures are named by the
 * file they were first uploaded as (EventImage::original_path), so a copy's
 * poster is the same poster.
 */
final class EventSnapshot
{
    /**
     * Stock that ran out is not a change the organizer made. A tier marked
     * sold out by a sale reads as the tier it was.
     */
    private const SOLD_OUT_IS_ON_SALE = ['sold_out' => 'on_sale'];

    /** @return array<string, mixed> */
    public static function of(Event $event): array
    {
        $event->loadMissing('venue');

        $types = $event->ticketTypes()->orderBy('sort_order')->orderBy('created_at')->orderBy('id')->get();
        $names = $types->pluck('name', 'id');

        $banner = $event->banner()->first();

        return [
            'title' => (string) $event->title,
            'kind' => (string) ($event->kind ?? 'ticketed'),
            'description' => (string) ($event->description ?? ''),
            'currency' => (string) $event->currency,
            'starts_at' => self::instant($event->starts_at),
            'ends_at' => self::instant($event->ends_at),
            'timezone' => (string) $event->timezone,
            'venue' => $event->venue === null ? null : [
                'name' => (string) $event->venue->name,
                'address' => $event->venue->address_line,
            ],
            'city' => (string) $event->city,
            'subdivision' => $event->subdivision,
            'country' => (string) $event->country,
            'category' => $event->category,
            'dress_code' => $event->dress_code,
            'min_age' => $event->min_age === null ? null : (int) $event->min_age,
            'id_required' => (bool) $event->id_required,
            // By the picture alone. A poster's caption is not shown to buyers
            // (EventResource shows the gallery's), and a copy for another
            // night does not carry one (EventDuplicator): kept here, a poster
            // promoted from a captioned gallery picture would make every date
            // of an approved series look changed.
            'poster' => $banner === null ? null : ['image' => self::file($banner)],
            'gallery' => $event->gallery()->get()->map(fn (EventImage $image) => self::picture($image))->values()->all(),
            'ticket_types' => $types->map(fn (TicketType $type) => [
                'name' => (string) $type->name,
                'description' => $type->description,
                'price_amount' => (int) $type->price_amount,
                'admits' => (int) ($type->admits ?? 1),
                'quantity_available' => $type->quantity_available === null ? null : (int) $type->quantity_available,
                'max_per_order' => $type->max_per_order === null ? null : (int) $type->max_per_order,
                'sales_start_at' => self::instant($type->sales_start_at),
                'sales_end_at' => self::instant($type->sales_end_at),
                'status' => self::SOLD_OUT_IS_ON_SALE[$type->status] ?? (string) $type->status,
                // By name: the tier it waits for, as a buyer would read it.
                'opens_after' => $type->opens_after_id === null ? null : ($names[$type->opens_after_id] ?? null),
            ])->values()->all(),
            'add_ons' => $event->addOns()->orderBy('id')->get()
                ->map(fn (AddOn $addOn) => [
                    'name' => (string) $addOn->name,
                    'description' => $addOn->description,
                    'price_amount' => (int) $addOn->price_amount,
                    'quantity_available' => $addOn->quantity_available === null ? null : (int) $addOn->quantity_available,
                    'max_per_order' => $addOn->max_per_order === null ? null : (int) $addOn->max_per_order,
                    'status' => self::SOLD_OUT_IS_ON_SALE[$addOn->status] ?? (string) $addOn->status,
                ])->values()->all(),
            'questions' => $event->questions()->orderBy('id')->get()->map(fn (EventQuestion $question) => [
                'label' => (string) $question->label,
                'type' => (string) $question->type,
                'options' => array_values((array) ($question->options ?? [])),
                'required' => (bool) $question->required,
                'per_attendee' => (bool) $question->per_attendee,
            ])->values()->all(),
        ];
    }

    /**
     * A short, fixed-length name for a snapshot.
     *
     * Keys sorted all the way down, so the same content always hashes the
     * same whatever order it was assembled in.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function fingerprint(array $snapshot): string
    {
        return hash('sha256', (string) json_encode(self::sorted($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The snapshot with its dates taken out, and every other time told
     * relative to the start.
     *
     * The next date of a series is a copy moved in time: its sales windows
     * move with it (EventDuplicator), so a tier that closed a day before the
     * source closes a day before the copy. Read this way, the two agree.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function withoutDates(array $snapshot): array
    {
        $start = isset($snapshot['starts_at']) ? CarbonImmutable::parse($snapshot['starts_at']) : null;

        $relative = fn (?string $at) => $at === null || $start === null
            ? null
            : (int) $start->diffInSeconds(CarbonImmutable::parse($at), false);

        $snapshot['length'] = $relative($snapshot['ends_at'] ?? null);
        unset($snapshot['starts_at'], $snapshot['ends_at']);

        $snapshot['ticket_types'] = array_map(fn (array $type) => array_merge($type, [
            'sales_start_at' => $relative($type['sales_start_at'] ?? null),
            'sales_end_at' => $relative($type['sales_end_at'] ?? null),
        ]), $snapshot['ticket_types'] ?? []);

        return $snapshot;
    }

    /**
     * What is different between two snapshots, in sentences staff can read.
     *
     * Ticket types, add-ons and questions are matched by name: that is how
     * both the organizer and the buyer know them, and a renamed tier reads,
     * correctly, as one removed and one added.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public static function changes(array $before, array $after): array
    {
        $currency = (string) ($after['currency'] ?? $before['currency'] ?? 'CAD');
        $zone = (string) ($after['timezone'] ?? 'UTC');
        $changes = [];

        $fields = [
            'title' => 'Title',
            'starts_at' => 'Starts',
            'ends_at' => 'Ends',
            'timezone' => 'Time zone',
            'city' => 'City',
            'subdivision' => 'Province or state',
            'country' => 'Country',
            'category' => 'Category',
            'dress_code' => 'Dress code',
            'min_age' => 'Minimum age',
            'id_required' => 'ID required at the door',
        ];

        foreach ($fields as $key => $label) {
            $was = $before[$key] ?? null;
            $now = $after[$key] ?? null;

            if ($was !== $now) {
                $changes[] = "{$label}: ".self::shown($key, $was, $zone).' → '.self::shown($key, $now, $zone);
            }
        }

        if (($before['description'] ?? '') !== ($after['description'] ?? '')) {
            $changes[] = 'The description was rewritten.';
        }

        if (($before['venue'] ?? null) !== ($after['venue'] ?? null)) {
            $changes[] = 'Venue: '.self::venue($before['venue'] ?? null).' → '.self::venue($after['venue'] ?? null);
        }

        $changes = [...$changes, ...self::pictureChanges($before, $after)];

        $changes = [...$changes, ...self::listChanges(
            'Ticket',
            $before['ticket_types'] ?? [],
            $after['ticket_types'] ?? [],
            'name',
            fn (array $type) => Money::of((int) $type['price_amount'], $currency)->format(),
            [
                'price_amount' => fn ($v) => Money::of((int) $v, $currency)->format(),
                'quantity_available' => fn ($v) => $v === null ? 'unlimited' : number_format((int) $v),
                'max_per_order' => fn ($v) => $v === null ? 'no limit' : (string) $v,
                'admits' => fn ($v) => (string) $v,
                'status' => fn ($v) => str_replace('_', ' ', (string) $v),
                'sales_start_at' => fn ($v) => self::shown('starts_at', $v, $zone),
                'sales_end_at' => fn ($v) => self::shown('starts_at', $v, $zone),
                'opens_after' => fn ($v) => $v === null ? 'none' : "“{$v}”",
                'description' => fn ($v) => $v === null || $v === '' ? 'none' : 'new wording',
            ],
        )];

        $changes = [...$changes, ...self::listChanges(
            'Add-on',
            $before['add_ons'] ?? [],
            $after['add_ons'] ?? [],
            'name',
            fn (array $addOn) => Money::of((int) $addOn['price_amount'], $currency)->format(),
            [
                'price_amount' => fn ($v) => Money::of((int) $v, $currency)->format(),
                'quantity_available' => fn ($v) => $v === null ? 'unlimited' : number_format((int) $v),
                'max_per_order' => fn ($v) => $v === null ? 'no limit' : (string) $v,
                'status' => fn ($v) => str_replace('_', ' ', (string) $v),
                'description' => fn ($v) => $v === null || $v === '' ? 'none' : 'new wording',
            ],
        )];

        $changes = [...$changes, ...self::listChanges(
            'Question',
            $before['questions'] ?? [],
            $after['questions'] ?? [],
            'label',
            fn (array $question) => str_replace('_', ' ', (string) $question['type']).($question['required'] ? ', required' : ''),
            [
                'type' => fn ($v) => str_replace('_', ' ', (string) $v),
                'options' => fn ($v) => implode(', ', (array) $v) ?: 'none',
                'required' => fn ($v) => $v ? 'required' : 'optional',
                'per_attendee' => fn ($v) => $v ? 'asked of each guest' : 'asked once',
            ],
        )];

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private static function pictureChanges(array $before, array $after): array
    {
        $changes = [];
        $was = $before['poster']['image'] ?? null;
        $now = $after['poster']['image'] ?? null;

        if ($was !== $now) {
            $changes[] = match (true) {
                $was === null => 'A poster was added.',
                $now === null => 'The poster was removed.',
                default => 'The poster was replaced.',
            };
        }

        $wasGallery = array_column($before['gallery'] ?? [], 'image');
        $nowGallery = array_column($after['gallery'] ?? [], 'image');
        $added = count(array_diff($nowGallery, $wasGallery));
        $removed = count(array_diff($wasGallery, $nowGallery));

        if ($added > 0) {
            $changes[] = $added === 1 ? 'One picture was added to the gallery.' : "{$added} pictures were added to the gallery.";
        }

        if ($removed > 0) {
            $changes[] = $removed === 1 ? 'One picture was removed from the gallery.' : "{$removed} pictures were removed from the gallery.";
        }

        if ($added === 0 && $removed === 0 && ($before['gallery'] ?? []) !== ($after['gallery'] ?? [])) {
            $changes[] = 'The gallery was reordered or its captions changed.';
        }

        return $changes;
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @param  callable(array<string, mixed>): string  $summary
     * @param  array<string, callable(mixed): string>  $shown
     * @return list<string>
     */
    private static function listChanges(string $noun, array $before, array $after, string $key, callable $summary, array $shown): array
    {
        $was = collect($before)->keyBy($key);
        $now = collect($after)->keyBy($key);
        $changes = [];

        foreach ($now as $name => $item) {
            if (! $was->has($name)) {
                $what = $summary($item);
                $changes[] = "{$noun} “{$name}” was added ({$what}).";

                continue;
            }

            $old = $was->get($name);
            $parts = [];

            foreach ($shown as $field => $format) {
                if (($old[$field] ?? null) !== ($item[$field] ?? null)) {
                    $parts[] = str_replace('_', ' ', $field).' '.$format($old[$field] ?? null).' → '.$format($item[$field] ?? null);
                }
            }

            if ($parts !== []) {
                $changes[] = "{$noun} “{$name}”: ".implode('; ', $parts).'.';
            }
        }

        foreach ($was as $name => $item) {
            if (! $now->has($name)) {
                $changes[] = "{$noun} “{$name}” was removed.";
            }
        }

        if ($changes === [] && $was->keys()->all() !== $now->keys()->all()) {
            $changes[] = $noun.'s were put in a different order.';
        }

        return $changes;
    }

    private static function shown(string $key, mixed $value, string $zone): string
    {
        if ($value === null || $value === '') {
            return 'none';
        }

        if (in_array($key, ['starts_at', 'ends_at'], true)) {
            return CarbonImmutable::parse((string) $value)->timezone($zone)->format('D j M Y, g:ia');
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return '“'.$value.'”';
    }

    /** @param  array{name?: string, address?: string|null}|null  $venue */
    private static function venue(?array $venue): string
    {
        return $venue === null ? 'none' : trim(($venue['name'] ?? '').($venue['address'] ? ', '.$venue['address'] : ''));
    }

    /** @return array{image: string, caption: string|null} */
    private static function picture(EventImage $image): array
    {
        return [
            'image' => self::file($image),
            'caption' => $image->caption,
        ];
    }

    /** The file it was first uploaded as, so a copy's picture is the same picture. */
    private static function file(EventImage $image): string
    {
        return (string) ($image->original_path ?? $image->path);
    }

    private static function instant(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? self::sorted($item) : $item, $value);
    }
}
