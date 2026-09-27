<?php

namespace App\Services\Organizations;

use App\Enums\Role;
use App\Mail\OrganizationSuspended;
use App\Mail\OrganizationUnsuspended;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\StaffSupport\StaffAction;
use App\Services\StaffSupport\StaffActionRefused;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The platform stopping, and starting again, for a whole organization.
 *
 * Suspending takes every event it has on sale off sale, stops anything being
 * sold — online, at the door, or handed back for resale — and freezes its
 * payouts. It deletes nothing and takes nothing from anybody who already
 * paid: their tickets stay valid, the door still lets them in, and refunds
 * can still be made, because the people who bought a ticket in good faith are
 * the last people a suspension is aimed at.
 *
 * Lifting it puts back exactly what it took. That is why each part is
 * remembered rather than worked out again afterwards:
 *
 * - the events that were on sale carry a mark, and only they go back on sale
 *   — not a draft, and not an event the organizer had taken off sale
 *   themselves, which would otherwise reappear on the site without anybody
 *   asking for it. Taking one off sale while the suspension lasts clears its
 *   mark for the same reason (EventController::publish);
 * - payout requests waiting at the time are held, not rejected, and go back
 *   to waiting with their place in the queue.
 *
 * Suspending twice changes nothing the first time did: the marks are only
 * ever added, so a second click can never lose the list. It does sweep up
 * anything that went on sale in between — a takedown lifted, say — so the
 * two always agree about what "suspended" means. Everything happens in one
 * transaction against the locked organization row; the trail is written and
 * the owners are emailed once it has committed.
 *
 * Administrators only (StaffAction::Suspend), checked here as well as on the
 * button.
 */
class Suspension
{
    /** Short enough to type, long enough to be a reason rather than a word. */
    public const MIN_REASON = 10;

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * Whether sales and payouts are stopped for this organization, read from
     * the row now rather than from whatever copy the caller holds.
     *
     * Everything that sells or pays out asks this: a checkout holding an
     * organization loaded before the suspension must not sell on it.
     */
    public static function inForce(Organization|string|null $organization): bool
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        if ($id === null || $id === '') {
            return false;
        }

