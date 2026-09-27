<?php

namespace App\Services\Checkout;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Services\Door\DoorPasses;
use App\Services\Organizations\Suspension;
use Carbon\CarbonInterface;

/**
 * Why a payment became no tickets.
 *
 * Money can arrive for places that have gone — sold to somebody who paid on
 * time — or for a night that is not happening at all: called off by its
 * organizer, over already, taken down by staff, or put on hold with the rest
 * of its organizer's sales by a suspension. Whichever it is, nothing is
 * issued and all of it goes back (Fulfiller). The buyer is told which it was,
 * because "it sold out while you were paying", said to somebody whose event was
 * cancelled, is untrue, and they will find that out.
 *
 * The reason goes on the refund in words, which is also what the processor is
 * given, and the email reads it back from there (SoldOutWhilePaying). The email
 * can go minutes after the decision — a refund that got no answer is confirmed,
 * and told, when the processor answers — and by then the event may have moved
 * on again. What was decided is what was recorded, so that is what is said.
 */
enum TurnedAway: string
{
    case SoldOut = 'sold_out';
    case Cancelled = 'cancelled';
    case Suspended = 'suspended';
    case Ended = 'ended';
    case TakenDown = 'taken_down';

    /**
     * How long an event with no end time runs.
     *
     * Half a day after it starts, which is the door's own measure: a door pass
     * for an event with no end stops working then too (DoorPasses).
     */
    public const HOURS_WITHOUT_AN_END = 12;

    /**
     * When the night is listed to end: its end time, or half a day after it
     * starts when it has none.
     *
     * Online sales stop here (CheckoutService). Nobody is sold a ticket for a
     * night that is over by its own listing, only to be charged and then sent
     * the money back — which also costs the platform the processor's fee,
     * since no processor returns that on a refund.
     */
    public static function listedEnd(Event $event): ?CarbonInterface
    {
        return $event->ends_at?->copy() ?? $event->starts_at?->copy()->addHours(self::HOURS_WITHOUT_AN_END);
    }

    /** Whether online sales for the event have stopped, as of $at. */
    public static function pastSelling(Event $event, CarbonInterface $at): bool
    {
        $end = self::listedEnd($event);

        return $end !== null && $end->lte($at);
    }

    /**
     * When a ticket for the night stops being any use: the listed end plus the
     * while the door stays open after it, measured the way a door pass is
     * (DoorPasses).
     *
     * Later than online sales stop, and on purpose. A buyer who started paying
     * a minute before the listed end has a ticket that still gets them in, so
     * their payment is honoured until the door closes. Only a payment that
     * lands after that — a transfer confirming the morning after — is for a
     * ticket nobody could use, and goes back.
     */
    public static function doorCloses(Event $event): ?CarbonInterface
    {
        return self::listedEnd($event)?->addHours(DoorPasses::GRACE_HOURS);
    }

    /**
     * Whether the night itself is off, for a payment arriving at $at.
     *
     * Null while it is on — which says nothing about whether there is room
     * (Fulfiller asks that next). An event that is no longer there at all, or
     * that staff took down, is off sale however it got that way; one the
     * organizer merely moved back to draft is still happening, and a payment
     * for it is honoured as it always was.
     *
     * A suspended organization's events are drafts too, but not that kind.
     * The platform stopped its sales (Suspension), and checkout refuses them;
     * a payment that was already on its way when that happened is a sale the
     * platform has stopped all the same, and would otherwise land on the
     * balance of an organization whose money is frozen because something is
     * wrong. Read from the organization now, like checkout reads it, so a
     * suspension lifted in the meantime lets the payment through.
     */
    public static function forEvent(?Event $event, CarbonInterface $at): ?self
    {
        if ($event === null || $event->trashed() || $event->taken_down_at !== null) {
            return self::TakenDown;
        }

        if ($event->status === EventStatus::Cancelled->value) {
            return self::Cancelled;
        }

        if (Suspension::inForce($event->organization_id)) {
            return self::Suspended;
        }

        $close = self::doorCloses($event);

        return $close !== null && $close->lte($at) ? self::Ended : null;
    }

    /**
     * Read back from a refund's reason. Anything else was a sell-out.
     *
     * Refunds of this kind written before there was more than one reason
     * all said "sold out", and they were.
     */
    public static function fromRefundReason(?string $reason): self
    {
        foreach (self::cases() as $case) {
            if ($case->refundReason() === $reason) {
                return $case;
            }
        }

        return self::SoldOut;
    }

    /**
     * Kept on the refund and sent to the processor with it.
     *
     * Stripe takes this as metadata, where a sentence is allowed; its own
     * `reason` field takes one of three fixed words and refuses anything else.
     */
    public function refundReason(): string
    {
        return match ($this) {
            self::SoldOut => 'Sold out while the buyer was paying.',
            self::Cancelled => 'The event was cancelled before the payment arrived.',
            self::Suspended => 'The organizer\'s sales were suspended before the payment arrived.',
            self::Ended => 'The event had ended before the payment arrived.',
            self::TakenDown => 'The event was taken off sale before the payment arrived.',
        };
    }

    public function auditAction(): string
    {
        return match ($this) {
            self::SoldOut => 'order.sold_out_while_paying',
            self::Cancelled => 'order.event_cancelled_while_paying',
            self::Suspended => 'order.organizer_suspended_while_paying',
            self::Ended => 'order.event_ended_while_paying',
            self::TakenDown => 'order.event_taken_down_while_paying',
        };
    }
}
