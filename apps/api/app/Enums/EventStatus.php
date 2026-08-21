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
 * This is the real model. Three stored states, and one derived fact — whether
 * the event has already happened — which is read from the clock rather than
 * stored, because a stored flag needs a job to maintain it and drifts the first
 * time that job does not run.
 */
enum EventStatus: string
{
    /** Being built. Not visible to anybody outside the organization. */
    case Draft = 'draft';

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
            // Published, or abandoned and deleted. Cancelling something nobody
            // could buy is meaningless — there is nobody to tell.
            self::Draft => [self::Published],

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
     * rewrites what they were told.
     */
    public function isEditable(): bool
    {
        return $this !== self::Cancelled;
    }

    public function isTerminal(): bool
    {
        return $this === self::Cancelled;
    }
}
