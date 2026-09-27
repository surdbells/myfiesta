<?php

namespace App\Services\StaffSupport;

use App\Enums\Permission;
use App\Enums\Role;
use App\Mail\EventRestored;
use App\Mail\EventTakenDown;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Organizations\Suspension;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The platform deciding what the public sees: featuring an event on the front
 * page, and taking one off sale.
 *
 * A takedown is not a cancellation and deletes nothing. The event goes back to
 * a draft, which hides the page and stops checkout, and the organizer's own
 * publish button is refused until an administrator lifts it — otherwise it is a
 * suggestion they can undo with one click. Tickets already sold stay valid:
 * whether the night goes ahead is a separate decision, and cancelling is how
 * that one is made.
 */
class EventModeration
{
    public function __construct(private readonly Auditor $auditor) {}

    /** Put an event on the front page, or take it off. */
    public function feature(Event $event, User $staff, bool $featured): void
    {
        StaffAction::Feature->authorize($staff);

        if ($featured && ($event->status !== 'published' || $event->taken_down_at !== null)) {
            throw StaffActionRefused::because('Only an event that is on sale can be featured.');
        }

        if ((bool) $event->is_featured === $featured) {
            return;
        }

        $event->forceFill(['is_featured' => $featured])->save();

        $this->auditor->record($featured ? 'event.featured' : 'event.unfeatured', $event, $staff);
    }

    /**
     * Off sale, with a reason the organizer is sent.
     *
     * @return int how many people at the organization were told
     */
    public function takeDown(Event $event, User $staff, string $reason): int
    {
        StaffAction::TakeDown->authorize($staff);

        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw StaffActionRefused::because('Give the organizer a reason they can act on. It is emailed to them.');
        }

        [$from, $heldBySuspension] = DB::transaction(function () use ($event, $staff, $reason) {
            $locked = $this->lock($event);

            if ($locked->taken_down_at !== null) {
                throw StaffActionRefused::because('This event is already taken down.');
            }

            // Already off sale, and told everybody it is not happening. Hiding
            // a cancelled page would leave ticket holders with no explanation.
            if ($locked->status === 'cancelled') {
                throw StaffActionRefused::because('This event is cancelled. Its page stays up so ticket holders can see that.');
            }

            $from = $locked->status;

            // A draft only because its organization is suspended: it was on
            // sale until then, and lifting this takedown should treat it so.
            $heldBySuspension = $locked->unpublished_by_suspension_at !== null;

            $locked->forceFill([
                'status' => 'draft',
                'is_featured' => false,
                'taken_down_at' => now(),
                'taken_down_reason' => mb_substr($reason, 0, 1000),
                'taken_down_by' => $staff->id,
            ])->save();

            return [$from, $heldBySuspension];
        });

        $event->refresh();

        $told = $this->organizers($event);

        $this->auditor->record('event.taken_down', $event, $staff, metadata: array_filter([
            'reason' => $reason,
            'from' => $from,
            'off_sale_for_suspension' => $heldBySuspension ?: null,
            'organizers_told' => $told->count(),
        ], fn ($value) => $value !== null));

        $told->each(fn (string $email) => Mail::to($email)->send(new EventTakenDown($event, $reason)));

