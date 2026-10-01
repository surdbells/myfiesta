<?php

namespace App\Services\Events;

use App\Models\AddOn;
use App\Models\Event;
use App\Models\EventImage;
use App\Models\EventQuestion;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Events\Copying\Carried;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copying an event into a new one.
 *
 * The value of this is not saving typing. It is that an organizer running a
 * weekly night currently re-enters four ticket tiers, a description, an address
 * and an age policy every week, and gets one of them slightly wrong every few
 * weeks — usually a price.
 *
 * What is copied and what is not is the whole design:
 *
 *   Copied: the shape of the event. Title, description, venue, tiers, prices,
 *   capacity, price ladders, age policy, extras, questions, banner and
 *   reminder offsets. Everything an organizer would otherwise retype.
 *
 *   Not copied: anything that happened. Orders, tickets, scans, the ledger,
 *   the guest list, the codes, the gallery. A copy that inherited last week's
 *   sales would be a lie about a night that has not happened.
 *
 *   Not copied: the slug, the published state, the approval, or the
 *   currency's freedom to change. A copy starts as a draft with its own URL,
 *   because publishing something an organizer has not looked at is not a
 *   favour — and it goes through review like any new event, except the next
 *   date of an approved series that is that night unchanged (EventReviews).
 *
 * This is also how a recurring event materialises each occurrence: an
 * occurrence is a copy at a different moment, and building the copy properly
 * once means recurrence does not need its own parallel version of it. And it
 * is how an event is made from a template (EventTemplates), which is the same
 * shape kept for later: both read the event as an EventBlueprint and build
 * from that.
 *
 * Changes asked for on the way — a new title, a tier left out, a price that
 * went up, no extras this time — come as DuplicateOptions. Without them the
 * copy is the night as it was, which is what a series date has to be.
 */
class EventDuplicator
{
    /**
     * @param  CarbonInterface|null  $startsAt  when the copy happens; the
     *                                          original's own start if omitted
     * @param  DuplicateOptions|null  $options  what to change on the way;
     *                                          nothing when omitted
     */
    public function duplicate(
        Event $source,
        ?CarbonInterface $startsAt = null,
        ?string $title = null,
        ?User $by = null,
        ?DuplicateOptions $options = null,
    ): Event {
        return DB::transaction(function () use ($source, $startsAt, $title, $by, $options) {
            // Read back before copying. A caller can hand us a model built in
            // memory, where every column filled in by a database default —
            // kind, status, id_required — is still null. Copying those nulls
            // produces an event that either fails to insert or, worse, inserts
            // with the wrong defaults and looks fine.
            $source = $source->fresh();

            $copy = $this->build(
                EventBlueprint::of($source),
                $source->organization_id,
                $startsAt ?? $source->starts_at,
                ($options ?? DuplicateOptions::everything())->titled($title),
                $by,
            );

            // What the features added since keep on a copy, each deciding
            // for its own columns (Copying\Carried).
            app(Carried::class)->carry($source, $copy);

            return $copy->refresh();
        });
    }

    /**
     * A new event from a shape kept earlier: a template.
     *
     * The same build as a copy, without an event to carry the features'
     * own columns from — what a template keeps is what the blueprint holds.
     *
     * @param  array<string, mixed>  $blueprint  as EventBlueprint::of() wrote it
     */
    public function fromBlueprint(
        array $blueprint,
        string $organizationId,
        CarbonInterface $startsAt,
        ?DuplicateOptions $options = null,
        ?User $by = null,
    ): Event {
        return DB::transaction(fn () => $this->build(
            $blueprint,
            $organizationId,
            $startsAt,
            $options ?? DuplicateOptions::everything(),
            $by,
        )->refresh());
    }

