<?php

namespace App\Services\Events;

use App\Enums\EventStatus;
use App\Enums\Permission;
use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Mail\EventApproved;
use App\Mail\EventAwaitingReview;
use App\Mail\EventRejected;
use App\Mail\EventSubmittedForReview;
use App\Mail\EventWithdrawnFromReview;
use App\Models\Event;
use App\Models\EventReview;
use App\Models\EventSeries;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Follows\Announcements;
use App\Services\Organizations\Suspension;
use App\Services\Organizations\WhileSuspended;
use App\Services\StaffSupport\StaffActionRefused;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Somebody at myFiesta looks at every event before it goes on sale.
 *
 * The organizer sends a draft for review. While it waits it is frozen: what
 * staff approve has to be what goes on sale, so every write that would change
 * what a buyer sees or pays is refused until it is decided or the organizer
 * takes it back (refuseWhileInReview). Staff approve it — it goes on sale, the
 * organizer's followers hear about it the first time — or send it back to a
 * draft with a reason the organizer is sent as written.
 *
 * Nothing reaches on sale without an approval, and an approval is kept: when,
 * by whom, and a fingerprint of what it approved (EventSnapshot). That is what
 * lets a few moves skip the queue without skipping the rule:
 *
 * - putting back on sale an event the organizer took off, when nothing a buyer
 *   sees has changed since it was approved. Anything changed goes back through
 *   review — including edits made while it was on sale, which are allowed
 *   without review but were never approved;
 * - the next date of an approved series, when it is the approved night on
 *   another date;
 * - staff lifting a takedown, which is a decision about that event by the
 *   people who would have reviewed it (recordApproval);
 * - lifting a suspension, which puts back what it took off sale as it was
 *   then and nothing changed since (Suspension::unsuspend). It is a decision
 *   about the organization, not a look at each listing, so it approves
 *   nothing.
 *
 * None of them outlasts a rejection: once staff send an event back, only
 * another approval puts it on sale. And a decision is made on what the
 * reviewer saw — the review page passes it, and an event that changed since
 * is refused until they look again — by somebody other than whoever sent it.
 *
 * Every step is one transaction against the locked event, recorded in the
 * event's review history and in the audit trail, and repeating one changes
 * nothing the first did and sends nothing twice. Emails go once it has
 * committed: to the people at the organization who can put events on sale, and
 * — for a new submission — to the staff who review them.
 *
 * Administrators and support decide. Finance can read the queue and the review
 * page, and cannot approve or reject: reviewing a listing is support's work,
 * and finance's panel access is for money.
 */
final class EventReviews
{
    /** Long enough to be something the organizer can act on. */
    public const MIN_REASON = 20;

    public const MAX_REASON = 2000;

    public const LOCKED = 'This event is being reviewed. Withdraw it to make changes.';

    public const LOCKED_CODE = 'event_in_review';

    /** A decision on a review page opened before the event last changed. */
    public const CHANGED_SINCE_OPENED = 'This event has changed since you opened it. Reload the page and look at it again.';

    public const OWN_SUBMISSION = 'You sent this event for review yourself, so another member of staff decides it.';

    /** How an approval came about, as the history records it. */
    public const VIA_REVIEW = 'review';

    public const VIA_TAKEDOWN_LIFTED = 'takedown_lifted';

    /**
     * No longer written: lifting a suspension approves nothing now
     * (Suspension::unsuspend). Kept so a history written before still reads.
     */
    public const VIA_SUSPENSION_LIFTED = 'suspension_lifted';

    public const VIA_SERIES = 'series';

    public const VIA_EXISTING = 'existing';

    public const VIA_IMPORTED = 'imported';

    public function __construct(
        private readonly Auditor $auditor,
        private readonly Announcements $announcements,
    ) {}

    // --- who ---------------------------------------------------------------------

    /** @return list<PlatformRole> */
    public static function deciders(): array
    {
        return [PlatformRole::Admin, PlatformRole::Support];
    }

