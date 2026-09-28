<?php

namespace App\Enums;

/**
 * The states an event can actually be in, and how it moves between them.
 *
 * The schema permitted five — draft, review, scheduled, published, cancelled —
 * and the API could only ever set two of them. `review` and `scheduled` were
 * never reachable by any code path, and `cancelled` was never reachable at all:
 * there was no way to cancel an event, while the terms page promised refunds
 * when an organizer did.
 *
 * This is the real model. Four stored states, and one derived fact — whether
 * the event has already happened — which is read from the clock rather than
 * stored, because a stored flag needs a job to maintain it and drifts the first
 * time that job does not run.
 *
 * Nothing goes on sale without somebody at myFiesta having looked at it. The
 * organizer sends a draft for review; staff approve it, which puts it on sale,
 * or send it back with a reason. EventReviews owns those moves and the few
 * that skip the queue because the content was already approved.
 */
enum EventStatus: string
{
    /** Being built. Not visible to anybody outside the organization. */
    case Draft = 'draft';

    /**
     * Sent to myFiesta to be looked at.
     *
     * Frozen while it waits: what staff approve has to be what goes on sale,
     * so nothing a buyer would see or pay can change until it is decided or
     * the organizer takes it back.
     */
    case InReview = 'in_review';

    /** On sale. The only state in which money can be taken. */
    case Published = 'published';

    /**
     * Called off.
     *
     * Terminal. An event that was cancelled and then quietly republished is a
     * worse outcome than one that stays cancelled and is copied to a new date —
     * everybody holding a ticket has already been told it is not happening, and
     * some of them have been refunded.
     */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InReview => 'In review',
            self::Published => 'On sale',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Whether this state may become that one.
     *
     * Deliberately a table rather than a set of ifs scattered through
     * controllers. "Can a cancelled event be published?" should have one place
     * to look.
     */
    public function canBecome(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            // Sent for review, or abandoned and deleted. Straight to on sale
            // only with an approval that still stands — a night taken off sale
            // and put back unchanged, or the next date of an approved series —
            // which EventReviews decides. Cancelling something nobody could
            // buy is meaningless: there is nobody to tell.
            self::Draft => [self::InReview, self::Published],

            // Approved, or back to a draft: rejected with a reason, or taken
            // back by the organizer to change something.
            self::InReview => [self::Published, self::Draft],

            // Back to draft hides the link without breaking tickets already
            // sold; cancelling tells everybody it is off.
            self::Published => [self::Draft, self::Cancelled],

            self::Cancelled => [],
        };
    }

    /** Whether tickets may be sold in this state. */
    public function sellsTickets(): bool
    {
        return $this === self::Published;
    }

    /**
     * Whether the shape of the event may still be changed.
     *
     * A cancelled event is a historical record. People bought tickets to what
     * it said, some were refunded on that basis, and editing it afterwards
     * rewrites what they were told. One in review is only paused: taking it
     * back from review opens it again.
     */
    public function isEditable(): bool
    {
        return $this !== self::Cancelled && $this !== self::InReview;
    }

    /** Waiting for myFiesta, and frozen until it is decided or taken back. */
    public function isInReview(): bool
    {
        return $this === self::InReview;
    }

    public function isTerminal(): bool
    {
        return $this === self::Cancelled;
    }
}
