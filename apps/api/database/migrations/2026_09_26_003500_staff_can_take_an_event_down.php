<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An event the platform has taken off sale.
 *
 * Unpublishing alone is something the organizer can undo with the next click,
 * which makes it a suggestion rather than a takedown. These columns are what
 * the organizer's publish button checks: while taken_down_at is set, the event
 * stays a draft until somebody here lifts it.
 *
 * Nothing is deleted. Tickets already sold stay valid, orders stay orders, and
 * the reason is kept on the row as well as in the audit trail so the organizer
 * console and the admin panel can both say why without reading the log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->timestampTz('taken_down_at')->nullable();
            $table->string('taken_down_reason', 1000)->nullable();
            $table->foreignUuid('taken_down_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index('taken_down_at');
        });

        // A takedown with no reason is one nobody can answer.
        DB::statement(<<<'SQL'
            ALTER TABLE events ADD CONSTRAINT events_taken_down_reason_check
            CHECK (taken_down_at IS NULL OR (taken_down_reason IS NOT NULL AND length(trim(taken_down_reason)) > 0))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_taken_down_reason_check');

        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('taken_down_by');
            $table->dropIndex(['taken_down_at']);
            $table->dropColumn(['taken_down_at', 'taken_down_reason']);
        });
    }
};
