<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money advanced to an organizer, and money they pay back.
 *
 * Paying a request for more than an organization is owed was already
 * possible, and the ledger already recorded it: the settlement entry takes the
 * balance below zero, and the next sales bring it back. What was missing was
 * the decision itself. The overdraft lived only in a settlement's type and a
 * free-text note, so "how much did we advance, who agreed it, and why" meant
 * reading notes and doing arithmetic.
 *
 * Now the request that was paid says so: how much of it was advanced, why,
 * who approved paying it and when. Nothing about the ledger changes — the
 * payout is still one settlement entry — so no money appears or disappears;
 * the balance goes below zero by exactly the advance.
 *
 * And money paid back outside the platform — a transfer to us, rather than
 * sales — has somewhere to be written. A repayment is its own record, with
 * the reference that matches it to our bank statement and the reason it was
 * taken, and one ledger entry of its own type. Like the ledger, it is never
 * edited: a mistake is corrected by writing its opposite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_requests', function (Blueprint $table) {
            // The part of the payment beyond what was owed at the moment it
            // was paid. Null when it was paid from the balance.
            $table->bigInteger('overdraft_amount')->nullable()->after('paid_amount');
            $table->text('overdraft_reason')->nullable()->after('overdraft_amount');

            // Who approved paying it, and when. Every paid request has one;
            // for an advance it is the name the decision is asked of later.
            $table->foreignUuid('approved_by')->nullable()->after('decided_at')->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable()->after('approved_by');
        });

        // Paid before this existed: approved by whoever decided it, then.
        DB::statement(<<<'SQL'
            UPDATE payout_requests
            SET approved_by = decided_by, approved_at = decided_at
            WHERE status = 'paid'
        SQL);

        /*
         * Advances paid before this existed.
         *
         * Paying a request beyond the balance was already possible, and
         * wrote an overdraft settlement with the reason as its note. Left
         * without its figures, such a request is told as refunds after a
         * payout — a cause that is not true — and the approver and reason
         * appear nowhere. So each one is measured the way it was decided:
         * the payout less the balance just before its ledger entry, reading
         * the ledger in the order it was written (by the second, and within
         * one by id, which is ordered by time too). One that cannot be
         * measured to more than nothing is left alone; Overdrafts tells it
         * from its settlement instead.
         */
        DB::statement(<<<'SQL'
            WITH measured AS (
                SELECT
                    pr.id AS request_id,
                    s.amount - COALESCE((
                        SELECT SUM(le.amount)
                        FROM ledger_entries le
                        WHERE le.organization_id = s.organization_id
                          AND le.currency = s.currency
                          AND (le.created_at, le.id) < (se.created_at, se.id)
                    ), 0) AS advanced,
                    COALESCE(NULLIF(TRIM(s.note), ''), NULLIF(TRIM(pr.decision_note), '')) AS reason
                FROM payout_requests pr
                JOIN settlements s ON s.id = pr.settlement_id
                JOIN ledger_entries se
                  ON se.organization_id = s.organization_id
                 AND se.currency = s.currency
                 AND se.type = 'settlement'
                 AND se.reason = 'Settlement ' || s.id
                WHERE pr.status = 'paid'
                  AND pr.overdraft_amount IS NULL
                  AND pr.approved_at IS NOT NULL
                  AND s.type = 'overdraft'
            )
            UPDATE payout_requests
            SET overdraft_amount = measured.advanced, overdraft_reason = measured.reason
            FROM measured
            WHERE payout_requests.id = measured.request_id
              AND measured.advanced > 0
              AND measured.reason IS NOT NULL
        SQL);

        // An advance is a paid request, with a reason and a time on it.
        DB::statement(<<<'SQL'
            ALTER TABLE payout_requests ADD CONSTRAINT payout_requests_overdraft_check
            CHECK (
                overdraft_amount IS NULL
                OR (
                    overdraft_amount > 0
                    AND status = 'paid'
                    AND overdraft_reason IS NOT NULL
                    AND length(trim(overdraft_reason)) > 0
                    AND approved_at IS NOT NULL
                )
            )
        SQL);

        // The latest advance per organization and currency: what a statement
        // and the admin's list of overdrafts both ask.
        DB::statement(<<<'SQL'
            CREATE INDEX payout_requests_advances
            ON payout_requests (organization_id, currency, approved_at)
            WHERE overdraft_amount IS NOT NULL
        SQL);

        Schema::create('repayments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('amount');

            // The bank's or Interac's reference for the money arriving, so the
            // record can be matched to our statement.
            $table->string('reference', 120);
            $table->text('reason');

            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('ledger_entry_id')->constrained('ledger_entries');
            $table->timestampsTz();

            $table->index(['organization_id', 'currency', 'created_at']);
        });

        DB::statement('ALTER TABLE repayments ADD CONSTRAINT repayments_amount_check CHECK (amount > 0)');
        DB::statement(<<<'SQL'
            ALTER TABLE repayments ADD CONSTRAINT repayments_said_check
            CHECK (length(trim(reference)) > 0 AND length(trim(reason)) > 0)
        SQL);

        // One transfer is one repayment. Recorded twice, it would credit
        // money that arrived once; the reference is what tells transfers
        // apart, compared as people type it, whatever the case.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX repayments_one_per_reference
            ON repayments (organization_id, currency, lower(trim(reference)))
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION repayments_are_immutable()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'repayments are append-only; record a correcting entry instead';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER repayments_no_update_or_delete
            BEFORE UPDATE OR DELETE ON repayments
            FOR EACH ROW EXECUTE FUNCTION repayments_are_immutable()
        SQL);

        // Money paid back is a credit of its own kind: not a sale, not a
        // correction, and never confused with either in a sum by type.
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type_check
            CHECK (type IN ('sale', 'discount', 'tax', 'refund', 'settlement', 'adjustment', 'collected', 'chargeback', 'repayment'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type_check
            CHECK (type IN ('sale', 'discount', 'tax', 'refund', 'settlement', 'adjustment', 'collected', 'chargeback'))
        SQL);

        Schema::dropIfExists('repayments');
        DB::statement('DROP FUNCTION IF EXISTS repayments_are_immutable()');

        DB::statement('DROP INDEX IF EXISTS payout_requests_advances');
        DB::statement('ALTER TABLE payout_requests DROP CONSTRAINT IF EXISTS payout_requests_overdraft_check');

        Schema::table('payout_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['overdraft_amount', 'overdraft_reason', 'approved_at']);
        });
    }
};