    /** Whether this member of staff may approve or reject. */
    public static function mayDecide(?User $staff): bool
    {
        return $staff !== null
            && $staff->deleted_at === null
            && $staff->hasPlatformRole(...self::deciders());
    }

    /** For a panel closure: may whoever is signed in decide? */
    public static function currentMayDecide(): bool
    {
        $user = auth()->user();

        return $user instanceof User && self::mayDecide($user);
    }

    // --- the lock ------------------------------------------------------------------

    /**
     * Refuse a change to what buyers see while the event is being reviewed.
     *
     * Called by every organizer write that changes the listing or the price —
     * the event itself, ticket types, add-ons, pictures, questions, the series
     * and codes — after the caller is authorised, so somebody outside the
     * organization learns nothing from it. 423: the event is locked, and the
     * sentence says what unlocks it.
     *
     * @throws HttpResponseException
     */
    public static function refuseWhileInReview(Event $event): void
    {
        if ($event->status === EventStatus::InReview->value) {
            throw new HttpResponseException(response()->json([
                'message' => self::LOCKED,
                'code' => self::LOCKED_CODE,
            ], 423));
        }
    }

    // --- the organizer -----------------------------------------------------------

    /**
     * What stops this event being sent for review, one plain sentence each.
     *
     * Empty when it is ready. Where an approval still stands for the event as
     * it is — sending it would put it straight on sale — only its dates are
     * asked about. Staff approved the listing as it says, so what it says is
     * not held to the checks again: an event on sale before reviews began
     * with no description, or a presale sold only through hidden tickets,
     * goes back on sale as it was approved rather than being told it can and
     * then refused. The calendar is the one thing that moves on by itself,
     * and an approval that still stands does not make last month's date
     * sellable.
     *
     * @return list<string>
     */
    public function notReadyBecause(Event $event): array
    {
        return $this->reasonsNotReady($event, $this->approvedAsItIs($event, EventSnapshot::of($event)));
    }

    /**
     * What sending this event now would do: `publish` when an approval still
     * stands for it as it is, `review` otherwise, null when it cannot be sent.
     *
     * Asked by the console before it offers the button, so the organizer is
     * told which will happen before they confirm.
     */
    public function whatSubmittingDoes(Event $event): ?string
    {
        if ($event->status !== EventStatus::Draft->value || $event->taken_down_at !== null) {
            return null;
        }

        return $this->approvedAsItIs($event, EventSnapshot::of($event)) ? 'publish' : 'review';
    }

    /**
     * Whether what a buyer sees is still exactly what was last approved, and
     * that approval still stands.
     *
     * False for an event never approved, or sent back since. Asked about an
     * event on sale, it is the answer to "if I take it off, can I put it
     * straight back?".
     */
    public function unchangedSinceApproval(Event $event): bool
    {
        return $event->approved_fingerprint !== null
            && hash_equals($event->approved_fingerprint, EventSnapshot::fingerprint(EventSnapshot::of($event)))
            && ! $this->rejectedSinceApproval($event);
    }

