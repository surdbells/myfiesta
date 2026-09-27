<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A refused answer to a dispute is asked again afresh.
 *
 * Each request that answers a dispute goes to Stripe under an idempotency key,
 * so a try repeated after a timeout is answered with what the first try did
 * rather than done twice. But Stripe keeps its answer to a key — a refusal or
 * one of its own errors included — and gives it back to that key for a day at
 * least: pressing Submit again after Stripe had a bad minute would only be
 * told the same thing, with a deadline running.
 *
 * So a try the processor refused spends its keys, and this counts how many
 * rounds have been spent; the next try's keys carry the count and are new.
 * A try that heard nothing back spends nothing, so its retry asks under the
 * same keys and learns what became of the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispute_evidence', function (Blueprint $table) {
            $table->unsignedSmallInteger('answer_round')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('dispute_evidence', function (Blueprint $table) {
            $table->dropColumn('answer_round');
        });
    }
};
