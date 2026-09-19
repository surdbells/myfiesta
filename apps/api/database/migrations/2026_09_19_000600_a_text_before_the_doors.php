<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numbers that have said stop.
 *
 * The same idea as email_preferences and kept for the same reason: a
 * suppression list only works if it outlives everything else. Somebody who
 * replies STOP and later buys another ticket must not start getting texts
 * again, so the number survives an erasure — it is held in order to be left
 * alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_preferences', function (Blueprint $table) {
            // E.164 with the plus. One row per number, whoever is holding it.
            $table->string('phone', 20)->primary();
            $table->timestampTz('opted_out_at');
            // What they replied, so a dispute about whether they opted out has
            // an answer.
            $table->string('via', 32)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_preferences');
    }
};
