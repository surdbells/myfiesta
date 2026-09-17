<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two lists an account keeps for itself: nights worth remembering, and
 * organizers worth hearing from.
 *
 * Both are a person's own record and nobody else's. An organizer is told how
 * many follow them, never who — a follower list would turn a browsing habit
 * into something sellable, and nobody follows a party expecting that.
 *
 * Deliberately not a "like": saving is for later, not applause. There is no
 * count on an event page, so a quiet night does not advertise how quiet it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['user_id', 'event_id']);
            // The list is read newest-saved first, for one person at a time.
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('organization_follows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['user_id', 'organization_id']);
            $table->index(['organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_follows');
        Schema::dropIfExists('saved_events');
    }
};
