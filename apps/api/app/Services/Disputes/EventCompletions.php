<?php

namespace App\Services\Disputes;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventCompletion;
use App\Services\Checkout\TurnedAway;
use App\Services\Door\DoorPasses;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Nights that are over, written down once: that they happened, and how the
 * door went.
 *
 * "The event never took place" is answered by the door — opened at 22:10,
 * closed at 03:40, 412 let in — and every scan behind that is kept already.
 * What is not kept is a version of the night its organizer cannot change: the
 * event row can be edited the next morning, times and all. So once the door
 * has closed and the phones that lost signal have had time to send their
 * scans, the night is copied here as it stood, beside the door's own counts,
 * into a table the database will not let anybody edit.
 *
 * Only nights that sold something are written down — a night with no tickets
 * has nobody to dispute it — and not cancelled or taken-down ones, which did
 * not take place. Nights older than the evidence is kept for are left alone,
 * so the first run is not a backfill of every night ever imported.
 */
class EventCompletions
{
    /** Write down every night that is due. Returns how many were written. */
    public function recordDue(): int
    {
        $settle = DoorPasses::GRACE_HOURS + (int) config('disputes.completion.after_door_closes_hours', 12);
        $listedEnd = "coalesce(events.ends_at, events.starts_at + interval '".TurnedAway::HOURS_WITHOUT_AN_END." hours')";

        $recorded = 0;

        Event::query()
            ->whereNull('taken_down_at')
            ->where('status', '!=', EventStatus::Cancelled->value)
            ->whereRaw("{$listedEnd} + (? * interval '1 hour') <= ?", [$settle, now()])
            ->whereRaw("{$listedEnd} >= ?", [now()->subMonths((int) config('disputes.retention_months', 18))])
            ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('tickets')->whereColumn('tickets.event_id', 'events.id'))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('event_completions')->whereColumn('event_completions.event_id', 'events.id'))
            ->with('venue')
            ->chunkById(100, function ($events) use (&$recorded) {
                foreach ($events as $event) {
                    $recorded += $this->record($event) ? 1 : 0;
                }
            });

        return $recorded;
    }

    /**
     * Write one night down, once.
     *
     * Returns whether this call wrote it: a second call, or two sweeps at
     * once, find the unique index and write nothing.
     */
    public function record(Event $event): bool
    {
        $door = DB::table('ticket_scans')
            ->where('event_id', $event->id)
            ->selectRaw('min(scanned_at) as opened, max(scanned_at) as closed, count(*) as scans')
            ->selectRaw("coalesce(sum(case when result = 'accepted' then admitted else 0 end), 0) as admitted")
            ->selectRaw("count(*) filter (where result <> 'accepted') as turned_away")
            ->first();

        $tickets = DB::table('tickets')
            ->where('event_id', $event->id)
            ->selectRaw('count(*) as issued')
            ->selectRaw("count(*) filter (where status in ('valid', 'checked_in')) as live")
            ->first();

        return EventCompletion::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'organization_id' => $event->organization_id,
            'title' => $event->title,
            'status' => $event->status,
            'starts_at' => $event->starts_at,
            'ends_at' => $event->ends_at,
            'timezone' => $event->timezone,
            'venue' => $event->venue?->name,
            'city' => $event->city,
            'door_opened_at' => $door->opened,
            'door_closed_at' => $door->closed,
            'tickets_issued' => (int) ($tickets->issued ?? 0),
            'tickets_live' => (int) ($tickets->live ?? 0),
            'people_admitted' => (int) ($door->admitted ?? 0),
            'turned_away' => (int) ($door->turned_away ?? 0),
            'scans' => (int) ($door->scans ?? 0),
            'recorded_at' => now(),
        ]) === 1;
    }
}