    /**
     * Send a draft for review — or, where an approval still stands for it as
     * it is, straight back on sale.
     *
     * @return array{status: string, outcome: string, message: string}
     *
     * @throws ReviewRefused
     */
    public function submit(Event $event, User $by): array
    {
        $done = DB::transaction(function () use ($event, $by) {
            $locked = $this->lock($event);
            $status = EventStatus::from($locked->status);

            if ($status === EventStatus::InReview) {
                return ['outcome' => 'already', 'event' => $locked];
            }

            if ($status === EventStatus::Published) {
                return ['outcome' => 'already', 'event' => $locked];
            }

            if ($status === EventStatus::Cancelled) {
                throw new ReviewRefused('A cancelled event cannot go back on sale. Copy it to a new date instead.');
            }

            // Taken off sale by the platform (EventModeration). Only staff
            // lift that; otherwise a takedown is undone by the next click.
            if ($locked->taken_down_at !== null) {
                throw new ReviewRefused('myFiesta has taken this event off sale: '.$locked->taken_down_reason
                    .' Reply to the email we sent to have it looked at again.');
            }

            // Checked here as well as by route (SuspensionBoundary): nothing
            // of a suspended organization's goes on sale or into the queue.
            if (Suspension::inForce($locked->organization_id)) {
                throw new ReviewRefused(WhileSuspended::withContact(WhileSuspended::PUBLISH), 403, errorCode: 'organization_suspended');
            }

            $snapshot = EventSnapshot::of($locked);
            $standing = $this->standingApproval($locked, $snapshot);
            $source = $standing ? null : $this->approvedSeriesDate($locked, $snapshot);

            $reasons = $this->reasonsNotReady($locked, $standing || $source !== null);

            if ($reasons !== []) {
                throw new ReviewRefused(
                    count($reasons) === 1 ? $reasons[0] : 'This event is not ready yet. '.implode(' ', $reasons),
                    422,
                    $reasons,
                );
            }

            if ($standing) {
                $this->goOnSale($locked);

                return ['outcome' => 'republished', 'event' => $locked];
            }

            if ($source !== null) {
                $this->approveWith($locked, null, self::VIA_SERIES, $snapshot);
                $this->goOnSale($locked);

                return ['outcome' => 'series', 'event' => $locked, 'source' => $source];
            }

            $locked->forceFill([
                'status' => EventStatus::InReview->value,
                'submitted_at' => now(),
            ])->save();

            // Who sent it, so whoever sent it is not who approves it —
            // staff acting as the organization included (approve()).
            $this->remember($locked, EventReview::SUBMITTED, $by, null, $snapshot);

            return ['outcome' => 'in_review', 'event' => $locked];
        });

        /** @var Event $locked */
        $locked = $done['event'];
        $event->setRawAttributes($locked->getAttributes(), true);

        return match ($done['outcome']) {
            'already' => [
                'status' => $locked->status,
                'outcome' => 'already',
                'message' => $locked->status === EventStatus::InReview->value
                    ? 'This event is already waiting for review.'
                    : 'This event is already on sale.',
            ],
            'republished' => $this->afterGoingOnSale($locked, $by, ['unchanged_since_approval' => true]),
            'series' => $this->afterGoingOnSale($locked, $by, ['series_source_event_id' => $done['source']->id]),
            default => $this->afterSubmitting($locked, $by),
        };
    }

    /**
     * Take an event back from review, to change something.
     *
     * @return array{status: string, outcome: string, message: string}
     *
     * @throws ReviewRefused
     */
    public function withdraw(Event $event, User $by): array
    {
        $done = DB::transaction(function () use ($event, $by) {
            $locked = $this->lock($event);

            if ($locked->status !== EventStatus::InReview->value) {
                // Pressed twice, or decided in between: the second press has
                // nothing to take back.
                if ($locked->status === EventStatus::Draft->value) {
                    return ['outcome' => 'already', 'event' => $locked];
                }

                throw new ReviewRefused('This event is not waiting for review.');
            }

            $locked->forceFill([
                'status' => EventStatus::Draft->value,
                'submitted_at' => null,
            ])->save();

            $this->remember($locked, EventReview::WITHDRAWN, $by);

            return ['outcome' => 'withdrawn', 'event' => $locked];
        });

        /** @var Event $locked */
        $locked = $done['event'];
        $event->setRawAttributes($locked->getAttributes(), true);

        if ($done['outcome'] === 'already') {
            return ['status' => $locked->status, 'outcome' => 'already', 'message' => 'This event is a draft. You can change it.'];
        }

        $this->auditor->record('event.withdrawn_from_review', $locked, $by);

        $this->tellOrganizers($locked, fn () => new EventWithdrawnFromReview($locked));

        return [
            'status' => EventStatus::Draft->value,
            'outcome' => 'withdrawn',
            'message' => 'Taken back from review. Make your changes, then send it again.',
        ];
    }

    // --- staff ---------------------------------------------------------------------

