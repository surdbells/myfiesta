<?php

namespace App\Contracts\Payments;

use App\Models\Order;

/**
 * A processor that can be asked for its own record of a payment.
 *
 * What a bank believes about a disputed payment is what the processor says
 * about it: whether the card's bank checked it was the cardholder, what the
 * fraud checks made of it, which card it was. That is asked for once the
 * payment has landed and kept (ProcessorEvidence), because by the time a
 * dispute arrives the question is months old. Separate from PaymentGateway
 * because only that sweep needs it, and a processor that cannot answer simply
 * leaves the order without the record.
 */
interface DescribesPayments
{
    /**
     * The processor's record of the order's payment, reduced to the fields a
     * dispute is answered with. Never a card number.
     *
     * Throws when the processor cannot be asked, or has nothing to say yet.
     */
    public function describePayment(Order $order): PaymentRecord;
}
