<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Other systems an organizer runs.
 *
 * We have always consumed webhooks — from Stripe and Paystack — and emitted
 * none. A larger promoter wants an order arriving in their own system the
 * moment it is paid, and a key to read their own sales without somebody
 * exporting a spreadsheet every Monday. It is also the cheapest route to the
 * integrations everybody asks for by name — Zapier, a mailing list, a CRM —
 * without building any of them: each of those can take a webhook.
 *
 * Two directions, three tables. Endpoints we push to, the deliveries we made
 * to them, and keys somebody else uses to pull from us.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Where to send things.
         *
         * The secret is what the receiver checks our signature against. It is
         * stored encrypted rather than hashed, because we have to use it to
         * sign — and shown once, when it is made, like a password.
         */
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('url', 2048);
            $table->text('secret');
            $table->jsonb('events');
            $table->string('description')->nullable();

            // Turned off by hand, or by us after a long run of failures — a
            // receiver that has been down for days is one we stop knocking on,
            // and say so, rather than one we retry for ever.
            $table->timestampTz('disabled_at')->nullable();
            $table->string('disabled_reason')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('organization_id');
        });

        /*
         * Every attempt to tell somebody something.
         *
         * Kept so an organizer can see what was sent and what came back — the
         * first thing anybody wiring up an integration needs — and pruned after
         * a month, because a payload carries a buyer's name and address and a
         * delivery log has no business keeping those for ever.
         */
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('event');
            $table->jsonb('payload');

            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            // What the receiver said, trimmed. Enough to debug a 400, short of
            // storing somebody's whole error page.
            $table->text('response_excerpt')->nullable();

            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampsTz();

            $table->index(['webhook_endpoint_id', 'created_at']);
            $table->index(['status', 'next_attempt_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check
            CHECK (status IN ('pending', 'succeeded', 'failed'))
        SQL);

        /*
         * Keys for reading our own data from somewhere else.
         *
         * Hashed, never stored — the key is shown once and after that only its
         * last four characters, so a leaked database is not a set of working
         * credentials. Belong to the organization rather than to the person
         * who made them: a key in a promoter's accounting system should not
         * stop working because the owner who created it left.
         */
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->char('token_hash', 64)->unique();
            $table->char('last_four', 4);

            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
