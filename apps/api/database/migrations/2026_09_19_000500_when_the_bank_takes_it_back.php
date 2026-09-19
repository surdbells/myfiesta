<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chargebacks: a buyer telling their bank the charge was wrong.
 *
 * Until now a dispute arrived, was written to the log, and that was the end of
 * it — nobody on this platform could see that money had been taken back, and
 * the ticket it paid for still opened a door.
 *
 * Also the reference that makes any of it findable. An order records the
 * checkout session it was paid through, and a dispute names the payment
 * itself; the two are different identifiers, so the one the processor uses
 * afterwards — for disputes and for refunds — is now kept alongside.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Stripe's payment intent, Paystack's transaction. Captured when
            // the payment settles, because that is the first time the
            // processor tells us what it is.
            $table->string('gateway_payment_reference')->nullable()->after('gateway_reference');
            $table->timestampTz('disputed_at')->nullable();

            $table->index('gateway_payment_reference');
        });

        Schema::create('disputes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway', 32);
            // The processor's id for the dispute itself, so a second delivery
            // about the same one updates rather than duplicates.
            $table->string('gateway_reference');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('reason')->nullable();
            $table->string('status', 16)->default('open');
            $table->timestampTz('opened_at');
            // When the processor stops accepting evidence. Shown to staff,
            // because after it there is nothing anybody can do.
            $table->timestampTz('evidence_due_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['gateway', 'gateway_reference']);
            $table->index(['status', 'opened_at']);
            $table->index('organization_id');
        });

        DB::statement("
            ALTER TABLE disputes ADD CONSTRAINT disputes_status_check
            CHECK (status IN ('open', 'won', 'lost', 'withdrawn'))
        ");

        // Money taken back out of an organizer's balance by a bank, which is
        // neither a refund they chose nor an adjustment staff made.
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_type_check');
        DB::statement("
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type_check
            CHECK (type IN ('sale', 'discount', 'tax', 'refund', 'settlement', 'adjustment', 'collected', 'chargeback'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['gateway_payment_reference', 'disputed_at']);
        });
    }
};
