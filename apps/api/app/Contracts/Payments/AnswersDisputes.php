<?php

namespace App\Contracts\Payments;

use App\Models\Dispute;

/**
 * A processor that can be asked about a dispute, and answered.
 *
 * Separate from PaymentGateway for the reason DescribesPayments is: only the
 * dispute desk needs it, and a processor that cannot do it leaves staff to
 * answer in its own dashboard instead.
 *
 * Every method throws when the processor cannot be reached or says no, with
 * the processor's own words in the message; the caller decides what a person
 * is told. None of them is ever called while a database transaction is open.
 */
interface AnswersDisputes
{
    /** The dispute as the processor has it now. */
    public function describeDispute(Dispute $dispute): ProcessorDispute;

    /**
     * Send the evidence and ask for the dispute to be decided on it.
     *
     * Files already given to the processor (EvidencePackage::uploaded) are not
     * given again, and each one given is remembered as soon as it is, so a
     * try that fails half-way and is tried again sends nothing twice.
     */
    public function submitDisputeEvidence(Dispute $dispute, EvidencePackage $package): ProcessorDispute;

    /**
     * Concede: the buyer keeps the money, and nothing more is sent.
     *
     * The package is for a processor that wants a note and a file with it
     * (Paystack); Stripe needs neither.
     */
    public function acceptDispute(Dispute $dispute, EvidencePackage $package): ProcessorDispute;
}
