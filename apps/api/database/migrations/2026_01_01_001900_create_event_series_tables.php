<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Events that happen again.
 *
 * The decision that shapes everything else: occurrences are materialised as
 * ordinary events, not computed on the fly.
 *
 * A virtual occurrence is elegant until it has to be sold. A ticket needs an
 * event id to belong to; an order needs one to reference; the door scanner
 * compares one against a token; the ledger groups by one; a shared link needs a
 * slug. Every one of those wants a row. Materialising means checkout, scanning,
 * refunds, guest lists, images and reminders all work on a Friday night in a
 * series without knowing series exist — which is worth far more than the rows
 * it costs.
 *
 * The recurrence rule itself is RFC 5545, evaluated in the series' own zone.
 * That matters more than it sounds: "every Friday at 9pm" has to stay 9pm
 * across a clock change, and an implementation that adds seven days of seconds
 * moves the event by an hour twice a year — in opposite directions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_series', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            /*
             * The event occurrences are copied from.
             *
             * Not a hidden template row. A template nobody can see is a place
             * for the price to be wrong invisibly — this is the first
             * occurrence, a real event the organizer already built and can
             * look at, and changing it changes what future ones inherit.
             */
            $table->foreignUuid('source_event_id')->constrained('events')->cascadeOnDelete();

            // RRULE without the DTSTART: "FREQ=WEEKLY;BYDAY=FR". Stored as
            // written rather than decomposed into columns, because the standard
            // is the interchange format — an organizer exporting to a calendar
            // gets exactly this back.
            $table->string('rrule', 512);

            // The zone the rule is evaluated in, which is the venue's. Copied
            // from the source rather than joined, so changing an occurrence's
            // zone later cannot silently reinterpret the whole series.
            $table->string('timezone');

            // DTSTART. The first occurrence's instant, and the anchor every
            // later one is counted from.
            $table->timestampTz('starts_at');

            /*
             * How far ahead occurrences have been created.
             *
             * A weekly night with no end date is infinite, so a rolling window
             * is materialised and extended by a scheduled job. Without this
             * column the job cannot tell "not generated yet" from "deliberately
             * cancelled".
             */
            $table->timestampTz('generated_through')->nullable();

            $table->string('status')->default('active');

            $table->timestampsTz();

            $table->index(['status', 'generated_through']);
            $table->index('organization_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE event_series ADD CONSTRAINT event_series_status_check
            CHECK (status IN ('active', 'paused', 'ended'))
        SQL);

        /*
         * Dates the series skips — EXDATE, in the standard's terms.
         *
         * "Not on Boxing Day" is the request every venue makes, and it has to
         * survive regeneration: without a record of the skip, the next run of
         * the generator helpfully puts Boxing Day back.
         */
        Schema::create('event_series_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('series_id')->constrained('event_series')->cascadeOnDelete();

            // The instant the rule would have produced, not the date somebody
            // typed. Matching on a date would be ambiguous across a clock
            // change, which is exactly when a skipped night gets un-skipped.
            $table->timestampTz('occurs_at');

            $table->string('reason')->nullable();
            $table->timestampsTz();

            $table->unique(['series_id', 'occurs_at']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->foreignUuid('series_id')->nullable()->after('organization_id')
                ->constrained('event_series')->nullOnDelete();

            /*
             * Which occurrence this is, as the rule scheduled it.
             *
             * Distinct from starts_at, and the distinction is the point. An
             * organizer who moves one Friday to the Saturday changes starts_at;
             * this stays on the Friday, so the generator still recognises the
             * occurrence as done and does not create a second event for the
             * same slot.
             */
            $table->timestampTz('series_occurs_at')->nullable();
        });

        // One event per slot, enforced here rather than by the generator being
        // careful. Two runs overlapping is the failure this prevents.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX events_one_per_series_slot
            ON events (series_id, series_occurs_at)
            WHERE series_id IS NOT NULL AND deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE events ADD CONSTRAINT events_series_pairing_check
            CHECK ((series_id IS NULL AND series_occurs_at IS NULL)
                OR (series_id IS NOT NULL AND series_occurs_at IS NOT NULL))
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS events_one_per_series_slot');
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_series_pairing_check');

        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('series_id');
            $table->dropColumn('series_occurs_at');
        });

        Schema::dropIfExists('event_series_exceptions');
        Schema::dropIfExists('event_series');
    }
};
