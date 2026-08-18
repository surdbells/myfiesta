<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tax rates, keyed on jurisdiction.
 *
 * The obvious shortcut is to key them on currency, since CAD implies Canada and
 * NGN implies Nigeria. It is wrong for most of Canada: rates vary by province —
 * 5% in Alberta, 13% in Ontario, 15% in Nova Scotia — while Nigerian VAT is a
 * flat 7.5%, which is what makes the shortcut look like it works.
 *
 * So: country plus optional subdivision, with currency kept only as a default
 * lookup hint. Rates are administered in Filament, and each records whether it
 * is already included in the displayed price or added at checkout.
 *
 * Rates are versioned by effective date rather than edited in place. A historic
 * order must always be reproducible against the rate that applied when it was
 * placed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->char('country', 2);                        // ISO 3166-1 alpha-2
            $table->string('subdivision', 8)->nullable();      // ISO 3166-2 suffix: ON, AB, NS
            $table->char('default_currency', 3)->nullable();   // lookup hint only

            $table->string('name');                            // "HST", "GST", "VAT"

            // Basis points: 1300 = 13%. Integer, so no float drift in a value
            // that multiplies money.
            $table->unsignedInteger('rate_bps');

            // true  — the displayed price already contains this tax
            // false — it is added at checkout
            $table->boolean('inclusive')->default(false);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->timestampsTz();

            $table->index(['country', 'subdivision', 'effective_from']);
        });

        DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_rate_bps_check CHECK (rate_bps >= 0 AND rate_bps <= 10000)');
        DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_effective_range_check CHECK (effective_to IS NULL OR effective_to > effective_from)');

        // One active rate per jurisdiction at a time. Without this, two
        // overlapping Ontario rates would make checkout non-deterministic.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX tax_rates_active_unique
            ON tax_rates (country, COALESCE(subdivision, ''))
            WHERE effective_to IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
