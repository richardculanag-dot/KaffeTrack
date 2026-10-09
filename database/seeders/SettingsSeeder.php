<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        Setting::$auditingEnabled = false;

        $values = array_merge(Setting::DEFAULTS, [
            'store_name' => 'HEIM Café',
            'store_address' => 'Matina, Davao City, Philippines',
            'store_contact' => '0917 123 4567',
            'store_tin' => '000-000-000-00000',
        ]);

        foreach ($values as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Setting::$auditingEnabled = true;
    }
}
