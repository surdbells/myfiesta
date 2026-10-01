<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A night that goes on sale at a time the organizer chose, and repeating
 * nights whose dates put themselves on sale.
 *
 * `publish_at` is when, and `publish_scheduled_by` is who asked: at that time
 * the night is sent the way the organizer would send it (EventReviews::submit),
 * as that member, so a member who has since lost the right to put events on
 * sale does not put this one on sale either (events:go-live asks again). A
 * date of a repeating night that its series scheduled has nobody of its own
 * there; the series says who turned that on (`auto_publish_by`), asked again
 * the same way.
 *
 * Neither is part of what a buyer sees, so neither is in the fingerprint an
 * approval is kept against (EventSnapshot): moving the time does not send the
 * night back through review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->timestampTz('publish_at')->nullable();
            $table->foreignUuid('publish_scheduled_by')->nullable()->constrained('users')->nullOnDelete();
        });

        // What events:go-live reads every minute: the drafts that are due. A
        // partial index, so the thousands of nights with no time set cost it
        // nothing.
        DB::statement(<<<'SQL'
            CREATE INDEX events_due_to_go_on_sale
            ON events (publish_at)
            WHERE publish_at IS NOT NULL AND status = 'draft' AND deleted_at IS NULL
        SQL);

        Schema::table('event_series', function (Blueprint $table) {
            // Each new date is sent by itself, on_sale_days_before its night
            // (or as soon as it is made, when that is null).
            $table->boolean('auto_publish')->default(false);
            $table->smallInteger('on_sale_days_before')->nullable();
            $table->foreignUuid('auto_publish_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE event_series ADD CONSTRAINT event_series_on_sale_days_before_check
            CHECK (on_sale_days_before IS NULL OR on_sale_days_before BETWEEN 0 AND 365)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE event_series DROP CONSTRAINT IF EXISTS event_series_on_sale_days_before_check');

        Schema::table('event_series', function (Blueprint $table) {
            $table->dropConstrainedForeignId('auto_publish_by');
            $table->dropColumn(['auto_publish', 'on_sale_days_before']);
        });

        DB::statement('DROP INDEX IF EXISTS events_due_to_go_on_sale');

        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('publish_scheduled_by');
            $table->dropColumn('publish_at');
        });
    }
};