    /** @param  array<string, mixed>  $blueprint */
    private function build(
        array $blueprint,
        string $organizationId,
        CarbonInterface $start,
        DuplicateOptions $options,
        ?User $by,
    ): Event {
        $details = $blueprint['event'];
        $from = CarbonImmutable::parse($blueprint['starts_at']);
        $title = $options->title ?? $details['title'];

        $copy = Event::create([
            'organization_id' => $organizationId,
            'venue_id' => $details['venue_id'],
            'slug' => $this->slugFor($title),
            'title' => $title,
            'kind' => $details['kind'],
            'description' => $options->replacesDescription ? $options->description : $details['description'],
            'currency' => $details['currency'],
            'starts_at' => $start,
            // The original's length, applied to the new start, unless an end
            // was asked for. Copying the absolute end time would produce an
            // event that finishes before it begins, which the database would
            // refuse and the organizer would not understand.
            'ends_at' => $options->endsAt ?? $this->endFor($blueprint, $from, $start),
            'timezone' => $details['timezone'],
            'city' => $details['city'],
            'subdivision' => $details['subdivision'],
            'country' => $details['country'],
            'category' => $details['category'],
            'dress_code' => $details['dress_code'],
            'min_age' => $details['min_age'],
            'id_required' => $details['id_required'],
            // Always a draft. An organizer who duplicates an event to
            // change the lineup should not find last month's lineup on
            // sale while they are still editing it.
            'status' => 'draft',
        ]);

        $this->copyTicketTypes($blueprint['ticket_types'] ?? [], $copy, $from->diffInSeconds($start, false), $options);

        if ($options->addOns) {
            $this->copyAddOns($blueprint['add_ons'] ?? [], $copy);
        }

        if ($options->questions) {
            $this->copyQuestions($blueprint['questions'] ?? [], $copy);
        }

        $this->copyBanner($blueprint['banner'] ?? null, $copy, $by);

        if ($options->reminders) {
            $this->copyReminders($blueprint['reminders'] ?? [], $copy);
        }

        return $copy;
    }

    /**
     * Tiers, with their sales windows shifted by the same amount as the event.
     *
     * A tier that closed a week before the original must close a week before
     * the copy. Carrying the absolute dates over would produce early-bird
     * pricing that expired before the new event was announced.
     *
     * A price ladder comes across as a ladder: each tier waits for the copy
     * of the tier it waited for. Pointing at the original's tier instead
     * would open the copy's second step when last month's first step sold
     * out — or never. A step left out of the copy leaves the one after it
     * waiting for nothing, so that one opens like any other tier.
     *
     * @param  list<array<string, mixed>>  $types
     */
    private function copyTicketTypes(array $types, Event $copy, float|int $shift, DuplicateOptions $options): void
    {
        $kept = array_values(array_filter($types, fn (array $type) => $options->includes((string) $type['key'])));
        $ids = $this->idsInOrder(count($kept));
        $copied = [];

        foreach ($kept as $index => $type) {
            $copied[(string) $type['key']] = $ids[$index];
        }

        foreach ($kept as $index => $type) {
            $type = $options->adjust((string) $type['key'], $type);

            (new TicketType)->forceFill([
                'id' => $ids[$index],
                'event_id' => $copy->id,
                'name' => $type['name'],
                'description' => $type['description'],
                'price_amount' => $type['price_amount'],
                'admits' => $type['admits'],
                // The room is the same size. Sales against the original are
                // not carried over, so the copy starts with all of it.
                'quantity_available' => $type['quantity_available'],
                'max_per_order' => $type['max_per_order'],
                'sales_start_at' => $this->moved($type['sales_start_at'], $shift),
                'sales_end_at' => $this->moved($type['sales_end_at'], $shift),
                // A tier that sold out on the original is on sale again here:
                // sold_out describes stock that no longer exists on this event.
                'status' => $type['status'] === 'sold_out' ? 'on_sale' : $type['status'],
                'sort_order' => $type['sort_order'],
            ])->save();
        }

        // Once every step exists, because a tier may wait for one listed
        // after it.
        foreach ($kept as $index => $type) {
            $waitsFor = $copied[(string) ($type['opens_after'] ?? '')] ?? null;

            if ($waitsFor !== null) {
                TicketType::query()->whereKey($ids[$index])->update(['opens_after_id' => $waitsFor]);
            }
        }
    }

    /**
     * Extras, with all of their stock and nothing sold.
     *
     * @param  list<array<string, mixed>>  $addOns
     */
    private function copyAddOns(array $addOns, Event $copy): void
    {
        $ids = $this->idsInOrder(count($addOns));

        foreach ($addOns as $index => $addOn) {
            (new AddOn)->forceFill([
                'id' => $ids[$index],
                'event_id' => $copy->id,
                'name' => $addOn['name'],
                'description' => $addOn['description'],
                'price_amount' => $addOn['price_amount'],
                'quantity_available' => $addOn['quantity_available'],
                'max_per_order' => $addOn['max_per_order'],
                'status' => $addOn['status'] === 'sold_out' ? 'on_sale' : $addOn['status'],
                'sort_order' => $addOn['sort_order'],
            ])->save();
        }
    }

    /**
     * The questions, asked the same way. Their answers stay with the night
     * they were given for.
     *
     * @param  list<array<string, mixed>>  $questions
     */
    private function copyQuestions(array $questions, Event $copy): void
    {
        $ids = $this->idsInOrder(count($questions));

        foreach ($questions as $index => $question) {
            (new EventQuestion)->forceFill([
                'id' => $ids[$index],
                'event_id' => $copy->id,
                'label' => $question['label'],
                'type' => $question['type'],
                'options' => $question['options'],
                'required' => $question['required'],
                'per_attendee' => $question['per_attendee'],
                'sort_order' => $question['sort_order'],
            ])->save();
        }
    }

