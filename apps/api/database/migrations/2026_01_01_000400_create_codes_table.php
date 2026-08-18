<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One codes namespace, covering discounts and promoter attribution.
 *
 * These look like two features and are one object. In nightlife the discount
 * code *is* the promoter's attribution — it is how they prove they drove the
 * sale. Modelling them separately means reconciling two systems later, so a
 * code may discount, attribute, or do both.
 *
 * Attribution has to exist from the first migration whether or not the
 * reporting does, because it cannot be reconstructed after the fact: a sale
 * that arrived without its referrer recorded has lost that fact permanently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // Null scopes the code to every event the organization runs.
            $table->foreignUuid('event_id')->nullable()->constrained()->cascadeOnDelete();

            // Stored uppercase; matching is case-insensitive at the query layer.
            $table->string('code', 64);
            $table->string('label')->nullable();     // "Summer promo", "Ade's list"

            // --- discount ---------------------------------------------------
            // Null discount_type means attribution only: the code tracks who
            // drove the sale without changing the price.
            $table->string('discount_type')->nullable();     // percentage | fixed
            $table->integer('discount_value')->nullable();   // bps, or minor units

            // Required for fixed-amount codes, forbidden for percentage ones.
            // A ₦2,000 code cannot apply to a CAD event; percentage codes travel
            // between currencies without meaning anything different.
            $table->char('discount_currency', 3)->nullable();

            // --- attribution ------------------------------------------------
            // Matches the ?ref= parameter on a shared link. A buyer never types
            // this; it is captured on landing and carried to the order.
            $table->string('ref_slug')->nullable();
            $table->string('promoter_name')->nullable();

            // --- limits -----------------------------------------------------
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedSmallInteger('max_per_customer')->nullable();

            // Incremented inside the same transaction as the inventory hold.
            // Counting it anywhere else lets a capped code oversell exactly the
            // way tickets would.
            $table->unsignedInteger('redemption_count')->default(0);

            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'is_active']);
            $table->index('ref_slug');
        });

        // Codes are unique per event, and separately unique across an
        // organization's event-wide codes. COALESCE gives both in one index,
        // since Postgres treats NULLs as distinct.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX codes_scope_unique
            ON codes (organization_id, COALESCE(event_id::text, ''), upper(code))
            WHERE deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE codes ADD CONSTRAINT codes_discount_type_check
            CHECK (discount_type IS NULL OR discount_type IN ('percentage', 'fixed'))
        SQL);

        // A code must do something: discount, attribute, or both.
        DB::statement(<<<'SQL'
            ALTER TABLE codes ADD CONSTRAINT codes_purposeful_check
            CHECK (discount_type IS NOT NULL OR ref_slug IS NOT NULL)
        SQL);

        // Fixed amounts carry a currency; percentages must not.
        DB::statement(<<<'SQL'
            ALTER TABLE codes ADD CONSTRAINT codes_currency_pairing_check
            CHECK (
                (discount_type = 'fixed'      AND discount_currency IS NOT NULL AND discount_value > 0) OR
                (discount_type = 'percentage' AND discount_currency IS NULL
                                              AND discount_value > 0 AND discount_value <= 10000) OR
                (discount_type IS NULL        AND discount_currency IS NULL AND discount_value IS NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE codes ADD CONSTRAINT codes_redemption_ceiling_check
            CHECK (max_redemptions IS NULL OR redemption_count <= max_redemptions)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('codes');
    }
};
