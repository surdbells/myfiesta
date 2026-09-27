<?php

namespace App\Services\Legacy;

/**
 * What Stripe said about an imported order, in one word.
 *
 * The old database's "paid" is a flag its own code set when a browser came
 * back from Stripe, and that code trusted the browser. The money itself is in
 * Stripe. Each case here is one way the two can disagree, and each has a
 * different person who has to act on it — docs/CUTOVER.md says who.
 */
enum ReconcileOutcome: string
{
    case Matched = 'matched';
    case AmountMismatch = 'amount_mismatch';
    case NotPaidInStripe = 'not_paid_in_stripe';
    case RefundedInStripeOnly = 'refunded_in_stripe_only';
    case RefundedHereOnly = 'refunded_here_only';
    case PaidInStripeOnly = 'paid_in_stripe_only';
    case DisputedInStripe = 'disputed_in_stripe';
    case SharedStripePayment = 'shared_stripe_payment';
    case MissingInStripe = 'missing_in_stripe';
    case NoStripeId = 'no_stripe_id';
    case Unreachable = 'unreachable';

    public function label(): string
    {
        return match ($this) {
            self::Matched => 'Matched',
            self::AmountMismatch => 'Amount or currency differs',
            self::NotPaidInStripe => 'Paid here, not succeeded in Stripe',
            self::RefundedInStripeOnly => 'Refunded in Stripe, not here',
            self::RefundedHereOnly => 'Refunded here, not in Stripe',
            self::PaidInStripeOnly => 'Paid in Stripe, not here',
            self::DisputedInStripe => 'Disputed in Stripe, not here',
            self::SharedStripePayment => 'One Stripe payment, several orders',
            self::MissingInStripe => 'Not found in Stripe',
            self::NoStripeId => 'Paid here, no Stripe id',
            self::Unreachable => 'Not checked: Stripe did not answer',
        };
    }

    /**
     * The order the report is read in: what is most likely to be somebody's
     * money first, agreement last.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Unreachable => 0,
            self::PaidInStripeOnly => 1,
            self::DisputedInStripe => 2,
            self::SharedStripePayment => 3,
            self::NotPaidInStripe => 4,
            self::MissingInStripe => 5,
            self::AmountMismatch => 6,
            self::RefundedInStripeOnly => 7,
            self::RefundedHereOnly => 8,
            self::NoStripeId => 9,
            self::Matched => 10,
        };
    }
}
