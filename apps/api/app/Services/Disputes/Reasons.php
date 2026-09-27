<?php

namespace App\Services\Disputes;

/**
 * What a dispute's reason means, in plain words, and what answers it.
 *
 * A card network's reason is a claim with a shape: "fraudulent" is answered by
 * showing the cardholder made the payment, "product not received" by showing
 * the tickets arrived and were used, "credit not processed" by the refund
 * policy the buyer agreed to. So each reason is sorted into one of five kinds
 * of answer, and the evidence put together (EvidenceDraft) is the kind's.
 *
 * Also said, for each: when the buyer is probably right. Not every dispute is
 * worth contesting — a night that was cancelled and never refunded is owed
 * back — and the page says so rather than leave staff to send an answer that
 * loses anyway.
 */
final class Reasons
{
    /** The cardholder says it was not them, or does not know the charge. */
    public const FRAUD = 'fraud';

    /** The buyer says they never got the tickets, or the night never happened. */
    public const NOT_RECEIVED = 'not_received';

    /** The buyer says a refund was owed, or the night was not as described. */
    public const REFUND = 'refund';

    /** The buyer says they were charged twice. */
    public const DUPLICATE = 'duplicate';

    /** No particular claim: everything that shows the sale was sound. */
    public const GENERAL = 'general';

    /** Which kind of answer a processor's reason calls for. */
    public static function kind(?string $reason): string
    {
        return match ($reason) {
            'fraudulent', 'unrecognized', 'fraud' => self::FRAUD,
            'product_not_received' => self::NOT_RECEIVED,
            'credit_not_processed', 'product_unacceptable', 'subscription_canceled' => self::REFUND,
            'duplicate' => self::DUPLICATE,
            default => self::GENERAL,
        };
    }

    /** A short name for the reason, for lists and emails. */
    public static function label(?string $reason): string
    {
        return match ($reason) {
            'fraudulent' => 'Fraud — not the cardholder',
            'unrecognized' => 'Charge not recognised',
            'product_not_received' => 'Tickets not received',
            'product_unacceptable' => 'Not as described',
            'credit_not_processed' => 'Refund not received',
            'duplicate' => 'Charged twice',
            'subscription_canceled' => 'Cancelled subscription',
            'general' => 'No reason given',
            'bank_cannot_process' => 'Bank could not process it',
            'customer_initiated' => 'Buyer asked their bank',
            'debit_not_authorized' => 'Debit not authorised',
            'incorrect_account_details' => 'Wrong account details',
            'insufficient_funds' => 'Insufficient funds',
            'noncompliant' => 'Card network rules',
            'check_returned' => 'Cheque returned',
            'fraud' => 'Fraud (Paystack)',
            'chargeback' => 'Chargeback (Paystack)',
            null, '' => 'Not given',
            default => ucfirst(str_replace(['_', '-'], ' ', $reason)),
        };
    }

    /** What the buyer's bank has been told, in plain words. */
    public static function claim(?string $reason): string
    {
        return match ($reason) {
            'fraudulent' => 'The cardholder told their bank they did not make this payment or allow anybody else to.',
            'unrecognized' => 'The cardholder told their bank they do not recognise this charge on their statement.',
            'product_not_received' => 'The buyer told their bank they paid and never received what they paid for — the tickets, or the night itself.',
            'product_unacceptable' => 'The buyer told their bank the night was not what was described, or was not fit to attend.',
            'credit_not_processed' => 'The buyer told their bank they were owed a refund and never received it.',
            'duplicate' => 'The buyer told their bank they were charged more than once for the same purchase.',
            'subscription_canceled' => 'The buyer told their bank they were charged for something they had cancelled.',
            'general' => 'The bank gave no particular reason.',
            'fraud' => 'Paystack has raised this as fraud: the cardholder says they did not make the payment.',
            'chargeback' => 'The buyer asked their bank, through Paystack, for the money back. Their own words, where Paystack gives them, are below.',
            null, '' => 'The processor gave no reason.',
            default => 'The processor gave the reason "'.str_replace(['_', '-'], ' ', $reason).'". It is not one a ticket sale usually meets; the general evidence is put together for it.',
        };
    }

    /** What wins a dispute of this kind. */
    public static function wins(string $kind): string
    {
        return match ($kind) {
            self::FRAUD => 'Showing the cardholder made the payment. Strongest of all: the card\'s bank checked it was them (3D Secure) — for a fraud claim that moves the loss to the bank. '
                .'Then: the card checks passed, the order came from the same internet address that later opened the tickets, the tickets were used at the door, and the same person has paid before without complaint.',
            self::NOT_RECEIVED => 'Showing the tickets reached the buyer and were used: issued, emailed to the address they gave, the link opened, scanned in at the door — and that the night took place.',
            self::REFUND => 'Showing what the buyer agreed to: the refund policy as it was shown at checkout, how they accepted it, and that no refund was due under it — or that one was made. '
                .'For "not as described": the listing as it stood, and that the night took place as listed and they were let in.',
            self::DUPLICATE => 'Showing the second charge was a second order: its own reference, its own tickets, bought separately.',
            default => 'Everything that shows the sale was sound: the receipt, the terms accepted, the tickets delivered and used, the refund policy.',
        };
    }

    /** When accepting is the right answer. */
    public static function fair(string $kind): string
    {
        return match ($kind) {
            self::FRAUD => 'Consider accepting when the card\'s bank did not authenticate the payment and nothing ties the tickets to the cardholder — never opened, never used.',
            self::NOT_RECEIVED => 'Accept when the night was cancelled or did not happen, or the tickets were never issued.',
            self::REFUND => 'Accept when a refund was owed and not made — the night was cancelled, or the organizer agreed to one.',
            self::DUPLICATE => 'Accept when there is only one order: a second charge for the same order is a mistake to put right.',
            default => 'Accept when the records show the buyer did not get what they paid for.',
        };
    }
}