        return Organization::withTrashed()->whereKey($id)->whereNotNull('suspended_at')->exists();
    }

    /**
     * Stop selling and paying out for this organization.
     *
     * @param  bool  $shareReason  whether the organization is shown the reason, in the
     *                             console and in the email. Kept on the record either way.
     * @return array{already: bool, events: list<string>, held: list<string>, told: int}
     *
     * @throws StaffActionRefused
     */
    public function suspend(Organization $organization, User $staff, string $reason, bool $shareReason = false): array
    {
        StaffAction::Suspend->authorize($staff);

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON) {
            throw StaffActionRefused::because('Give a reason of at least a sentence. It stays on the record, and the organization is shown it if you choose.');
        }

        $done = DB::transaction(function () use ($organization, $staff, $reason, $shareReason) {
            $locked = $this->lock($organization);

            $already = $locked->suspended_at !== null;

            if (! $already) {
                $locked->forceFill([
                    'suspended_at' => now(),
                    'suspended_by' => $staff->id,
                    'suspension_reason' => mb_substr($reason, 0, 1000),
                    'suspension_reason_shared' => $shareReason,
                ])->save();
            }

            // Every event on sale now. Marked one by one rather than in a
            // single update, so each keeps its own entry in the trail below
            // and anything listening to an event being saved hears about it.
            $events = Event::query()
                ->where('organization_id', $locked->id)
                ->where('status', 'published')
                ->lockForUpdate()
                ->get();

            foreach ($events as $event) {
                $event->forceFill([
                    'status' => 'draft',
                    'unpublished_by_suspension_at' => now(),
                ])->save();
            }

            // Waiting requests keep their status and their place; holding is
            // a flag the pay button and the service both refuse on.
            $held = PayoutRequest::query()
                ->where('organization_id', $locked->id)
                ->where('status', 'pending')
                ->whereNull('held_at')
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            if ($held !== []) {
                PayoutRequest::query()->whereKey($held)->update(['held_at' => now(), 'updated_at' => now()]);
            }

            return [
                'already' => $already,
                'events' => $events->modelKeys(),
                'held' => $held,
            ];
        });

        $organization->refresh();

        if ($done['already'] && $done['events'] === [] && $done['held'] === []) {
            return $done + ['told' => 0];
        }

        $owners = $done['already'] ? collect() : $this->owners($organization);

        // The reason on the record, which a second click does not replace.
        $this->auditor->record('organization.suspended', $organization, $staff, $organization->id, array_filter([
            'reason' => $organization->suspension_reason,
            'reason_shared' => $organization->suspension_reason_shared,
            'events_unpublished' => $done['events'],
            'payout_requests_held' => $done['held'],
            'owners_told' => $owners->count(),
            // Suspended already: this only took off sale what had gone back
            // on since, and nobody was emailed a second time.
            'again' => $done['already'] ?: null,
        ], fn ($value) => $value !== null));

        $this->recordEach($done['events'], $done['held'], $staff, $organization, suspended: true);

        $owners->each(fn (string $email) => Mail::to($email)->send(new OrganizationSuspended(
            $organization,
            $shareReason ? $reason : null,
            count($done['events']),
        )));

        return $done + ['told' => $owners->count()];
    }

    /**
     * Start selling and paying out again, putting back what the suspension took.
     *
     * Back on sale: the events it took off sale that have not started and
     * have not since been cancelled, deleted or taken down. The rest stay
     * drafts — an event that has already happened stays off the site. An
     * event the organizer took off sale themselves while it lasted carries
     * no mark any more (their own unpublish clears it), so it is not here to
     * put back.
     *
     * @return array{lifted: bool, republished: list<string>, left: array<string, string>, released: list<string>, told: int}
     *
     * @throws StaffActionRefused
     */
    public function unsuspend(Organization $organization, User $staff, ?string $note = null): array
    {
        StaffAction::Suspend->authorize($staff);

        $done = DB::transaction(function () use ($organization) {
            $locked = $this->lock($organization);

            if ($locked->suspended_at === null) {
                return null;
            }

            $was = [
                'suspended_at' => $locked->suspended_at?->toIso8601String(),
                'reason' => $locked->suspension_reason,
            ];

            $locked->forceFill([
                'suspended_at' => null,
                'suspended_by' => null,
                'suspension_reason' => null,
                'suspension_reason_shared' => false,
            ])->save();

            $republished = [];
            $left = [];

            $marked = Event::withTrashed()
                ->where('organization_id', $locked->id)
                ->whereNotNull('unpublished_by_suspension_at')
                ->lockForUpdate()
                ->get();

            foreach ($marked as $event) {
                $why = $this->whyItStaysOff($event);

                $event->forceFill($why === null
                    ? ['status' => 'published', 'published_at' => $event->published_at ?? now(), 'unpublished_by_suspension_at' => null]
                    : ['unpublished_by_suspension_at' => null])->save();

                if ($why === null) {
                    $republished[] = $event->id;
                } else {
                    $left[$event->id] = $why;
                }
            }

            $released = PayoutRequest::query()
                ->where('organization_id', $locked->id)
                ->where('status', 'pending')
                ->whereNotNull('held_at')
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            if ($released !== []) {
                PayoutRequest::query()->whereKey($released)->update(['held_at' => null, 'updated_at' => now()]);
            }

            return ['was' => $was, 'republished' => $republished, 'left' => $left, 'released' => $released];
        });

        if ($done === null) {
            return ['lifted' => false, 'republished' => [], 'left' => [], 'released' => [], 'told' => 0];
        }

        $organization->refresh();

        $owners = $this->owners($organization);

        $this->auditor->record('organization.unsuspended', $organization, $staff, $organization->id, array_filter([
            'note' => filled($note) ? trim($note) : null,
            'suspended_at' => $done['was']['suspended_at'],
            'reason' => $done['was']['reason'],
            'events_republished' => $done['republished'],
            'events_left_unpublished' => $done['left'] ?: null,
            'payout_requests_released' => $done['released'],
            'owners_told' => $owners->count(),
        ], fn ($value) => $value !== null));

        $this->recordEach($done['republished'], $done['released'], $staff, $organization, suspended: false);

        $titles = fn (array $ids) => Event::withTrashed()->whereKey($ids)->orderBy('starts_at')->pluck('title')->all();

        $back = $titles($done['republished']);
        $stayed = $titles(array_keys($done['left']));

        $owners->each(fn (string $email) => Mail::to($email)->send(new OrganizationUnsuspended($organization, $back, $stayed)));

        return [
            'lifted' => true,
            'republished' => $done['republished'],
            'left' => $done['left'],
            'released' => $done['released'],
            'told' => $owners->count(),
        ];
    }

    /**
     * Why a remembered event does not go back on sale, or null when it does.
     *
     * Only what has happened to it since: deleted, cancelled or taken down,
     * or its night has come. Not the publish button's test of a ticket type
     * on sale. The event passed that when it was published, and was on sale
     * as it stood — a presale sold only through hidden types, or a page kept
     * up after its types closed. Asked again here, it would take such an
     * event off the site for good, since that same button would then refuse
     * the organizer too.
     */
    private function whyItStaysOff(Event $event): ?string
    {
        return match (true) {
            $event->trashed() => 'deleted by the organizer',
            $event->status !== 'draft' => 'now '.$event->status,
            $event->taken_down_at !== null => 'taken down by myFiesta',
            $event->starts_at === null || ! $event->starts_at->isFuture() => 'already happened',
            default => null,
        };
    }

    /**
     * One entry per event and per payout request, on each one's own trail.
     *
     * The organization's entry says what the suspension did; these say, on
     * the event's page and the request's, why it went off sale or waited —
     * which is where somebody asking about that one thing will look.
     *
     * @param  list<string>  $events
     * @param  list<string>  $requests
     */
    private function recordEach(array $events, array $requests, User $staff, Organization $organization, bool $suspended): void
    {
        $because = $suspended ? 'organization.suspended' : 'organization.unsuspended';

        foreach (Event::withTrashed()->whereKey($events)->get() as $event) {
            $this->auditor->record($suspended ? 'event.unpublished' : 'event.published', $event, $staff, $organization->id, [
                'because' => $because,
                'from' => $suspended ? 'published' : 'draft',
            ]);
        }

        foreach (PayoutRequest::query()->whereKey($requests)->get() as $request) {
            $this->auditor->record($suspended ? 'payout_request.held' : 'payout_request.released', $request, $staff, $organization->id, [
                'because' => $because,
                'amount' => $request->amount,
                'currency' => $request->currency,
            ]);
        }
    }

    /**
     * The organization, locked, or a refusal if it has been deleted.
     */
    private function lock(Organization $organization): Organization
    {
        /** @var Organization $locked */
        $locked = Organization::withTrashed()->whereKey($organization->id)->lockForUpdate()->firstOrFail();

        if ($locked->trashed()) {
            throw StaffActionRefused::because('This organization has been deleted.');
        }

        return $locked;
    }

    /**
     * Who is told: the organization's owners.
     *
     * The people who answer for the organization and can do something about
     * the reason. Falls back to its contact address when it has no owner with
     * an address we can write to.
     *
     * @return Collection<int, string>
     */
    private function owners(Organization $organization): Collection
    {
        $emails = $organization->members()
            ->wherePivot('role', Role::Owner->value)
            ->whereNotNull('users.email')
            ->pluck('users.email')
            ->map(fn (string $email) => strtolower($email))
            ->reject(fn (string $email) => str_ends_with($email, '@erased.invalid'))
            ->unique()
            ->values();

        if ($emails->isEmpty() && filled($organization->contact_email)) {
            return collect([strtolower((string) $organization->contact_email)]);
        }

        return $emails;
    }
}
