<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A ticket can admit more than one person, and they do not always arrive
 * together.
 *
 * A Couple ticket admits two; a Table of 5 admits five. Check-in was binary —
 * valid, then checked_in — which cannot express three of a table of five
 * arriving at eleven and the other two at midnight. Under the old model the
 * first scan consumed the whole ticket and the remaining two were turned away
 * holding a ticket the system said had already been used.
 *
 * So a ticket now carries how many it admits and how many have come in, and
 * check-in decrements rather than flips.
 *
 * admits is snapshotted onto the ticket rather than read through the ticket
 * type. An organizer editing a Table of 5 down to a Table of 4 next month must
 * not silently shrink a table somebody already bought.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Copied from the ticket type when the ticket is issued.
            $table->unsignedSmallInteger('admits')->default(1)->after('ticket_type_id');

            // How many of the party are inside. Reaching admits closes it.
            $table->unsignedSmallInteger('admitted_count')->default(0)->after('admits');
        });

        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_admits_check CHECK (admits >= 1)');

        // Cannot admit more people than the ticket is for. Enforced here as
        // well as in the service, because the door is where the pressure is and
        // a race between two scanners must not let six through a table of five.
        DB::statement(<<<'SQL'
            ALTER TABLE tickets ADD CONSTRAINT tickets_admitted_within_admits_check
            CHECK (admitted_count >= 0 AND admitted_count <= admits)
        SQL);

        // Existing tickets are single-admission and already consistent: a
        // checked-in one has had its single person through.
        DB::statement("UPDATE tickets SET admitted_count = 1 WHERE status = 'checked_in'");

        // Scans record how many people that scan let in, so a partial
        // admission is legible afterwards rather than being one row among
        // several with no numbers on it.
        Schema::table('ticket_scans', function (Blueprint $table) {
            $table->unsignedSmallInteger('admitted')->default(0)->after('result');
        });

        DB::statement("UPDATE ticket_scans SET admitted = 1 WHERE result = 'accepted'");
    }

    public function down(): void
    {
        Schema::table('ticket_scans', fn (Blueprint $table) => $table->dropColumn('admitted'));

        DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_admitted_within_admits_check');
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_admits_check');

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['admits', 'admitted_count']);
        });
    }
};
