<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The internet address an online order came from, and the browser that sent it.
 *
 * The one thing added to what is kept about a person, and for two reasons
 * only: catching somebody working through stolen cards, and answering a bank
 * when a buyer says months later that they never bought anything. A bank
 * weighs "the order came from the same address and browser that opened the
 * tickets on the night" far above anything an organizer can say about it.
 *
 * Not a fingerprint and not a location. It is what every request carries
 * anyway, as the application believes it (TRUSTED_PROXIES), written once
 * when the order is made and read by nobody but staff answering a dispute.
 * Nothing is worked out from it.
 *
 * Null for a door sale, which is made on the organizer's phone and says
 * nothing about the person paying; for every order made before this; and,
 * 18 months after the event, for every order at all (disputes:prune-evidence),
 * which is longer than any card network allows a payment to be disputed.
 * An erasure request clears them too (config/personal_data.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Long enough for IPv6 written out in full.
            $table->string('purchase_ip', 45)->nullable();
            // Cut to this length on the way in: a browser can send any
            // amount, and the start of it is the part that says what it is.
            $table->string('purchase_user_agent', 512)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['purchase_ip', 'purchase_user_agent']);
        });
    }
};
