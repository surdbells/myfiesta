<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A place has one tax rate on any given day.
 *
 * The first tax-rate migration meant this and enforced only half of it: its
 * index allows one open-ended rate per place, so two rates with end dates, or
 * one with an end date beside the open-ended one, could both be in force on
 * the same day. TaxRate::resolve() would then hand checkout whichever of the
 * two Postgres returned first, and a buyer's tax would depend on it.
 *
 * An exclusion constraint says it whole: no two rows for the same country
 * and province whose [effective_from, effective_to) share a day. A rate that
 * closes on the day its replacement starts does not overlap it, which is
 * exactly what a supersession leaves. Upper case on the province, as the
 * admin compares it.
 *
 * btree_gist lets the constraint compare the country and province for
 * equality beside the date ranges. It ships with Postgres and is a trusted
 * extension, so the database's owner can create it without a superuser.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement(<<<'SQL'
            ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_no_overlap
            EXCLUDE USING gist (
                country WITH =,
                (upper(COALESCE(subdivision, ''))) WITH =,
                daterange(effective_from, effective_to, '[)') WITH &&
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tax_rates DROP CONSTRAINT IF EXISTS tax_rates_no_overlap');
    }
};
