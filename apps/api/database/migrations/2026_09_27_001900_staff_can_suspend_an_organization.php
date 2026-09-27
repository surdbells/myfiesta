<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An organization the platform has stopped selling for.
 *
 * Suspending is a takedown of the whole organization: every event on sale
 * comes off, sales stop, and payouts freeze until an administrator lifts it.
 * Lifting it has to put back exactly what was there, which means remembering
 * it — so each part keeps its own mark:
 *
 * - organizations: when, by whom, and why. The reason is kept on the row as
 *   well as in the audit trail so the console banner and the admin panel can
 *   both say why without reading the log; whether the organization is shown
 *   it is staff's choice, recorded beside it.
 * - events: which ones were on sale when the suspension came. Only these go
 *   back on sale when it is lifted — never a draft, or an event the organizer
 *   had taken off sale themselves.
 * - payout_requests: which requests are held. Held is not rejected: the
 *   request keeps its place and its status, and goes back to waiting when the
 *   suspension is lifted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestampTz('suspended_at')->nullable();
            $table->foreignUuid('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('suspension_reason', 1000)->nullable();
            $table->boolean('suspension_reason_shared')->default(false);

            $table->index('suspended_at');
        });

        // A suspension with no reason is one nobody can answer or lift.
        DB::statement(<<<'SQL'
            ALTER TABLE organizations ADD CONSTRAINT organizations_suspension_reason_check
            CHECK (suspended_at IS NULL OR (suspension_reason IS NOT NULL AND length(trim(suspension_reason)) > 0))
        SQL);

        Schema::table('events', function (Blueprint $table) {
            $table->timestampTz('unpublished_by_suspension_at')->nullable();
        });

        DB::statement(<<<'SQL'
            CREATE INDEX events_unpublished_by_suspension_index
            ON events (organization_id)
            WHERE unpublished_by_suspension_at IS NOT NULL
        SQL);

        Schema::table('payout_requests', function (Blueprint $table) {
            $table->timestampTz('held_at')->nullable();
        });

        // Only a request still waiting can be held; a paid or rejected one
        // has been decided, and holding it would mean nothing.
        DB::statement(<<<'SQL'
            ALTER TABLE payout_requests ADD CONSTRAINT payout_requests_held_check
            CHECK (held_at IS NULL OR status IN ('pending', 'cancelled', 'rejected'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payout_requests DROP CONSTRAINT IF EXISTS payout_requests_held_check');

        Schema::table('payout_requests', function (Blueprint $table) {
            $table->dropColumn('held_at');
        });

        DB::statement('DROP INDEX IF EXISTS events_unpublished_by_suspension_index');

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('unpublished_by_suspension_at');
        });

        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_suspension_reason_check');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suspended_by');
            $table->dropIndex(['suspended_at']);
            $table->dropColumn(['suspended_at', 'suspension_reason', 'suspension_reason_shared']);
        });
    }
};