        return $told->count();
    }

    /**
     * Lift a takedown.
     *
     * Back on sale only if it was on sale before and still can be — it has not
     * started, and has something to sell. Otherwise it stays a draft the
     * organizer can publish once they are ready, which is the same thing they
     * would be told by their own publish button.
     *
     * @return string the status it is left in
     */
    public function restore(Event $event, User $staff, ?string $note = null): string
    {
        StaffAction::TakeDown->authorize($staff);

        $wasPublished = $this->wasPublishedWhenTakenDown($event);

        [$status, $waitsForSuspension] = DB::transaction(function () use ($event, $wasPublished) {
            $locked = $this->lock($event);

            if ($locked->taken_down_at === null) {
                throw StaffActionRefused::because('This event is not taken down.');
            }

            $canSell = $locked->kind !== 'ticketed'
                || $locked->ticketTypes()->where('status', 'on_sale')->exists();

            $status = $wasPublished && $locked->starts_at->isFuture() && $canSell ? 'published' : 'draft';

            // The organization is suspended: nothing of its goes on sale. The
            // event waits with the ones the suspension took off sale, and goes
            // back on sale with them when it is lifted.
            $waitsForSuspension = $status === 'published' && Suspension::inForce($locked->organization_id);

            if ($waitsForSuspension) {
                $status = 'draft';
                $locked->forceFill(['unpublished_by_suspension_at' => now()]);
            }

            $locked->forceFill([
                'status' => $status,
                'published_at' => $status === 'published' ? ($locked->published_at ?? now()) : $locked->published_at,
                'taken_down_at' => null,
                'taken_down_reason' => null,
                'taken_down_by' => null,
            ])->save();

            return [$status, $waitsForSuspension];
        });

        $event->refresh();

        $told = $this->organizers($event);

        $this->auditor->record('event.restored', $event, $staff, metadata: array_filter([
            'status' => $status,
            'waits_for_suspension' => $waitsForSuspension ?: null,
            'note' => $note ? trim($note) : null,
            'organizers_told' => $told->count(),
        ], fn ($value) => $value !== null));

        // Waiting for the suspension, "publish it from the console" would be
        // refused: it goes back on sale by itself when that is lifted.
        $told->each(fn (string $email) => Mail::to($email)->send(new EventRestored($event, $waitsForSuspension)));

        return $status;
    }

    /**
     * The event, locked, or a refusal if the organizer has since deleted it.
     *
     * Looked up with the deleted ones so that case is a sentence rather than
     * a 404: the admin lists and opens deleted events, and an occurrence with
     * no tickets can be deleted while it is taken down. A deleted event is
     * off the site already, and lifting a takedown on it would publish
     * nothing.
     */
    private function lock(Event $event): Event
    {
        /** @var Event $locked */
        $locked = Event::withTrashed()->whereKey($event->id)->lockForUpdate()->firstOrFail();

        if ($locked->trashed()) {
            throw StaffActionRefused::because('This event was deleted by the organizer.');
        }

        return $locked;
    }

    /**
     * Read from the takedown's own entry in the trail.
     *
     * The event row forgets what it was before; the audit entry was written
     * for exactly this question. Without one — a failed audit write — it stays
     * a draft, which is the answer that cannot put anything on sale by
     * mistake.
     *
     * An event taken down while its organization was suspended was a draft
     * only because of the suspension, and was on sale before it. Counted as
     * published, so the order staff lift the two in does not decide whether
     * it goes back on sale.
     */
    private function wasPublishedWhenTakenDown(Event $event): bool
    {
        $entry = AuditLog::query()
            ->where('action', 'event.taken_down')
            ->where('subject_type', Event::class)
            ->where('subject_id', $event->id)
            ->latest('created_at')
            ->first();

        return ($entry?->metadata['from'] ?? null) === 'published'
            || ($entry?->metadata['off_sale_for_suspension'] ?? false) === true;
    }

    /**
     * Who hears about it: everybody at the organization who could publish it.
     *
     * The people who can act on the reason. Falls back to the organization's
     * contact address when nobody holds that role.
     *
     * @return Collection<int, string>
     */
    private function organizers(Event $event): Collection
    {
        $roles = collect(Role::cases())
            ->filter(fn (Role $role) => in_array(Permission::EventsPublish, Permission::forRole($role), true))
            ->map(fn (Role $role) => $role->value)
            ->values()
            ->all();

        $emails = $event->organization
            ?->members()
            ->wherePivotIn('role', $roles)
            ->whereNotNull('users.email')
            ->pluck('users.email')
            ->map(fn (string $email) => strtolower($email))
            ->reject(fn (string $email) => str_ends_with($email, '@erased.invalid'))
            ->unique()
            ->values() ?? collect();

        if ($emails->isEmpty() && filled($event->organization?->contact_email)) {
            return collect([$event->organization->contact_email]);
        }

        return $emails;
    }
}
