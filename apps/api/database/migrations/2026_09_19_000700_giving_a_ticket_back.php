<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Giving a ticket back, at what was paid for it.
 *
 * The argument against touts, and it matters most here: a sold-out night whose
 * tickets reappear at triple on Instagram is the normal state of things in
 * both markets. Somebody who cannot go returns the ticket, gets their money
 * back when it sells, and the place goes back on sale at the organizer's own
 * price to whoever is next.
 *
 * Deliberately not a marketplace. There is no asking price, no bidding and no
 * choosing whose ticket to buy: a returned place is ordinary stock again, and
 * the next buyer goes through the ordinary checkout without knowing or caring
 * that the place came back. That removes the whole surface — price gouging,
 * fake listings, buyers paying strangers — rather than policing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // The organizer's own decision. Off unless they turn it on: a
            // night where returns are impossible is a legitimate way to run a
            // night, and this changes who is standing in the room.
            $table->boolean('resale_enabled')->default(false);
            // No returns once it is nearly here. A ticket returned an hour
            // before the doors is unlikely to sell, and the seller is left
            // thinking they have been refunded when they have not.
            $table->unsignedSmallInteger('resale_closes_hours')->default(24);
        });

        // A ticket that has been given back and is waiting to be resold. It is
        // neither valid (its holder cannot get in with it) nor refunded (no
        // money has moved yet), so it gets its own state.
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT tickets_status_check');
        DB::statement("
            ALTER TABLE tickets ADD CONSTRAINT tickets_status_check
            CHECK (status IN ('valid', 'checked_in', 'transferred', 'refunded', 'void', 'listed'))
        ");

        Schema::create('resale_listings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ticket_type_id')->constrained()->cascadeOnDelete();
            // Who gets the money back, which is whoever paid — never an
            // address typed in at the time of listing.
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            // What they paid for it, worked out when it is listed so a price
            // change afterwards cannot alter what they are owed.
            $table->unsignedBigInteger('price_amount');
            $table->string('currency', 3);
            $table->string('status', 16)->default('listed');
            $table->timestampTz('listed_at');
            $table->timestampTz('sold_at')->nullable();
            // The order that took the place, for tracing one to the other.
            $table->foreignUuid('sold_to_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignUuid('refund_id')->nullable()->constrained('refunds')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['ticket_type_id', 'status', 'listed_at']);
            $table->index(['event_id', 'status']);
        });

        DB::statement("
            ALTER TABLE resale_listings ADD CONSTRAINT resale_listings_status_check
            CHECK (status IN ('listed', 'sold', 'cancelled'))
        ");

        // One live listing per ticket. Two would sell one place twice.
        DB::statement("
            CREATE UNIQUE INDEX resale_listings_one_live_per_ticket
            ON resale_listings (ticket_id) WHERE status = 'listed'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('resale_listings');

        DB::statement('ALTER TABLE tickets DROP CONSTRAINT tickets_status_check');
        DB::statement("
            ALTER TABLE tickets ADD CONSTRAINT tickets_status_check
            CHECK (status IN ('valid', 'checked_in', 'transferred', 'refunded', 'void'))
        ");

        Schema::table('events', fn (Blueprint $table) => $table->dropColumn(['resale_enabled', 'resale_closes_hours']));
    }
};
