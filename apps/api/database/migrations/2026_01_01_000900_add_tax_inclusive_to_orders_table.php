<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the tax on an order was inside the price or added to it.
 *
 * The original constraint assumed tax is always added:
 *
 *     total = subtotal - discount + tax
 *
 * True in Canada, where prices are advertised before tax. False in Nigeria,
 * where VAT is quoted inside the price — there, a ₦1,075 ticket contains ₦75 of
 * VAT and the buyer is charged ₦1,075, not ₦1,150. Applying the original rule
 * to an inclusive rate charges the tax twice.
 *
 * Storing the flag keeps the order honest about what was displayed, rather than
 * back-computing a tax-exclusive subtotal that matches no price the buyer ever
 * saw. The constraint then asserts whichever arithmetic actually applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('tax_inclusive')->default(false)->after('tax_amount');
        });

        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_total_arithmetic_check');

        // Two assertions, not one.
        //
        // The total is now expressible in a single form for both tax modes,
        // because net revenue means the same thing under each: what the
        // organizer earned, tax excluded whichever side of the price it sat.
        // The service charge is added for the buyer and appears here for the
        // same reason the tax does — it is part of what was charged and no
        // part of what was earned.
        //
        // The second clause is what the tax_inclusive flag is actually for: it
        // pins net revenue to the arithmetic that applied, so the flag cannot
        // disagree with the figures it describes.
        DB::statement(<<<'SQL'
            ALTER TABLE orders ADD CONSTRAINT orders_total_arithmetic_check
            CHECK (
                total_amount = net_revenue_amount + tax_amount + service_charge_amount
                AND (
                    (tax_inclusive = false AND net_revenue_amount = subtotal_amount - discount_amount)
                    OR
                    (tax_inclusive = true  AND net_revenue_amount = subtotal_amount - discount_amount - tax_amount)
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_total_arithmetic_check');

        DB::statement(<<<'SQL'
            ALTER TABLE orders ADD CONSTRAINT orders_total_arithmetic_check
            CHECK (total_amount = subtotal_amount - discount_amount + tax_amount)
        SQL);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('tax_inclusive');
        });
    }
};