    /**
     * New ids that sort in the order they are handed out.
     *
     * The snapshot a review approves lists tiers, extras and questions by
     * position, then by when they were made, then by id (EventSnapshot); rows
     * made in one request can share a moment, and new ids made in the same
     * millisecond do not always sort in the order they were made. Sorted
     * here and given out in the source's order, the copy lists everything as
     * the source did — so the next date of an approved series with two extras
     * reads as the approved night rather than as one with its extras swapped.
     *
     * @return list<string>
     */
    private function idsInOrder(int $count): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = (string) Str::uuid7();
        }

        sort($ids, SORT_STRING);

        return $ids;
    }

    private function moved(?string $at, float|int $shift): ?CarbonImmutable
    {
        return $at === null ? null : CarbonImmutable::parse($at)->addSeconds($shift);
    }

    /**
     * The banner, copied as new files rather than shared.
     *
     * Two rows pointing at one path looks like a saving until somebody replaces
     * the banner on one event and it changes on the other, or deletes one event
     * and the other loses its picture. Storage is cheaper than that phone call.
     *
     * @param  array<string, mixed>|null  $banner
     */
    private function copyBanner(?array $banner, Event $copy, ?User $by): void
    {
        if ($banner === null) {
            return;
        }

        $disk = Storage::disk('public');
        $stem = Str::lower(Str::random(16));
        $directory = "events/{$copy->id}";

        $copyFile = function (?string $path, string $suffix) use ($disk, $directory, $stem): ?string {
            if (! $path || ! $disk->exists($path)) {
                return null;
            }

            $destination = "{$directory}/{$stem}{$suffix}.jpg";
            $disk->copy($path, $destination);

            return $destination;
        };

        $path = $copyFile($banner['path'] ?? null, '');

        if (! $path) {
            // The original's file is missing from the disk. Copying a row that
            // points at nothing would give the new event a broken image rather
            // than none, and broken is harder to notice than absent.
            return;
        }

        $renditions = [];

        foreach ($banner['renditions'] ?? [] as $name => $renditionPath) {
            if ($copied = $copyFile($renditionPath, "-{$name}")) {
                $renditions[$name] = $copied;
            }
        }

        EventImage::create([
            'event_id' => $copy->id,
            'kind' => 'banner',
            'path' => $path,
            // The same picture in new files. Named after the one it was first
            // uploaded as, so the next date of an approved series shows the
            // approved poster rather than an unknown one (EventSnapshot).
            'original_path' => $banner['original_path'] ?? $banner['path'],
            'renditions' => $renditions,
            'width' => $banner['width'] ?? null,
            'height' => $banner['height'] ?? null,
            'byte_size' => $banner['byte_size'] ?? null,
            'mime' => $banner['mime'] ?? null,
            'uploaded_by' => $by?->id ?? ($banner['uploaded_by'] ?? null),
        ]);

        // The gallery is deliberately not copied: those photographs are of a
        // night that already happened, and putting them on a future event
        // would be advertising with pictures of a different party.
    }

    /**
     * Reminder offsets, not reminder state.
     *
     * The copy gets the same schedule with nothing sent, which is the only
     * sensible reading — a reminder that already went out went out about the
     * original.
     *
     * @param  list<int>  $offsets
     */
    private function copyReminders(array $offsets, Event $copy): void
    {
        foreach ($offsets as $minutes) {
            if ($copy->starts_at->copy()->subMinutes((int) $minutes)->isPast()) {
                continue;
            }

            $copy->reminders()->create([
                'offset_minutes' => (int) $minutes,
                'status' => 'scheduled',
            ]);
        }
    }

    /** @param  array<string, mixed>  $blueprint */
    private function endFor(array $blueprint, CarbonImmutable $from, CarbonInterface $start): ?CarbonInterface
    {
        if (($blueprint['ends_at'] ?? null) === null) {
            return null;
        }

        return $start->copy()->addSeconds(
            $from->diffInSeconds(CarbonImmutable::parse($blueprint['ends_at']))
        );
    }

    /**
     * A new slug, always.
     *
     * The original's is in shared messages and printed codes and belongs to it
     * permanently. A numeric suffix is honest about what this is.
     */
    private function slugFor(string $title): string
    {
        $base = Str::slug($title) ?: 'event';
        $slug = $base;
        $n = 2;

        while (Event::slugIsTaken($slug, withTrashed: true)) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
