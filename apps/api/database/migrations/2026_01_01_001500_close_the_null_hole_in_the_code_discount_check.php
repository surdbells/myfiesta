<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A check constraint that looked like it enforced something and did not.
 *
 * codes_currency_pairing_check was written to require a positive amount
 * whenever a discount type is set. It does not, because of how SQL treats
 * unknowns: with discount_value NULL, `discount_value > 0` is NULL rather than
 * false, the branch it sits in evaluates to NULL, and a CHECK that evaluates to
 * NULL is satisfied. So a percentage code with no percentage went in cleanly.
 *
 * What that cost: the code looked usable in the console, and every buyer who
 * typed it got a 500 from the quote endpoint — Money::percentage() received
 * null where it requires an int. A discount that breaks checkout is worse than
 * one that never existed, because the buyer has already decided to pay.
 *
 * The fix is to make each branch say what it means about NULL rather than
 * leaving it to be inferred. The validation in CodeController stops this at the
 * edge with a sentence a person can act on; this is the floor underneath it,
 * for anything that reaches the table another way.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Repair before tightening.
         *
         * Rows the old constraint let through cannot be left in place — the new
         * one is validated against every existing row, so a single half-written
         * code would make this migration unrunnable on any database that has
         * one. Failing the deploy and leaving somebody to fix it by hand at the
         * console is not better than fixing it here.
         *
         * A row that also carries a tracking slug is still a real code: strip
         * the discount that was never there and it goes on attributing sales.
         */
        DB::statement(<<<'SQL'
            UPDATE codes
            SET discount_type = NULL, discount_value = NULL, discount_currency = NULL
            WHERE discount_type IS NOT NULL
              AND discount_value IS NULL
              AND ref_slug IS NOT NULL
        SQL);

        /*
         * The rest never worked and never could. Quoting with one raised a type
         * error before any order existed, so there is nothing pointing at them
         * and nothing to preserve — unlike a redeemed code, which is why the
         * console deactivates rather than deletes.
         */
        DB::statement(<<<'SQL'
            DELETE FROM codes
            WHERE discount_type IS NOT NULL AND discount_value IS NULL
        SQL);

        DB::statement('ALTER TABLE codes DROP CONSTRAINT codes_currency_pairing_check');

        DB::statement(<<<'SQL'
            ALTER TABLE codes ADD CONSTRAINT codes_currency_pairing_check
            CHECK (
                (discount_type = 'fixed'
                    AND discount_currency IS NOT NULL
                    AND discount_value IS NOT NULL AND discount_value > 0) OR
                (discount_type = 'percentage'
                    AND discount_currency IS NULL
                    AND discount_value IS NOT NULL
                    AND discount_value > 0 AND discount_value <= 10000) OR
                (discount_type IS NULL
                    AND discount_currency IS NULL
                    AND discount_value IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE codes DROP CONSTRAINT codes_currency_pairing_check');

        DB::statement(<<<'SQL'
            ALTER TABLE codes ADD CONSTRAINT codes_currency_pairing_check
            CHECK (
                (discount_type = 'fixed'      AND discount_currency IS NOT NULL AND discount_value > 0) OR
                (discount_type = 'percentage' AND discount_currency IS NULL
                                              AND discount_value > 0 AND discount_value <= 10000) OR
                (discount_type IS NULL        AND discount_currency IS NULL AND discount_value IS NULL)
            )
        SQL);
    }
};
