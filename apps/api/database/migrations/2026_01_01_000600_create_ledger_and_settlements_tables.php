<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An append-only ledger, and the settlements recorded against it.
 *
 * The previous platform had no ledger. An organizer's balance was computed at
 * read time as the sum of paid sales minus the sum of successful settlements,
 * in PHP, on every request. That works until something needs correcting — a
 * refund, a chargeback, a mistaken payout — at which point there is nowhere for
 * the correction to live.
 *
 * Entries are never updated or deleted. A mistake is corrected by writing its
 * reverse, so the history of how a balance was reached stays intact.
 *
 * Entries carry their own currency and are never summed across currencies. An
 * organization running events in Toronto and Lagos has two balances, not one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained()->nullOnDelete();

            // sale       + gross ticket revenue
            // discount   - organizer-funded reduction
            // tax        - collected on behalf of a tax authority
            // (there is deliberately no 'commission' type: the service charge is
            //  the buyer's payment to the platform and never enters an
            //  organizer's balance, so it has no entry to make here)
            // refund     - returned to the buyer
            // settlement - paid out to the organizer
            // adjustment - manual correction, always carrying a reason
            $table->string('type');

            // Signed. Positive increases what the organizer is owed.
            $table->bigInteger('amount');
            $table->char('currency', 3);

            $table->string('reason')->nullable();

            // Set when this entry reverses another, so corrections are
            // traceable rather than merely present. The foreign key is added
            // after creation — the table cannot reference its own primary key
            // in the statement that defines it.
            $table->uuid('reverses_entry_id')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['organization_id', 'currency', 'occurred_at']);
            $table->index(['event_id', 'type']);
            $table->index('order_id');
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->foreign('reverses_entry_id')->references('id')->on('ledger_entries')->nullOnDelete();
            $table->index('reverses_entry_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type_check
            CHECK (type IN ('sale', 'discount', 'tax', 'refund', 'settlement', 'adjustment'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_adjustment_reason_check
            CHECK (type <> 'adjustment' OR reason IS NOT NULL)
        SQL);

        // Append-only, enforced by the database rather than by convention.
        // Application code cannot quietly rewrite financial history.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_entries_are_immutable()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'ledger_entries is append-only; write a reversing entry instead';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER ledger_entries_no_update_or_delete
            BEFORE UPDATE OR DELETE ON ledger_entries
            FOR EACH ROW EXECUTE FUNCTION ledger_entries_are_immutable()
        SQL);

        Schema::create('settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained()->nullOnDelete();

            $table->bigInteger('amount');
            $table->char('currency', 3);

            // Which rail the money actually moved on. Manual settlement spans
            // both gateways, so the balance alone does not say how it was paid.
            $table->string('rail');           // interac | bank_transfer | stripe | paystack

            // Classified by comparing the amount against the outstanding
            // balance. Overdraft requires a note, as it did before.
            $table->string('type');           // full | partial | overdraft
            $table->text('note')->nullable();

            $table->string('status')->default('pending');
            $table->foreignUuid('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('settled_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'currency', 'created_at']);
            $table->index(['event_id', 'status']);
        });

        DB::statement('ALTER TABLE settlements ADD CONSTRAINT settlements_amount_check CHECK (amount > 0)');
        DB::statement(<<<'SQL'
            ALTER TABLE settlements ADD CONSTRAINT settlements_type_check
            CHECK (type IN ('full', 'partial', 'overdraft'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE settlements ADD CONSTRAINT settlements_status_check
            CHECK (status IN ('pending', 'success', 'failed', 'reversed'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE settlements ADD CONSTRAINT settlements_rail_check
            CHECK (rail IN ('interac', 'bank_transfer', 'stripe', 'paystack'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE settlements ADD CONSTRAINT settlements_overdraft_note_check
            CHECK (type <> 'overdraft' OR (note IS NOT NULL AND length(trim(note)) > 0))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
        DB::statement('DROP TRIGGER IF EXISTS ledger_entries_no_update_or_delete ON ledger_entries');
        DB::statement('DROP FUNCTION IF EXISTS ledger_entries_are_immutable()');
        Schema::dropIfExists('ledger_entries');
    }
};
