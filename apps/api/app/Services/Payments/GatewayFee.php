<?php

namespace App\Services\Payments;

use App\Support\Money;

/**
 * What the processor takes out of a charge.
 *
 * Borne by the platform, out of the service charge, never by the organizer. An
 * organizer's payout is the ticket price whether the buyer paid by card or
 * wallet, domestic or foreign.
 *
 * Recorded per order at the moment the payment settles rather than inferred at
 * settlement time. Margin then is a figure that can be read off the orders
 * table, rather than one reconstructed from a processor's monthly statement
 * against a set of transactions nobody kept ids for.
 *
 * This is an estimate from published rates, not a reading of what the processor
 * actually charged. Where a gateway reports its own fee on the charge object,
 * prefer that: cross-border cards, currency conversion and negotiated rates all
 * make the published rate a floor rather than the figure. The estimate exists
 * so that margin is never simply unknown.
 */
final readonly class GatewayFee
{
    /**
     * The processor's cut of a charge.
     *
     * The flat component is in minor units — 30 is thirty cents. The live
     * platform stored this calculation as a generated column that added `0.30`
     * to an amount already held in cents, recording Stripe's flat fee as three
     * tenths of one cent. Every one of its 1,713 paid orders overstated margin
     * by very nearly the whole thirty.
     */
    public static function on(Money $charge, string $gateway): Money
    {
        $rates = config("payments.gateway_fees.{$gateway}");

        if (! is_array($rates)) {
            // An unconfigured gateway returns zero rather than guessing. A
            // wrong fee is worse than an absent one: it would be reported as
            // margin and reconciled against nothing.
            return Money::zero($charge->currency);
        }

        $fee = $charge
            ->percentage((int) ($rates['bps'] ?? 0))
            ->plus(new Money((int) ($rates['flat'] ?? 0), $charge->currency));

        // Paystack caps its fee on large transactions. Without this a ₦500,000
        // ticket would be recorded as costing far more to process than it does.
        $cap = $rates['cap'] ?? null;

        if ($cap !== null && $fee->amount > (int) $cap) {
            return new Money((int) $cap, $charge->currency);
        }

        return $fee;
    }
}
