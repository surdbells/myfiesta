<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds we hear about, and refunds we are still waiting to hear about.
 *
 * Until now a refund row was only ever written by us, and only ever settled by
 * the answer to our own request. Two things fell through that:
 *
 * A refund made in the Stripe or Paystack dashboard. The money went back and
 * nothing here knew: the tickets still opened the door and the organizer's
 * balance still counted the sale. `source` says which side started a refund,
 * so one made at the processor can be written down as what it is.
 *
 * A request that got no answer. A timeout does not mean the processor said no
 * — it may have paid the money back a moment before the connection dropped —
 * and writing it down as failed invited the organizer to try again and pay it
 * back twice. `unanswered_at` says the refund was sent and nobody replied, so
 * it can be asked about again (refunds:follow-up) instead of being guessed at.
 *
 * `confirmed_at` is when the processor's own notice about a refund was matched
 * to its row. It is how one notice is kept from being read as two refunds, and
 * two refunds of the same amount from being read as one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->string('source', 16)->default('platform');
            $table->timestampTz('unanswered_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();

            // What the follow-up sweep reads: the few rows still waiting.
            $table->index(['status', 'unanswered_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE refunds ADD CONSTRAINT refunds_source_check
            CHECK (source IN ('platform', 'processor'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT IF EXISTS refunds_source_check');

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropIndex(['status', 'unanswered_at']);
            $table->dropColumn(['source', 'unanswered_at', 'confirmed_at']);
        });
    }
};
