<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Reference data the platform cannot operate without. Safe to re-run:
        // every seeder here uses updateOrCreate.
        $this->call([
            TaxRateSeeder::class,
        ]);
    }
}
