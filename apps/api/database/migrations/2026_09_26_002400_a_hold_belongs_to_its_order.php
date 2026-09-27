<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hold says whose it is.
 *
 * It never did. A hold recorded what was reserved and until when, and nothing
 * about the checkout that reserved it — so when an order was paid, the hold
 * given up was simply the oldest live one for the same ticket type, whoever's
 * it was. Paying for one order released another shopper's reservation while
 * they were still on the payment page. And the number given up was counted in
 * rows although each row carries a quantity, so an order for four released
 * four people's holds, not its own four places.
 *
 * Holds taken before this ran have no order and are left that way: nothing
 * can say whose they were. They stop counting when they expire, as they
 * always did, which is within the hour.
 *
 * Deleted with the order, because a hold with nobody behind it is stock
 * nobody can buy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_holds', function (Blueprint $table) {
            $table->foreignUuid('order_id')->nullable()->index()
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_holds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
