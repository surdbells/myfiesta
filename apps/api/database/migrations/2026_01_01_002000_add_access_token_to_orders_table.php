<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * How a guest gets back to their tickets.
 *
 * Guest checkout is the primary path, so most buyers have no account to sign
 * into — the emailed link is the whole of their access. That link was a Laravel
 * signed URL, which works only while the signature matches the URL it was
 * computed for: the ticket page is an Angular screen on another origin, so the
 * signature would never validate there.
 *
 * A random token per order instead. It is not derived from anything, so it
 * confirms nothing about the buyer if it leaks; it survives the signing key
 * being rotated; and the same token works from the site, the API and a wallet
 * pass later without three different URL shapes.
 *
 * Deliberately not expiring. The previous platform's tickets became unreachable
 * before some of its events had happened, and a ticket you cannot open on the
 * night is not a ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // 44 characters of base62. Long enough that guessing one is not a
            // strategy, short enough to survive an email client wrapping it.
            $table->string('access_token', 64)->nullable()->after('reference');
        });

        // Backfilled one row at a time rather than with a single SQL update:
        // gen_random_uuid() would give every order a token from the same
        // generator the ids come from, and the point is that the two are
        // unrelated. Orders are few enough at this stage for this to be cheap.
        foreach (DB::table('orders')->select('id')->cursor() as $order) {
            DB::table('orders')
                ->where('id', $order->id)
                ->update(['access_token' => Str::random(44)]);
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('access_token', 64)->nullable(false)->change();
            $table->unique('access_token');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['access_token']);
            $table->dropColumn('access_token');
        });
    }
};
