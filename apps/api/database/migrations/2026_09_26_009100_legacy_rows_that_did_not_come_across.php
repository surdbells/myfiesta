<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rows of the old database that did not make it across, and why.
 *
 * The import commits one source row at a time, and a row that fails is rolled
 * back whole and the run carries on. That is the right behaviour and it has a
 * cost: a failure is now something the run survives, so it is something a
 * person can miss. This is where it is written down.
 *
 * Kept apart from legacy_map because the two answer different questions.
 * legacy_map says what a row became; this says what it has not become yet.
 * A row that fails and later succeeds is marked resolved rather than removed,
 * so the cutover's record shows it needed a second attempt. So is one a person
 * chose to leave behind, with their reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_import_failures', function (Blueprint $table) {
            $table->id();

            // In the source's own vocabulary, as legacy_map is: 'tickets_sales'
            // and a sales_id, so the row can be found in the dump.
            $table->string('source_table');
            $table->string('source_id');

            // The database's own sentence about it, with the values taken out.
            // A constraint name says what went wrong; the row's contents,
            // which can include a ticket code, stay in the source.
            $table->text('reason');

            $table->unsignedInteger('attempts')->default(1);
            $table->timestampTz('first_failed_at');
            $table->timestampTz('last_failed_at');
            $table->timestampTz('resolved_at')->nullable();

            // Set when a person decided the row stays behind (legacy:import
            // --leave-behind), and why. Resolved either way, but not the same
            // thing as having come across, and the record says which.
            $table->text('left_behind_because')->nullable();

            $table->unique(['source_table', 'source_id']);
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_import_failures');
    }
};
