<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money returned, recorded as its own fact.
 *
 * The order already carries refunded_at and a status, which is enough to say
 * *that* an order was refunded and nothing else. It cannot say how much has
 * gone back so far, which is the question a second partial refund has to
 * answer before it can decide whether it is allowed. Storing a running total on
 * the order would make two concurrent refunds race for it; storing each refund
 * as a row makes the total a sum, and the sum is taken under the order's lock.
 *
 * A row is written for failures too. A refund that Stripe refused is a thing
 * that happened, an organizer will ask about it, and the reason is only in the
 * response — recording it here is the difference between answering the question
 * and reading a log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();

            // Who authorised it. Nullable because a refund may eventually come
            // from an automated policy, and a null here should read as "the
            // system" rather than as a missing person.
            $table->foreignUuid('issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->char('currency', 3);

            // What the buyer got back, and the parts it decomposes into. Kept
            // separately rather than derived later: tax rates change, commission
            // rates change, and a refund has to stay explicable against the
            // terms in force when it happened.
            $table->bigInteger('amount');
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('commission_amount')->default(0);

            $table->string('status')->default('pending');
            $table->string('gateway')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('reason')->nullable();

            $table->timestampsTz();

            $table->index(['order_id', 'status']);
            $table->index(['event_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE refunds ADD CONSTRAINT refunds_status_check
            CHECK (status IN ('pending', 'succeeded', 'failed'))
        SQL);

        // A refund of nothing is not a refund, and the parts cannot exceed the
        // whole. Both are things the arithmetic should make impossible, and a
        // constraint is where that belief goes when it matters.
        DB::statement(<<<'SQL'
            ALTER TABLE refunds ADD CONSTRAINT refunds_amounts_check
            CHECK (amount > 0
                   AND tax_amount >= 0 AND commission_amount >= 0
                   AND tax_amount <= amount)
        SQL);

        // A failure has to say why, or the row answers nothing.
        DB::statement(<<<'SQL'
            ALTER TABLE refunds ADD CONSTRAINT refunds_failure_reason_check
            CHECK (status <> 'failed' OR (failure_reason IS NOT NULL
                                          AND length(trim(failure_reason)) > 0))
        SQL);

        // Which tickets the money was for.
        //
        // Refunding is by ticket, not by amount. An arbitrary sum leaves the
        // tickets valid and the door with no idea, so somebody who was paid
        // back still walks in. Naming the tickets makes the proportion exact
        // and voiding them part of the same act.
        Schema::create('refund_tickets', function (Blueprint $table) {
            $table->foreignUuid('refund_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ticket_id')->constrained()->restrictOnDelete();

            $table->timestampsTz();

            // The pair is the identity. A surrogate key on a join table buys
            // nothing and costs a generator on every attach.
            //
            // A ticket can appear in a failed refund and then a successful one,
            // so uniqueness across refunds cannot be absolute — that is enforced
            // in the service, which knows which rows succeeded.
            $table->primary(['refund_id', 'ticket_id']);
            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_tickets');
        Schema::dropIfExists('refunds');
    }
};
