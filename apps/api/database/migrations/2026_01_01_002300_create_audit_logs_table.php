<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who did what.
 *
 * The ledger records that money moved. Nothing recorded who caused it — not
 * publishing an event, not cancelling one, not dropping a ticket price to zero,
 * not minting comps, not sending a refund. For a platform where several staff
 * share an organization and hold other people's money, "who dropped the price
 * at 11pm?" needs an answer that is not somebody's memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Which organization's history this belongs to. Nullable because a
            // platform-level action — a staff account being disabled — belongs
            // to no organization and still has to be recorded.
            $table->foreignUuid('organization_id')->nullable()
                ->constrained()->nullOnDelete();

            /*
             * Who, twice.
             *
             * The foreign key is how the trail is joined while the account
             * exists. The label is what survives the account being deleted or
             * anonymised under a privacy request — without it, erasing one
             * member's account would blank the actor on every refund they ever
             * processed, which is the opposite of what an audit trail is for.
             */
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label')->nullable();

            // Named in the past tense and dotted, matching the permission
            // vocabulary: event.cancelled, refund.processed, ticket.price_changed.
            $table->string('action');

            // What it happened to. Polymorphic rather than a column per type,
            // because the list of auditable things will grow and a nullable
            // column per model is how a table becomes forty columns of null.
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();

            /*
             * What actually changed.
             *
             * Free-form on purpose: a price change wants before and after, a
             * cancellation wants the reason and how many were refunded, and
             * forcing both into the same columns would fit neither. Never
             * personal data — the subject reference is how a person is reached,
             * so a name does not need to be copied in here.
             */
            $table->json('metadata')->nullable();

            // Where from. The one field that distinguishes a member acting
            // normally from a session somebody else is holding.
            $table->string('ip_address', 45)->nullable();

            // Written once and never touched, so there is no updated_at.
            $table->timestampTz('created_at');

            // The two questions asked of this table: what happened to this
            // thing, and what did this organization do lately.
            $table->index(['subject_type', 'subject_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });

        /*
         * Append-only, enforced by the database.
         *
         * The same guarantee the ledger has, for the same reason: a log that
         * application code can rewrite is not evidence of anything. Anybody who
         * can reach the database can still drop the table — this stops the
         * ordinary mistakes, and the ordinary mistakes are what actually
         * happen.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_are_immutable()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only: % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_no_update_or_delete
            BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION audit_logs_are_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update_or_delete ON audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_are_immutable()');

        Schema::dropIfExists('audit_logs');
    }
};
