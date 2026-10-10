<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * php artisan migrate:fresh --seed
     *
     * Set SEED_DEMO_DATA=false in .env to seed only accounts + master data (no sales history).
     */
    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
            UserSeeder::class,
            CatalogSeeder::class,
            InventoryMasterSeeder::class,
            FinanceMasterSeeder::class,
        ]);

        if (filter_var(env('SEED_DEMO_DATA', true), FILTER_VALIDATE_BOOLEAN)) {
            $this->call(DemoHistorySeeder::class);
        }

        // Demo links between menu and inventory (after history, so past units get demo counts).
        $this->call(SalesInventoryLinkSeeder::class);
    }
}
