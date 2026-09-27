<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An order keeps a copy of how it was taxed, and by whom it was sold.
 *
 * A pointer to a tax rate was enough while each place had one tax and the
 * rest of the pricing was fixed in configuration. Now QST can sit beside GST,
 * the service charge can be taxed, the seller can be the organizer or the
 * platform, and all of it is a setting staff can change on a Tuesday. A
 * receipt printed next year has to say what was charged this year, so each
 * order carries it:
 *
 *   tax_lines                 — every tax, with its name, rate, what it was
 *                               charged on and how much. Sums to tax_amount
 *                               for the tickets, and to
 *                               service_charge_tax_amount for the fee.
 *   service_charge_tax_amount — the part of service_charge_amount that is tax.
 *                               Inside it, not beside it, so the arithmetic
 *                               every existing check holds the order to is
 *                               untouched: total = net revenue + tax +
 *                               service charge, as before.
 *   seller_of_record          — organizer or platform, as it was.
 *   pricing_snapshot          — the service charge rate, the switches, and the
 *                               names and registration numbers the receipt
 *                               prints.
 *
 * Orders placed before this have nulls and zero, and read as they always did:
 * one tax, the organizer as seller, no tax on the service charge — which is
 * what was true of every one of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->bigInteger('service_charge_tax_amount')->default(0);
            $table->string('seller_of_record', 16)->nullable();
            $table->jsonb('tax_lines')->nullable();
            $table->jsonb('pricing_snapshot')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE orders ADD CONSTRAINT orders_service_charge_tax_check
            CHECK (service_charge_tax_amount >= 0 AND service_charge_tax_amount <= service_charge_amount)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE orders ADD CONSTRAINT orders_seller_of_record_check
            CHECK (seller_of_record IS NULL OR seller_of_record IN ('organizer', 'platform'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_seller_of_record_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_service_charge_tax_check');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['service_charge_tax_amount', 'seller_of_record', 'tax_lines', 'pricing_snapshot']);
        });
    }
};
