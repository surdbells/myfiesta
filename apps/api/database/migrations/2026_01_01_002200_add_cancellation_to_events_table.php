<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes cancellation a real state rather than an unreachable one.
 *
 * The events table permitted five statuses and the application could set two.
 * `review` and `scheduled` were aspirational — no code ever wrote them, no
 * screen ever showed them, and nothing was planned. `cancelled` was worse than
 * aspirational: the terms page promises refunds when an organizer cancels, and
 * there was no way for an organizer to cancel.
 *
 * This narrows the constraint to the three states that are real and adds the
 * record of what happened. Narrowing is safe here and verified below: no row
 * has ever held one of the removed values, because nothing could write them.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Refuse to run if the assumption is wrong.
         *
         * Narrowing a CHECK constraint on a column that has values outside the
         * new set fails at the ALTER with a message about a constraint, which
         * is a confusing way to find out. This says what is actually wrong, and
         * this migration is the last moment where a decision about those rows
         * can still be made deliberately.
         */
        $orphans = DB::table('events')->whereIn('status', ['review', 'scheduled'])->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "{$orphans} event(s) hold a status this migration removes ('review' or ".
                "'scheduled'). Decide what they should become before running it."
            );
        }

        Schema::table('events', function (Blueprint $table) {
            // Who called it off and when. Separate from the audit trail because
            // this is shown to the organizer on the event itself, not looked up
            // in a log.
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_status_check');

        DB::statement(<<<'SQL'
            ALTER TABLE events ADD CONSTRAINT events_status_check
            CHECK (status IN ('draft', 'published', 'cancelled'))
        SQL);

        /*
         * A cancelled event must say when, and a live one must not.
         *
         * Without this the two can disagree — a status of cancelled with no
         * timestamp reads as a bug, and a cancelled_at on a published event is
         * a partial rollback somebody stopped halfway.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE events ADD CONSTRAINT events_cancellation_check
            CHECK (
                (status = 'cancelled' AND cancelled_at IS NOT NULL)
                OR (status <> 'cancelled' AND cancelled_at IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_cancellation_check');
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_status_check');

        DB::statement(<<<'SQL'
            ALTER TABLE events ADD CONSTRAINT events_status_check
            CHECK (status IN ('draft', 'review', 'scheduled', 'published', 'cancelled'))
        SQL);

        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
