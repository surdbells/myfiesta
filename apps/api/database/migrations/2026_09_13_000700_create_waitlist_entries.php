<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The waitlist for a sold-out event.
 *
 * A sold-out page with nothing to do on it loses the buyer to whoever is
 * selling a ticket in the comments. When places come back — refunds, a
 * released allocation, a new tier — the organizer had nobody to tell.
 *
 * One entry per address per event. The token is the whole credential for
 * leaving the list from an email, the same way unsubscribing works.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name', 120)->nullable();
            // How many they want — so "40 people for 90 tickets" can inform
            // how many places are worth releasing.
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('status')->default('waiting');
            $table->string('token', 64)->unique();
            $table->timestampTz('notified_at')->nullable();
            $table->timestampTz('purchased_at')->nullable();
            $table->timestampsTz();

            $table->unique(['event_id', 'email']);
            $table->index(['event_id', 'status', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE waitlist_entries ADD CONSTRAINT waitlist_entries_status_check
            CHECK (status IN ('waiting', 'notified', 'purchased', 'left'))
        SQL);

        DB::statement('ALTER TABLE waitlist_entries ADD CONSTRAINT waitlist_entries_quantity_check CHECK (quantity BETWEEN 1 AND 10)');
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
