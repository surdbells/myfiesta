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

        // Invented nights and sales, for looking at the site on a laptop. Only
        // when a developer asks (SEED_DEMO_EVENTS), and never in production —
        // the seeder refuses there as well.
        if (config('discovery.seed_demo_events') && ! app()->isProduction()) {
            $this->call(DemoEventsSeeder::class);
        }
    }
}
