<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moving an account to a new email address.
 *
 * Until this existed nobody could change the address they sign in with — the
 * profile endpoint refused it, because an address changed on the spot is a way
 * to take an account over from a borrowed laptop: change it, ask for a reset
 * link, and the owner is locked out of their own tickets.
 *
 * So the change waits here until somebody opens a link sent to the new
 * address, which is the only proof anybody has that they can read it. The
 * token is stored hashed, like an invitation's: the database holds nothing
 * that completes the change, only something to check a presented link against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // One per account. Asking again replaces the earlier request rather
            // than leaving two links that would both move the account.
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            // The address it would move to, lowercased as every address here is.
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            // The session that asked. It stays signed in when the change goes
            // through; every other one is signed out, as a new password does.
            $table->foreignId('requested_by_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_changes');
    }
};