    /**
     * Approve: it goes on sale.
     *
     * What goes on sale has to be what the reviewer looked at. The review
     * page passes the fingerprint of the event as it showed it ($seen), and
     * the approval is refused if the event says anything else now — the
     * organizer took it back, changed it and sent it again while the page
     * was open. And whoever sent it for review does not approve it: staff
     * acting as the organization can prepare and send an event, and somebody
     * else at myFiesta decides it.
     *
     * When the organization is suspended it waits instead, marked with the
     * events the suspension took off sale, and goes on sale with them when it
     * is lifted (Suspension::unsuspend) — as it was approved, and not if it
     * changed while it waited.
     *
     * @param  string|null  $seen  the fingerprint (EventSnapshot) of the event as the
     *                             reviewer was shown it
     * @return string the status it is left in
     *
     * @throws StaffActionRefused
     */
    public function approve(Event $event, User $staff, ?string $seen = null): string
    {
        $this->authorize($staff);

        $done = DB::transaction(function () use ($event, $staff, $seen) {
            $locked = $this->lock($event);

            if ($locked->trashed()) {
                throw StaffActionRefused::because('This event was deleted by the organizer.');
            }

            if ($locked->status !== EventStatus::InReview->value) {
                if ($this->lastStep($locked)?->action === EventReview::APPROVED) {
                    return ['outcome' => 'already', 'event' => $locked, 'held' => false];
                }

                throw StaffActionRefused::because($this->noLongerWaiting($locked));
            }

            if (! $locked->starts_at->isFuture()) {
                throw StaffActionRefused::because('This event has already started. Send it back so the organizer can change the date.');
            }

            $snapshot = EventSnapshot::of($locked);

            $this->refuseIfChangedSince($seen, $snapshot);

            $submitter = $this->lastSubmission($locked)?->actor_id;

            if ($submitter !== null && $submitter === $staff->id) {
                throw StaffActionRefused::because(self::OWN_SUBMISSION);
            }

            $waited = $this->waited($locked);

            $this->approveWith($locked, $staff, self::VIA_REVIEW, $snapshot);

            $held = Suspension::inForce($locked->organization_id);

            if ($held) {
                $locked->forceFill([
                    'status' => EventStatus::Draft->value,
                    'submitted_at' => null,
                    'unpublished_by_suspension_at' => now(),
                    // What was approved is what lifting the suspension puts
                    // on sale, and nothing edited while it waits.
                    'unpublished_by_suspension_fingerprint' => $locked->approved_fingerprint,
                ])->save();
            } else {
                $this->goOnSale($locked);
            }

            return ['outcome' => 'approved', 'event' => $locked, 'held' => $held, 'waited' => $waited];
        });

        /** @var Event $locked */
        $locked = $done['event'];
        $event->setRawAttributes($locked->getAttributes(), true);

        if ($done['outcome'] === 'already') {
            return $locked->status;
        }

        self::scheduleDefaultReminders($locked);

        // Its followers hear about it the first time it goes on sale, never
        // when it is sent for review.
        $told = $done['held'] ? 0 : $this->announcements->announce($locked);

        $this->auditor->record('event.approved', $locked, $staff, metadata: array_filter([
            'followers_told' => $told ?: null,
            'waits_for_suspension' => $done['held'] ?: null,
            'waited_minutes' => $done['waited'] ?? null,
        ], fn ($value) => $value !== null));

        $this->tellOrganizers($locked, fn () => new EventApproved($locked, $done['held']));

        return $locked->status;
    }

    /**
     * Send it back to a draft, with a reason the organizer is sent as written.
     *
     * A rejection is staff saying no to the event, not only to the change in
     * front of them: until another member of staff approves it, no approval
     * from before puts it back on sale (rejectedSinceApproval). Like an
     * approval, it is refused when the event changed after the review page
     * showed it ($seen): a reason written about one version is no use
     * against another.
     *
     * @param  string|null  $seen  the fingerprint of the event as the reviewer was shown it
     *
     * @throws StaffActionRefused
     */
    public function reject(Event $event, User $staff, string $reason, ?string $seen = null): void
    {
        $this->authorize($staff);

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON) {
            throw StaffActionRefused::because('Give the organizer a reason they can act on — at least '.self::MIN_REASON.' characters. It is emailed to them as written.');
        }

