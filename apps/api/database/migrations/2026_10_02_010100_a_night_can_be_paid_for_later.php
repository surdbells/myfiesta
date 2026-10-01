<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the organizer lets buyers pay for a night later, with Klarna or
 * Affirm through Stripe.
 *
 * Off unless they turn it on, because it costs them: the lenders charge about
 * twice what a card does, and the organizer pays the difference
 * (PayLater::premium). Turning it on offers it only while myFiesta has it
 * switched on (bnpl_enabled) and only within bnpl_max_days_before_event of
 * the night, so it is the organizer's half of the decision, not the whole.
 *
 * Not part of what a buyer sees on the listing, so not in the fingerprint an
 * approval is kept against (EventSnapshot): turning it on does not send the
 * night back through review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('pay_later_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('pay_later_enabled');
        });
    }
};
