<?php

use Database\Seeders\TaxRateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The tax rates the platform launches with, installed by a deploy.
 *
 * Production runs `migrate`, not `db:seed`. Until now the rates came only from
 * the seeder, so a fresh production database would have charged no tax at
 * all — every quote resolving to no rate, and every order explaining itself
 * as untaxed — until somebody remembered to seed it.
 *
 * Idempotent, and safe on a database that was seeded already: a rate is added
 * only for a place that has none. Nothing that exists is changed, because
 * rates are superseded, never edited. The list itself is TaxRateSeeder's, so
 * development and production cannot drift apart.
 *
 * Not while the test suite runs. Its tests each build the rates they are
 * about — an Ontario event priced with no rate at all is how most of them
 * check the arithmetic without tax in the way — and have always started
 * from an empty table. The suite calls install() itself to check exactly what
 * a deploy runs.
 *
 * No down(): orders point at these rows, and a rate that priced a sale is not
 * removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $this->install();
    }

    public function install(): int
    {
        return (new TaxRateSeeder)->install();
    }

    public function down(): void
    {
        //
    }
};
