<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook deliveries already acted on.
 *
 * Gateways retry, sometimes for days, and both of ours will deliver the same
 * event more than once. Without a record of what has been handled, a retry of a
 * successful payment mints a second set of tickets for an order that was
 * already fulfilled.
 *
 * The unique index is the guarantee. A concurrent duplicate delivery loses the
 * insert rather than racing past a SELECT that found nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('gateway');
            $table->string('event_id');
            $table->timestampTz('processed_at')->useCurrent();

            $table->unique(['gateway', 'event_id']);
            // Old rows are swept once retries can no longer arrive.
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_webhooks');
    }
};