        if (mb_strlen($reason) > self::MAX_REASON) {
            throw StaffActionRefused::because('Keep the reason under '.self::MAX_REASON.' characters.');
        }

        $done = DB::transaction(function () use ($event, $staff, $reason, $seen) {
            $locked = $this->lock($event);

            if ($locked->trashed()) {
                throw StaffActionRefused::because('This event was deleted by the organizer.');
            }

            if ($locked->status !== EventStatus::InReview->value) {
                // Pressed twice: still the draft the first press left.
                if ($locked->status === EventStatus::Draft->value && $this->lastStep($locked)?->action === EventReview::REJECTED) {
                    return ['outcome' => 'already', 'event' => $locked];
                }

                throw StaffActionRefused::because($this->noLongerWaiting($locked));
            }

            if ($seen !== null) {
                $this->refuseIfChangedSince($seen, EventSnapshot::of($locked));
            }

            $waited = $this->waited($locked);

            $locked->forceFill([
                'status' => EventStatus::Draft->value,
                'submitted_at' => null,
            ])->save();

            $this->remember($locked, EventReview::REJECTED, $staff, $reason);

            return ['outcome' => 'rejected', 'event' => $locked, 'waited' => $waited];
        });

        /** @var Event $locked */
        $locked = $done['event'];
        $event->setRawAttributes($locked->getAttributes(), true);

        if ($done['outcome'] === 'already') {
            return;
        }

        $this->auditor->record('event.rejected', $locked, $staff, metadata: array_filter([
            'reason' => $reason,
            'waited_minutes' => $done['waited'] ?? null,
        ], fn ($value) => $value !== null));

