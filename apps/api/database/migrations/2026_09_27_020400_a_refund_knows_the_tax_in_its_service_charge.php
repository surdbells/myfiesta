<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much of the service charge a refund gave back was tax.
 *
 * An order's service charge can now carry tax inside it, and the order says
 * how much (service_charge_tax_amount). A refund gives back its share of the
 * service charge and said nothing about how much of that share was tax — so
 * once anything was refunded, what the platform kept and what it owes a tax
 * authority could no longer be told apart. Each refund now records its share,
 * written by RefundService when the refund is, so a report can take the tax
 * out of the platform's figure and put it with the rest of the tax.
 *
 * Zero on every refund before this, which is what they gave back: no order
 * had tax on its service charge until the column on orders came in beside
 * this one. The update below only reaches a database where orders were taxed
 * that way and refunded before this ran, and gives those refunds their
 * proportion, rounded down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->bigInteger('service_charge_tax_amount')->default(0);
        });

        DB::statement(<<<'SQL'
            UPDATE refunds
            SET service_charge_tax_amount = LEAST(
                refunds.service_charge_amount,
                orders.service_charge_tax_amount * refunds.service_charge_amount / orders.service_charge_amount
            )
            FROM orders
            WHERE orders.id = refunds.order_id
              AND orders.service_charge_tax_amount > 0
              AND orders.service_charge_amount > 0
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE refunds ADD CONSTRAINT refunds_service_charge_tax_check
            CHECK (service_charge_tax_amount >= 0 AND service_charge_tax_amount <= service_charge_amount)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT IF EXISTS refunds_service_charge_tax_check');

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn('service_charge_tax_amount');
        });
    }
};
