<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a door phone needs to keep working when the venue's signal does not.
 *
 * client_id is the scan's own identity, minted on the phone. A scan sent
 * online can time out after the server has already admitted the guest; the
 * phone then decides from its saved list and queues the same scan for later.
 * Without an identity the server cannot tell a retry from a second person on
 * the same ticket, and would refuse the guest it had already let in — or, on
 * a table, count them twice. Unique, so a race between the two arrivals is
 * settled by the database rather than by luck.
 *
 * offline_result is what the phone showed while it had no connection. The
 * server's result is what was true. When the phone said "let them in" and the
 * server says the ticket was already used, somebody walked in on a spent
 * ticket — usually two phones working the same queue without signal, sometimes
 * a screenshot passed along a line. Either way it is worth a row, not a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_scans', function (Blueprint $table) {
            $table->uuid('client_id')->nullable()->unique();
            $table->string('offline_result')->nullable();
        });

        DB::statement("
            ALTER TABLE ticket_scans ADD CONSTRAINT ticket_scans_offline_result_check
            CHECK (offline_result IS NULL OR offline_result IN ('accepted', 'duplicate', 'not_found', 'void', 'over_capacity'))
        ");

        // The question every door report asks: who got in offline on a ticket
        // that turned out to be spent.
        DB::statement("
            CREATE INDEX ticket_scans_offline_conflicts
            ON ticket_scans (event_id, scanned_at)
            WHERE offline_result = 'accepted' AND result <> 'accepted'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ticket_scans_offline_conflicts');
        DB::statement('ALTER TABLE ticket_scans DROP CONSTRAINT IF EXISTS ticket_scans_offline_result_check');

        Schema::table('ticket_scans', function (Blueprint $table) {
            $table->dropUnique(['client_id']);
            $table->dropColumn(['client_id', 'offline_result']);
        });
    }
};
