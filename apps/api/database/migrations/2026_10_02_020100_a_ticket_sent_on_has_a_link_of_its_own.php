<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ticket sent to somebody else has a link of its own.
 *
 * The order's link belongs to whoever paid, and it carries their receipt and
 * everything else they bought. Somebody a ticket was sent to gets a link that
 * opens that ticket and nothing else (TicketHandover, TicketAccessController),
 * while it is still theirs.
 *
 * Nullable, because transfers made before this have no link, and none can be
 * made for them now: nobody would receive it. Unique, because the token is
 * the whole credential, as an order's is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_transfers', function (Blueprint $table) {
            $table->string('access_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_transfers', function (Blueprint $table) {
            $table->dropUnique(['access_token']);
            $table->dropColumn('access_token');
        });
    }
};
