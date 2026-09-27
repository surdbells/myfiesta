<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sign-up, held until somebody opens the link sent to its address.
 *
 * Until this existed, signing up made the account on the spot and handed back
 * a session. That made the answer to "is this address registered?" the
 * difference between a token and no token, whatever the message said; and it
 * let anybody make an account under somebody else's address, which tickets
 * later bought as a guest with that address would then belong to.
 *
 * So nothing is made until the address is proved. What was typed waits here —
 * the password already hashed, exactly as it would sit on an account — and
 * becomes an account when the link is opened. Several can wait for one
 * address, so a stranger signing up with it cannot cancel the owner's own
 * link; the first one opened wins and the rest go with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Lowercased, as every address here is.
            $table->string('email')->index();
            $table->string('name');
            // A hash, never the password.
            $table->string('password');
            // The events page to open alongside the account. Empty for
            // somebody who is going out rather than putting something on.
            $table->string('organization')->nullable();
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_registrations');
    }
};
