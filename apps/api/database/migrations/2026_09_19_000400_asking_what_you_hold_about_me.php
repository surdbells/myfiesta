<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody asking for their data, or asking to be forgotten.
 *
 * PIPEDA and the NDPR both give people these two rights and thirty days to be
 * answered in. Guest checkout means most of the people who will ask have no
 * account to ask from, so a request is tied to an address and proved by
 * reaching it — the same way the unsubscribe link is.
 *
 * The row is kept after the request is done, without the export attached to
 * it: what was asked, when, and what was done about it is the evidence that
 * the law was obeyed, and it is the one part of a privacy request that must
 * outlive the data it was about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 16);
            $table->string('email');
            // Set when the address belongs to an account, so the map's
            // by_user half can be walked as well as its by_email half.
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            // The whole credential, in the link that is emailed. Nothing is
            // done until it comes back.
            // Dropped when a request expires unproved: a link that is gone
            // cannot be followed later.
            $table->string('token', 64)->nullable()->unique();
            $table->timestampTz('verified_at')->nullable();
            // Thirty days from verification: what the law allows, on the row,
            // so an overdue request is a query rather than a memory.
            $table->timestampTz('due_at')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestampTz('completed_at')->nullable();
            // What was done, or why it could not be: counts per table for an
            // erasure, a note for a refusal.
            $table->jsonb('outcome')->nullable();
            // Where the export sits until it expires. Private disk, never public.
            $table->string('file_path')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestampsTz();

            $table->index(['status', 'due_at']);
            $table->index('email');
        });

        DB::statement("
            ALTER TABLE data_requests ADD CONSTRAINT data_requests_kind_check
            CHECK (kind IN ('export', 'erasure'))
        ");

        DB::statement("
            ALTER TABLE data_requests ADD CONSTRAINT data_requests_status_check
            CHECK (status IN ('pending', 'verified', 'completed', 'refused', 'expired'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('data_requests');
    }
};
