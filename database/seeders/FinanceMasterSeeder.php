<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class FinanceMasterSeeder extends Seeder
{
    public function run(): void
    {
        ExpenseCategory::$auditingEnabled = false;

        ExpenseCategory::inventoryPurchases();
        foreach ([
            ['Rent', 'Monthly store lease'],
            ['Utilities', 'Electricity, water and internet'],
            ['Salaries & Wages', 'Staff payroll'],
            ['Supplies', 'Cleaning materials, tissue, small wares'],
            ['Maintenance & Repairs', 'Espresso machine servicing, repairs'],
            ['Marketing', 'Social media ads, printed materials'],
            ['Miscellaneous', 'Other operating expenses'],
        ] as [$name, $desc]) {
            ExpenseCategory::create(['name' => $name, 'description' => $desc, 'is_system' => false]);
        }

        ExpenseCategory::$auditingEnabled = true;
    }
}
