<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A night, written down once it is over: that it happened, and how the door
 * went.
 *
 * "The event never took place" is a dispute reason, and the answer to it is
 * the door: it opened at 22:10 and closed at 03:40, 412 people were let in on
 * 390 tickets, 9 were turned away. All of that can be counted from the scans
 * — but the event row it is counted against can be edited by its organizer
 * the next morning, and a record an interested party can rewrite is not one a
 * bank should be shown. So once the door has closed and the offline phones
 * have had time to catch up, the night is written down here, event details
 * and all, as they were (disputes:record-completions).
 *
 * Once per event, and never changed or deleted: the database refuses both.
 * No person is named in it, so nothing here waits on a retention window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_completions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->unique()->constrained();
            $table->foreignUuid('organization_id')->constrained();

            // The listing as it stood when the night was written down.
            $table->string('title');
            $table->string('status', 32);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->string('timezone', 64);
            $table->string('venue')->nullable();
            $table->string('city')->nullable();

            // The first and last scan of any kind: when somebody was on the
            // door with a phone, which is what "the doors opened" means.
            $table->timestampTz('door_opened_at')->nullable();
            $table->timestampTz('door_closed_at')->nullable();

            // Every ticket ever issued for it, and those still good for entry
            // when it ended — the gap is refunds, returns and chargebacks.
            $table->unsignedInteger('tickets_issued');
            $table->unsignedInteger('tickets_live');
            // People, not scans: a table of five is five.
            $table->unsignedInteger('people_admitted');
            // Scans that let nobody in: a spent ticket, a code from another
            // night, one that matched nothing.
            $table->unsignedInteger('turned_away');
            $table->unsignedInteger('scans');

            $table->timestampTz('recorded_at');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION event_completions_are_immutable()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'event_completions is append-only: % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER event_completions_no_update_or_delete
            BEFORE UPDATE OR DELETE ON event_completions
            FOR EACH ROW EXECUTE FUNCTION event_completions_are_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS event_completions_no_update_or_delete ON event_completions');
        DB::unprepared('DROP FUNCTION IF EXISTS event_completions_are_immutable()');

        Schema::dropIfExists('event_completions');
    }
};
