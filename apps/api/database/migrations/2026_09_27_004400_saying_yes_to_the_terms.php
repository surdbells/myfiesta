<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * That somebody agreed to the terms, the privacy policy and the refund policy,
 * when, and to which version of them.
 *
 * Until this existed the checkout had a box to tick and nothing kept it: the
 * box never reached the server, and signing up asked nothing at all. Asked
 * later what a buyer had agreed to, the only honest answer was "whatever the
 * pages said that day, probably".
 *
 * Kept in three places, because the agreement is made in three situations:
 *
 * - on the order, for everybody who buys — guest checkout is the primary
 *   path, and an order has to stay explainable against the words that applied
 *   when it was placed, like its prices and its tax;
 * - on the account, for somebody who signed up or bought while signed in, so
 *   they are asked once rather than at every checkout, and asked again when
 *   the words change;
 * - on a sign-up still waiting for its link, because the account it becomes
 *   does not exist yet when the box is ticked (see SignUps::complete()).
 *
 * The version is config('terms.version'), a name for the words, not a copy of
 * them. Nothing else about the moment is kept — not the address the request
 * came from, not the browser — because the privacy page does not say we hold
 * either, and it is written from what we actually hold.
 *
 * Nullable throughout. Orders and accounts from before this, and every door
 * sale, have nothing to record, and an invented date would be worse than none.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'orders', 'pending_registrations'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('terms_version', 32)->nullable();
                $table->timestampTz('terms_accepted_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'orders', 'pending_registrations'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['terms_version', 'terms_accepted_at']);
            });
        }
    }
};
