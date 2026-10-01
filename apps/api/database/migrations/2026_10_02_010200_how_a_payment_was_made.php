<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each online payment was made, in Stripe's word for it: card, klarna,
 * affirm, link.
 *
 * Kept beside the processor's record of the payment (payment_evidence), and
 * written in the same moment that record is captured, so it is fixed with
 * it. Its own column rather than a key inside `facts` because two things ask
 * it per order: a refund, which Klarna and Affirm take back only for so long
 * after the payment (PayLater::refundRefusal), and the event's cancellation
 * preview, which counts the orders that are past that.
 *
 * Not on orders: orders.payment_method is for a sale at the door, and its
 * CHECK constraint says so.
 *
 * Beside it, everything the processor took for the payment (fee_amount), in
 * the order's minor units. For a card it is the order's processor fee too.
 * For Klarna or Affirm on a night the organizer opted in, the organizer pays
 * the part over a card's fee, so the order's processor fee is only the
 * platform's part, and this is where the whole of it stays on record
 * (ProcessorEvidence::charged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_evidence', function (Blueprint $table) {
            $table->string('method_type', 32)->nullable();
            $table->bigInteger('fee_amount')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_evidence', function (Blueprint $table) {
            $table->dropColumn(['method_type', 'fee_amount']);
        });
    }
};
