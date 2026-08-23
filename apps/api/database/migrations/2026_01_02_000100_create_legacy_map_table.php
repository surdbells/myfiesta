<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each row in the old database became in this one.
 *
 * A `legacy_id` column on every table would be simpler to write and worse to
 * live with: it puts a dead foreign key from a decommissioned MySQL database
 * into the schema of tables that will outlive it by years, and it has nothing
 * to say about the rows — organizations, chiefly — that have no legacy row at
 * all but were derived from one.
 *
 * Keeping it here means the import can be run twice without creating anything
 * twice, which matters more than it sounds: 288 MB of poster blobs and 2,694
 * orders is a job that will be interrupted at least once, and the only sane
 * recovery is to start it again.
 *
 * It is also the audit trail for the cutover. When somebody asks in six months
 * why an event has a six-hour duration nobody chose, this is what says the row
 * came from the old system and what it looked like.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_map', function (Blueprint $table) {
            $table->id();

            // The old table the row came from: 'events', 'user_accounts',
            // 'tickets_sales'. Kept as the source's own name so the mapping
            // reads against the dump rather than against our vocabulary.
            $table->string('source_table');
            $table->string('source_id');

            // What it became here. Not a foreign key: the target lives in a
            // different table per row, and a constraint that cannot name its
            // target is not a constraint.
            $table->string('target_type');
            $table->uuid('target_id');

            /*
             * Anything that was inferred rather than read.
             *
             * An event's end time, a timezone guessed from currency, a
             * password that could not come across. Written so the run can
             * report what it had to invent, and so the inventions can be found
             * again later without re-deriving which ones they were.
             */
            $table->jsonb('inferred')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            // One row of the old system maps to one row here, and running the
            // import again finds it rather than making a second.
            $table->unique(['source_table', 'source_id']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_map');
    }
};
