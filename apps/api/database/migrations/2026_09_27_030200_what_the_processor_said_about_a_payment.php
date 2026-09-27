<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The payment processor's own record of a payment, kept beside the order.
 *
 * A dispute arrives months after the night, and by then the only account of
 * the payment anybody can offer a bank is the one the processor keeps — so
 * it is asked for once the payment lands and kept here, rather than hoped
 * for later: whether the buyer's bank checked it was them (3D Secure), what
 * the processor's fraud checks made of it, which card it was by brand, last
 * four and fingerprint, and the receipt it sent. Never a card number; the
 * processors do not give one out, and nothing here would keep it if they did.
 *
 * One row per paid order. It starts as a note that the record is still to be
 * fetched (pending), with what the signed payment notice itself said; a
 * scheduled sweep asks the processor, tries again on a widening gap, and
 * gives up after a day (disputes:collect-evidence). Once captured the row is
 * fixed — the database refuses to change it — because a record that can be
 * edited after the dispute arrives is not evidence of anything.
 *
 * Deleted 18 months after the event by disputes:prune-evidence, and by
 * nothing else: the trigger lets a delete through only inside that prune.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->unique()->constrained();
            // Which night, so the prune can find what is past its window
            // without reading every order.
            $table->foreignUuid('event_id')->constrained();
            $table->string('gateway', 32);
            // Stripe's payment intent, or Paystack's transaction reference:
            // what the processor is asked about.
            $table->string('payment_reference')->nullable();

            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->string('last_error', 500)->nullable();

            // What the signed payment notice said, as it arrived: whether
            // Stripe's own terms box was ticked, when that is asked.
            $table->jsonb('checkout')->nullable();
            // The processor's record, reduced to the fields a dispute is
            // answered with (ProcessorEvidence says which, and why).
            $table->jsonb('facts')->nullable();
            // The address the processor sent its receipt to. Its own column
            // so a privacy request can find it (config/personal_data.php).
            $table->string('receipt_email')->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'next_attempt_at']);
            $table->index('event_id');
        });

        DB::statement("
            ALTER TABLE payment_evidence ADD CONSTRAINT payment_evidence_status_check
            CHECK (status IN ('pending', 'captured', 'gave_up'))
        ");

        /*
         * Fixed once captured, and deleted only by the retention prune.
         *
         * The prune says so for its own transaction alone (SET LOCAL
         * myfiesta.retention_prune); any other delete is refused. A pending
         * row can still be written to — that is its attempts being counted.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payment_evidence_is_kept()
            RETURNS TRIGGER AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('myfiesta.retention_prune', true) IS DISTINCT FROM 'on' THEN
                        RAISE EXCEPTION 'payment_evidence is deleted only by its retention prune';
                    END IF;

                    RETURN OLD;
                END IF;

                IF OLD.status = 'captured' THEN
                    RAISE EXCEPTION 'payment_evidence is fixed once captured: % is not permitted', TG_OP;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER payment_evidence_fixed_once_captured
            BEFORE UPDATE OR DELETE ON payment_evidence
            FOR EACH ROW EXECUTE FUNCTION payment_evidence_is_kept();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS payment_evidence_fixed_once_captured ON payment_evidence');
        DB::unprepared('DROP FUNCTION IF EXISTS payment_evidence_is_kept()');

        Schema::dropIfExists('payment_evidence');
    }
};