        $this->tellOrganizers($locked, fn () => new EventRejected($locked, $reason));
    }

    /**
     * Record an approval that did not come from the queue: staff lifting a
     * takedown, events on sale before reviews existed, and imported ones.
     *
     * The caller changes the status; this only remembers that what is on sale
     * now was approved, so taking it off and putting it back unchanged later
     * does not send it through review.
     */
    public function recordApproval(Event $event, ?User $by, string $via): void
    {
        $this->approveWith($event, $by, $via, EventSnapshot::of($event));
    }

    // --- reading -----------------------------------------------------------------

    /**
     * The rejection the organizer has not yet answered, if the event's last
     * step is one.
     */
    public function standingRejection(Event $event): ?EventReview
    {
        $last = $this->lastStep($event);

        return $last?->action === EventReview::REJECTED ? $last : null;
    }

    /** The approval that stands, with the content it approved. */
    public function lastApproval(Event $event): ?EventReview
    {
        if ($event->approved_fingerprint === null) {
            return null;
        }

        return EventReview::query()
            ->where('event_id', $event->id)
            ->where('action', EventReview::APPROVED)
            ->orderByDesc('seq')
            ->first();
    }

    /**
     * What a buyer would see differently from what was last approved.
     *
     * Null when it has never been approved; an empty list when nothing has
     * changed.
     *
     * @return list<string>|null
     */
    public function changesSinceApproval(Event $event): ?array
    {
        $approval = $this->lastApproval($event);

        if ($approval === null || ! is_array($approval->snapshot)) {
            return null;
        }

        return EventSnapshot::changes($approval->snapshot, EventSnapshot::of($event));
    }

    /**
     * The history, as the organizer is shown it: newest first, staff named as
     * myFiesta rather than by name.
     *
     * @return list<array<string, mixed>>
     */
    public function historyFor(Event $event, int $limit = 20): array
    {
        return EventReview::query()
            ->where('event_id', $event->id)
            ->orderByDesc('seq')
            ->limit($limit)
            ->get()
            ->map(fn (EventReview $step) => [
                'action' => $step->action,
                'via' => $step->via,
                'reason' => $step->reason,
                'at' => $step->created_at?->toIso8601String(),
                'by' => match ($step->action) {
                    EventReview::APPROVED, EventReview::REJECTED => 'myFiesta',
                    default => $step->actor_label,
                },
            ])
            ->values()
            ->all();
    }

    // --- recipients ----------------------------------------------------------------

    /**
     * Everybody at the organization who could put the event on sale.
     *
     * The people who can act on a reason. Falls back to the organization's
     * contact address when nobody holds such a role.
     *
     * @return list<string>
     */
    public function organizers(Event $event): array
    {
        $roles = collect(Role::cases())
            ->filter(fn (Role $role) => in_array(Permission::EventsPublish, Permission::forRole($role), true))
            ->map(fn (Role $role) => $role->value)
            ->values()
            ->all();

        $organization = $event->organization()->withTrashed()->first();

        $emails = $organization
            ?->members()
            ->wherePivotIn('role', $roles)
            ->whereNotNull('users.email')
            ->pluck('users.email')
            ->map(fn (string $email): string => Str::lower($email))
            ->reject(fn (string $email) => str_ends_with($email, '@erased.invalid'))
            ->unique()
            ->values()
            ->all() ?? [];

        if ($emails === [] && filled($organization?->contact_email)) {
            return [Str::lower((string) $organization->contact_email)];
        }

        return $emails;
    }

    /**
     * Who hears that a new event is waiting: the addresses configured, or
     * else every member of staff who can decide.
     *
     * @return list<string>
     */
    public function reviewers(): array
    {
        $configured = collect((array) config('events.review.notify', []))
            ->map(fn ($email): string => Str::lower(trim((string) $email)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($configured !== []) {
            return $configured;
        }

        return User::query()
            ->whereIn('platform_role', (array) config('events.review.notify_roles', ['admin', 'support']))
            ->whereNotNull('email')
            ->whereNotNull('email_verified_at')
            ->pluck('email')
            ->map(fn (string $email): string => Str::lower($email))
            ->reject(fn (string $email) => str_ends_with($email, '@erased.invalid'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Reminders an organizer did not have to think about.
     *
     * A week out to plan around, the day before to remember, and three hours
     * out for anyone who has not left yet. firstOrCreate rather than create,
     * so going back on sale does not resurrect a reminder the organizer
     * deliberately turned off, and so an event that was taken down and put
     * back does not send twice. Skipped for anything starting sooner than the
     * offset, since a reminder for a moment already past is not something to
     * write down and then decline to send.
     */
    public static function scheduleDefaultReminders(Event $event): void
    {
        foreach ([7 * 24 * 60, 24 * 60, 3 * 60] as $minutes) {
            if ($event->starts_at->copy()->subMinutes($minutes)->isPast()) {
                continue;
            }

            $event->reminders()->firstOrCreate(
                ['offset_minutes' => $minutes],
                ['status' => 'scheduled'],
            );
        }
    }

    // --- the parts -----------------------------------------------------------------

    /**
     * Whether an approval stands for the event as it is: sending it would
     * put it straight on sale rather than into the queue.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function approvedAsItIs(Event $event, array $snapshot): bool
    {
        return $this->standingApproval($event, $snapshot) || $this->approvedSeriesDate($event, $snapshot) !== null;
    }

    /**
     * What stops the event going on sale, or into the queue.
     *
     * @param  bool  $approved  whether an approval stands for it as it is, in which
     *                          case only its dates are asked about (notReadyBecause)
     * @return list<string>
     */
    private function reasonsNotReady(Event $event, bool $approved): array
    {
        $reasons = [];

        if (! $approved && trim((string) $event->title) === '') {
            $reasons[] = 'Give the event a title.';
        }

        if (! $approved && trim(strip_tags((string) $event->description)) === '') {
            $reasons[] = 'Write a description, so people know what they are buying a ticket to.';
        }

        if (! $approved && (trim((string) $event->city) === '' || trim((string) $event->country) === '')) {
            $reasons[] = 'Say where the event is.';
        }

        if ($event->starts_at === null || ! $event->starts_at->isFuture()) {
            $reasons[] = 'The start date has passed. Change the date.';
        } elseif ($event->ends_at !== null && ! $event->ends_at->greaterThan($event->starts_at)) {
            $reasons[] = 'The end is before the start. Change one of them.';
        }

        // A listing with nothing to sell is a shared link that disappoints
        // everyone who follows it.
        if (! $approved && $event->kind !== 'invitation' && ! $event->ticketTypes()->where('status', 'on_sale')->exists()) {
            $reasons[] = 'Add at least one ticket on sale.';
        }

        return $reasons;
    }

    /**
     * An approval that still stands for the event as it is now: it was on
     * sale before, nothing a buyer sees has changed since it was approved,
     * and staff have not sent it back since.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function standingApproval(Event $event, array $snapshot): bool
    {
        return $event->approved_fingerprint !== null
            && $event->published_at !== null
            && hash_equals($event->approved_fingerprint, EventSnapshot::fingerprint($snapshot))
            && ! $this->rejectedSinceApproval($event);
    }

    /**
     * Whether staff sent the event back after its last approval.
     *
     * After a rejection only another decision by staff puts the event on
     * sale. Otherwise an organizer who was sent back could restore the last
     * approved listing and put it straight back — a listing nobody at
     * myFiesta may ever have looked at, when it was on sale before reviews
     * began or was imported — and a series date sent back could do the same
     * by matching its source again.
     */
    private function rejectedSinceApproval(Event $event): bool
    {
        return EventReview::query()
            ->where('event_id', $event->id)
            ->whereIn('action', [EventReview::APPROVED, EventReview::REJECTED])
            ->orderByDesc('seq')
            ->value('action') === EventReview::REJECTED;
    }

    /**
     * The approved source of the series this date belongs to, when this date
     * is that approved night on another date.
     *
     * Its own dates, and the sales windows that move with them, aside. A copy
     * carries neither the gallery, the add-ons nor the questions
     * (EventDuplicator), so a date that has none of one of those is compared
     * without it — it offers less than was approved, never something else. A
     * date that has any of them has to have exactly what was approved. Not
     * for a date staff sent back, nor the dates of a source they sent back.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function approvedSeriesDate(Event $event, array $snapshot): ?Event
    {
        if ($event->series_id === null || $this->rejectedSinceApproval($event)) {
            return null;
        }

        $series = EventSeries::query()->find($event->series_id);

        if ($series === null || $series->source_event_id === $event->id) {
            return null;
        }

        $source = Event::query()->find($series->source_event_id);

        if ($source === null || $source->taken_down_at !== null || $source->approved_fingerprint === null
            || $this->rejectedSinceApproval($source)) {
            return null;
        }

        $approved = $this->lastApproval($source)?->snapshot;

        if (! is_array($approved)) {
            return null;
        }

        $mine = EventSnapshot::withoutDates($snapshot);
        $theirs = EventSnapshot::withoutDates($approved);

        foreach (['gallery', 'add_ons', 'questions'] as $part) {
            if (($mine[$part] ?? []) === []) {
                unset($mine[$part], $theirs[$part]);
            }
        }

        return EventSnapshot::fingerprint($mine) === EventSnapshot::fingerprint($theirs) ? $source : null;
    }

    /** @param  array<string, mixed>  $snapshot */
    private function approveWith(Event $event, ?User $by, string $via, array $snapshot): void
    {
        $fingerprint = EventSnapshot::fingerprint($snapshot);

        $event->forceFill([
            'approved_at' => now(),
            'approved_by' => $by?->id,
            'approved_fingerprint' => $fingerprint,
        ])->save();

        $this->remember($event, EventReview::APPROVED, $by, null, $snapshot, $via);
    }

    /** On sale, keeping the first publish date so an old event does not look newly announced. */
    private function goOnSale(Event $event): void
    {
        $event->forceFill([
            'status' => EventStatus::Published->value,
            'submitted_at' => null,
            'published_at' => $event->published_at ?? now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{status: string, outcome: string, message: string}
     */
    private function afterGoingOnSale(Event $event, User $by, array $metadata): array
    {
        self::scheduleDefaultReminders($event);

        // Once per event, the first time it goes on sale: an event put back
        // unchanged was announced when it was approved.
        $told = $this->announcements->announce($event);

        $this->auditor->record('event.published', $event, $by, metadata: $metadata + ($told > 0 ? ['followers_told' => $told] : []));

        return [
            'status' => EventStatus::Published->value,
            'outcome' => 'published',
            'message' => isset($metadata['series_source_event_id'])
                ? 'On sale. It is the approved night on a new date, so it did not need another review.'
                : 'Back on sale. Nothing has changed since it was approved, so it did not need another review.',
        ];
    }

    /** @return array{status: string, outcome: string, message: string} */
    private function afterSubmitting(Event $event, User $by): array
    {
        $this->auditor->record('event.submitted', $event, $by);

        $this->tellOrganizers($event, fn () => new EventSubmittedForReview($event));

        foreach ($this->reviewers() as $email) {
            Mail::to($email)->queue(new EventAwaitingReview($event));
        }

        return [
            'status' => EventStatus::InReview->value,
            'outcome' => 'in_review',
            'message' => 'Sent for review. We will email you when it has been looked at — usually within '
                .config('events.review.typical_wait', 'one working day').'.',
        ];
    }

    /** @param  \Closure(): Mailable  $mail */
    private function tellOrganizers(Event $event, \Closure $mail): void
    {
        foreach ($this->organizers($event) as $email) {
            Mail::to($email)->queue($mail());
        }
    }

    /** @param  array<string, mixed>|null  $snapshot */
    private function remember(Event $event, string $action, ?User $by, ?string $reason = null, ?array $snapshot = null, ?string $via = null): void
    {
        EventReview::create([
            'event_id' => $event->id,
            'action' => $action,
            'via' => $via,
            'actor_id' => $by?->id,
            'actor_label' => $by?->name,
            'reason' => $reason,
            'fingerprint' => $snapshot === null ? null : EventSnapshot::fingerprint($snapshot),
            'snapshot' => $snapshot,
            'created_at' => now(),
        ]);
    }

    private function lastStep(Event $event): ?EventReview
    {
        return EventReview::query()->where('event_id', $event->id)->orderByDesc('seq')->first();
    }

    /** The submission now waiting: the last time it was sent. */
    private function lastSubmission(Event $event): ?EventReview
    {
        return EventReview::query()
            ->where('event_id', $event->id)
            ->where('action', EventReview::SUBMITTED)
            ->orderByDesc('seq')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $snapshot  the event as it is now
     *
     * @throws StaffActionRefused
     */
    private function refuseIfChangedSince(?string $seen, array $snapshot): void
    {
        if ($seen !== null && ! hash_equals($seen, EventSnapshot::fingerprint($snapshot))) {
            throw StaffActionRefused::because(self::CHANGED_SINCE_OPENED);
        }
    }

    private function noLongerWaiting(Event $event): string
    {
        return match ($event->status) {
            EventStatus::Draft->value => 'This event is no longer waiting for review. The organizer took it back to make changes.',
            EventStatus::Published->value => 'This event is already on sale.',
            EventStatus::Cancelled->value => 'This event has been cancelled.',
            default => 'This event is not waiting for review.',
        };
    }

    /** Minutes it has been waiting, for the trail. */
    private function waited(Event $event): ?int
    {
        return $event->submitted_at === null ? null : (int) now()->diffInMinutes($event->submitted_at, true);
    }

    /** @throws StaffActionRefused */
    private function authorize(User $staff): void
    {
        if (! self::mayDecide($staff)) {
            throw StaffActionRefused::because('Only administrators and support review events.');
        }
    }

    /** The event, read again and locked for the length of the step. */
    private function lock(Event $event): Event
    {
        /** @var Event $locked */
        $locked = Event::withTrashed()->whereKey($event->id)->lockForUpdate()->firstOrFail();

        return $locked;
    }
}
