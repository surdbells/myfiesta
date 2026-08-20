<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventImage;
use App\Models\TicketType;
use App\Models\User;
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
 *   capacity, age policy, banner and reminder offsets. Everything an organizer
 *   would otherwise retype.
 *
 *   Not copied: anything that happened. Orders, tickets, scans, the ledger,
 *   the guest list, the gallery. A copy that inherited last week's sales would
 *   be a lie about a night that has not happened.
 *
 *   Not copied: the slug, the published state, or the currency's freedom to
 *   change. A copy starts as a draft with its own URL, because publishing
 *   something an organizer has not looked at is not a favour.
 *
 * This is also how a recurring event will materialise each occurrence: an
 * occurrence is a copy at a different moment, and building the copy properly
 * once means recurrence does not need its own parallel version of it.
 */
class EventDuplicator
{
    /**
     * @param  CarbonInterface|null  $startsAt  when the copy happens; the
     *                                          original's own start if omitted
     */
    public function duplicate(
        Event $source,
        ?CarbonInterface $startsAt = null,
        ?string $title = null,
        ?User $by = null,
    ): Event {
        return DB::transaction(function () use ($source, $startsAt, $title, $by) {
            // Read back before copying. A caller can hand us a model built in
            // memory, where every column filled in by a database default —
            // kind, status, id_required — is still null. Copying those nulls
            // produces an event that either fails to insert or, worse, inserts
            // with the wrong defaults and looks fine.
            $source = $source->fresh();

            $start = $startsAt ?? $source->starts_at;

            $copy = Event::create([
                'organization_id' => $source->organization_id,
                'venue_id' => $source->venue_id,
                'slug' => $this->slugFor($title ?? $source->title),
                'title' => $title ?? $source->title,
                'kind' => $source->kind,
                'description' => $source->description,
                'currency' => $source->currency,
                'starts_at' => $start,
                // The original's length, applied to the new start. Copying the
                // absolute end time would produce an event that finishes before
                // it begins, which the database would refuse and the organizer
                // would not understand.
                'ends_at' => $this->endFor($source, $start),
                'timezone' => $source->timezone,
                'city' => $source->city,
                'subdivision' => $source->subdivision,
                'country' => $source->country,
                'category' => $source->category,
                'dress_code' => $source->dress_code,
                'min_age' => $source->min_age,
                'id_required' => $source->id_required,
                // Always a draft. An organizer who duplicates an event to
                // change the lineup should not find last month's lineup on
                // sale while they are still editing it.
                'status' => 'draft',
            ]);

            $this->copyTicketTypes($source, $copy, $start);
            $this->copyBanner($source, $copy, $by);
            $this->copyReminders($source, $copy);

            return $copy->refresh();
        });
    }

    /**
     * Tiers, with their sales windows shifted by the same amount as the event.
     *
     * A tier that closed a week before the original must close a week before
     * the copy. Carrying the absolute dates over would produce early-bird
     * pricing that expired before the new event was announced.
     */
    private function copyTicketTypes(Event $source, Event $copy, CarbonInterface $start): void
    {
        $shift = $source->starts_at->diffInSeconds($start, false);

        foreach ($source->ticketTypes()->orderBy('sort_order')->get() as $type) {
            /** @var TicketType $type */
            TicketType::create([
                'event_id' => $copy->id,
                'name' => $type->name,
                'description' => $type->description,
                'price_amount' => $type->price_amount,
                'admits' => $type->admits,
                // The room is the same size. Sales against the original are
                // not carried over, so the copy starts with all of it.
                'quantity_available' => $type->quantity_available,
                'max_per_order' => $type->max_per_order,
                'sales_start_at' => $type->sales_start_at?->copy()->addSeconds($shift),
                'sales_end_at' => $type->sales_end_at?->copy()->addSeconds($shift),
                // A tier that sold out on the original is on sale again here:
                // sold_out describes stock that no longer exists on this event.
                'status' => $type->status === 'sold_out' ? 'on_sale' : $type->status,
                'sort_order' => $type->sort_order,
            ]);
        }
    }

    /**
     * The banner, copied as new files rather than shared.
     *
     * Two rows pointing at one path looks like a saving until somebody replaces
     * the banner on one event and it changes on the other, or deletes one event
     * and the other loses its picture. Storage is cheaper than that phone call.
     */
    private function copyBanner(Event $source, Event $copy, ?User $by): void
    {
        $banner = $source->banner;

        if (! $banner) {
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

        $path = $copyFile($banner->path, '');

        if (! $path) {
            // The original's file is missing from the disk. Copying a row that
            // points at nothing would give the new event a broken image rather
            // than none, and broken is harder to notice than absent.
            return;
        }

        $renditions = [];

        foreach ($banner->renditions ?? [] as $name => $renditionPath) {
            if ($copied = $copyFile($renditionPath, "-{$name}")) {
                $renditions[$name] = $copied;
            }
        }

        EventImage::create([
            'event_id' => $copy->id,
            'kind' => 'banner',
            'path' => $path,
            'renditions' => $renditions,
            'width' => $banner->width,
            'height' => $banner->height,
            'byte_size' => $banner->byte_size,
            'mime' => $banner->mime,
            'uploaded_by' => $by?->id ?? $banner->uploaded_by,
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
     */
    private function copyReminders(Event $source, Event $copy): void
    {
        $offsets = $source->reminders()
            ->where('status', '!=', 'cancelled')
            ->pluck('offset_minutes');

        foreach ($offsets as $minutes) {
            if ($copy->starts_at->copy()->subMinutes($minutes)->isPast()) {
                continue;
            }

            $copy->reminders()->create([
                'offset_minutes' => $minutes,
                'status' => 'scheduled',
            ]);
        }
    }

    private function endFor(Event $source, CarbonInterface $start): ?CarbonInterface
    {
        if (! $source->ends_at) {
            return null;
        }

        return $start->copy()->addSeconds(
            $source->starts_at->diffInSeconds($source->ends_at)
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

        while (Event::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
