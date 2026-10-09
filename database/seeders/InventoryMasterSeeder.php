<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

class InventoryMasterSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::$auditingEnabled = ItemCategory::$auditingEnabled = InventoryItem::$auditingEnabled = false;

        $suppliers = collect([
            ['name' => 'Mt. Apo Coffee Roasters', 'contact_person' => 'Ramon Dizon', 'phone' => '0918 222 1001', 'email' => 'orders@mtaporoasters.ph', 'address' => 'Kidapawan City, Cotabato'],
            ['name' => 'Mindanao Dairy Co-op', 'contact_person' => 'Liza Gomez', 'phone' => '0918 222 1002', 'email' => 'sales@mindanaodairy.ph', 'address' => 'Malagos, Davao City'],
            ['name' => 'Davao Café Supply Hub', 'contact_person' => 'Kevin Tan', 'phone' => '0918 222 1003', 'email' => 'hello@cafesupplyhub.ph', 'address' => 'Bajada, Davao City'],
            ['name' => 'Sweet Crumbs Bakeshop', 'contact_person' => 'Marites Lim', 'phone' => '0918 222 1004', 'email' => 'sweetcrumbs@gmail.com', 'address' => 'Lanang, Davao City'],
        ])->mapWithKeys(fn ($s) => [$s['name'] => Supplier::create($s + ['is_active' => true])]);

        $cats = collect(['Coffee Beans', 'Dairy & Alternatives', 'Syrups & Sauces', 'Powders', 'Packaging', 'Bakery'])
            ->mapWithKeys(fn ($n) => [$n => ItemCategory::create(['name' => $n])]);

        // [name, sku, category, unit, reorder level, track expiry, supplier]
        $items = [
            ['HEIM House Blend Beans', 'BN-HOUSE', 'Coffee Beans', 'bag (1 kg)', 3, true, 'Mt. Apo Coffee Roasters'],
            ['Mt. Apo Single-Origin Beans', 'BN-APO', 'Coffee Beans', 'bag (500 g)', 2, true, 'Mt. Apo Coffee Roasters'],
            ['Fresh Milk', 'DY-MILK', 'Dairy & Alternatives', 'carton (1 L)', 8, true, 'Mindanao Dairy Co-op'],
            ['Oat Milk', 'DY-OAT', 'Dairy & Alternatives', 'carton (1 L)', 4, true, 'Davao Café Supply Hub'],
            ['Almond Milk', 'DY-ALM', 'Dairy & Alternatives', 'carton (1 L)', 3, true, 'Davao Café Supply Hub'],
            ['Condensed Milk', 'DY-CON', 'Dairy & Alternatives', 'can (390 g)', 4, true, 'Davao Café Supply Hub'],
            ['Whipping Cream', 'DY-CRM', 'Dairy & Alternatives', 'carton (1 L)', 2, true, 'Mindanao Dairy Co-op'],
            ['Vanilla Syrup', 'SY-VAN', 'Syrups & Sauces', 'bottle (750 ml)', 2, true, 'Davao Café Supply Hub'],
            ['Caramel Sauce', 'SY-CAR', 'Syrups & Sauces', 'bottle (1 L)', 2, true, 'Davao Café Supply Hub'],
            ['Dark Chocolate Sauce', 'SY-CHO', 'Syrups & Sauces', 'bottle (1 L)', 2, true, 'Davao Café Supply Hub'],
            ['Matcha Powder', 'PW-MAT', 'Powders', 'pack (500 g)', 2, true, 'Davao Café Supply Hub'],
            ['Frappé Base Powder', 'PW-FRP', 'Powders', 'pack (1 kg)', 2, true, 'Davao Café Supply Hub'],
            ['16 oz Cold Cups', 'PK-CUP16', 'Packaging', 'sleeve (50 pcs)', 4, false, 'Davao Café Supply Hub'],
            ['12 oz Hot Cups', 'PK-CUP12', 'Packaging', 'sleeve (50 pcs)', 4, false, 'Davao Café Supply Hub'],
            ['Paper Straws', 'PK-STR', 'Packaging', 'box (200 pcs)', 2, false, 'Davao Café Supply Hub'],
            ['Croissant Dough (frozen)', 'BK-CRO', 'Bakery', 'box (24 pcs)', 1, true, 'Sweet Crumbs Bakeshop'],
        ];

        foreach ($items as [$name, $sku, $cat, $unit, $reorder, $expiry, $supplier]) {
            InventoryItem::create([
                'item_category_id' => $cats[$cat]->id,
                'default_supplier_id' => $suppliers[$supplier]->id,
                'name' => $name,
                'sku' => $sku,
                'unit' => $unit,
                'reorder_level' => $reorder,
                'track_expiry' => $expiry,
                'is_active' => true,
            ]);
        }

        Supplier::$auditingEnabled = ItemCategory::$auditingEnabled = InventoryItem::$auditingEnabled = true;
    }
}
