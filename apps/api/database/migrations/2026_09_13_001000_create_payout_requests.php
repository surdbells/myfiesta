<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organizers asking to be paid.
 *
 * Settlement has always been something staff did unprompted: somebody here
 * looked at a balance and decided to send it. An organizer with money owed
 * before a weekend had no way to ask except an email, and no way to see that
 * the ask had been heard.
 *
 * A request is only the ask. It moves no money and writes nothing to the
 * ledger; paying it goes through the same settlement recorder as every other
 * payout, and is the only place an administrator may pay more than is owed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);

            // What they asked for, and what they were owed at the time — so
            // whoever pays it can see whether the balance moved since.
            $table->bigInteger('amount');
            $table->bigInteger('balance_at_request');
            $table->text('note')->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status')->default('pending');

            // The decision: paid (and how much, through which settlement),
            // rejected (and why), or withdrawn by the organizer.
            $table->bigInteger('paid_amount')->nullable();
            $table->foreignUuid('settlement_id')->nullable()->constrained()->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();

            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });

        DB::statement('ALTER TABLE payout_requests ADD CONSTRAINT payout_requests_amount_check CHECK (amount > 0 AND (paid_amount IS NULL OR paid_amount > 0))');

        DB::statement(<<<'SQL'
            ALTER TABLE payout_requests ADD CONSTRAINT payout_requests_status_check
            CHECK (status IN ('pending', 'paid', 'rejected', 'cancelled'))
        SQL);

        // Paid means a settlement exists; rejected means somebody said why.
        DB::statement(<<<'SQL'
            ALTER TABLE payout_requests ADD CONSTRAINT payout_requests_decision_check
            CHECK (
                (status <> 'paid' OR (paid_amount IS NOT NULL AND decided_at IS NOT NULL))
                AND (status <> 'rejected' OR (decision_note IS NOT NULL AND decided_at IS NOT NULL))
            )
        SQL);

        // One open request per currency. A second while the first waits is a
        // duplicate that could be paid twice.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payout_requests_one_pending
            ON payout_requests (organization_id, currency)
            WHERE status = 'pending'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_requests');
    }
};
