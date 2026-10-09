<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Product;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        Category::$auditingEnabled = Product::$auditingEnabled = Addon::$auditingEnabled = Discount::$auditingEnabled = false;

        $addons = collect([
            ['name' => 'Extra espresso shot', 'price' => 30],
            ['name' => 'Oat milk', 'price' => 35],
            ['name' => 'Almond milk', 'price' => 35],
            ['name' => 'Vanilla syrup', 'price' => 20],
            ['name' => 'Caramel drizzle', 'price' => 20],
            ['name' => 'Whipped cream', 'price' => 15],
            ['name' => 'Sea-salt cream foam', 'price' => 25],
        ])->mapWithKeys(fn ($a) => [$a['name'] => Addon::create($a + ['is_active' => true])]);

        $coffeeAddons = ['Extra espresso shot', 'Oat milk', 'Almond milk', 'Vanilla syrup', 'Caramel drizzle', 'Sea-salt cream foam'];
        $milkAddons = ['Oat milk', 'Almond milk', 'Vanilla syrup', 'Whipped cream'];
        $frappeAddons = ['Extra espresso shot', 'Whipped cream', 'Caramel drizzle', 'Oat milk'];

        $menu = [
            ['Espresso Classics', 'coffee', [
                ['Americano', 'ESP-AME', 'Double shot of HEIM house blend over hot or cold water.', [['12 oz Hot', 110], ['16 oz Iced', 130]], $coffeeAddons],
                ['Café Latte', 'ESP-LAT', 'Espresso with steamed milk and a light layer of foam.', [['12 oz Hot', 130], ['16 oz Iced', 150]], $coffeeAddons],
                ['Cappuccino', 'ESP-CAP', 'Equal parts espresso, steamed milk and thick foam.', [['8 oz Hot', 120], ['12 oz Hot', 135]], $coffeeAddons],
                ['Spanish Latte', 'ESP-SPL', 'Sweetened condensed milk latte — a café favourite.', [['12 oz Hot', 145], ['16 oz Iced', 165]], $coffeeAddons],
                ['Caramel Macchiato', 'ESP-CMA', 'Vanilla milk marked with espresso and caramel drizzle.', [['12 oz Hot', 150], ['16 oz Iced', 170]], $coffeeAddons],
                ['Mocha', 'ESP-MOC', 'Espresso, dark chocolate and steamed milk.', [['12 oz Hot', 150], ['16 oz Iced', 170]], $coffeeAddons],
                ['Davao Pour-Over', 'ESP-POV', 'Single-origin Mt. Apo beans, hand brewed.', [['Regular', 160]], []],
            ]],
            ['Non-Coffee', 'cup', [
                ['Matcha Latte', 'NCF-MAT', 'Ceremonial-grade matcha with milk.', [['12 oz Hot', 150], ['16 oz Iced', 170]], $milkAddons],
                ['Dark Chocolate', 'NCF-CHO', 'Rich Davao cacao hot or iced chocolate.', [['12 oz Hot', 135], ['16 oz Iced', 155]], $milkAddons],
                ['Strawberry Milk', 'NCF-STR', 'Real strawberry compote with fresh milk.', [['16 oz Iced', 150]], $milkAddons],
            ]],
            ['Frappés', 'snowflake', [
                ['Java Chip Frappé', 'FRP-JAV', 'Blended coffee with chocolate chips.', [['16 oz', 175], ['22 oz', 195]], $frappeAddons],
                ['Caramel Frappé', 'FRP-CAR', 'Blended coffee, caramel and cream.', [['16 oz', 170], ['22 oz', 190]], $frappeAddons],
                ['Matcha Cream Frappé', 'FRP-MAT', 'Blended matcha with milk and cream.', [['16 oz', 180], ['22 oz', 200]], $frappeAddons],
            ]],
            ['Tea', 'leaf', [
                ['Calamansi Iced Tea', 'TEA-CAL', 'House-brewed black tea with local calamansi.', [['16 oz', 95], ['22 oz', 115]], []],
                ['Chamomile', 'TEA-CHA', 'Soothing chamomile herbal tea.', [['12 oz Hot', 100]], []],
            ]],
            ['Pastries', 'cake', [
                ['Butter Croissant', 'PAS-CRO', 'Flaky, all-butter croissant baked daily.', [['Regular', 95]], []],
                ['Ensaymada', 'PAS-ENS', 'Soft brioche with butter, sugar and cheese.', [['Regular', 85]], []],
                ['Banana Bread', 'PAS-BAN', 'Moist banana bread with walnuts.', [['Slice', 75]], []],
                ['Blueberry Cheesecake', 'PAS-CHE', 'New York-style cheesecake with blueberry topping.', [['Slice', 165]], []],
                ['Chocolate Chip Cookie', 'PAS-COO', 'Chewy cookie with dark chocolate chunks.', [['Regular', 65]], []],
            ]],
        ];

        foreach ($menu as $sort => [$catName, $icon, $products]) {
            $category = Category::create(['name' => $catName, 'icon' => $icon, 'sort_order' => $sort, 'is_active' => true]);

            foreach ($products as [$name, $code, $desc, $variants, $addonNames]) {
                $product = Product::create([
                    'category_id' => $category->id, 'name' => $name, 'code' => $code, 'description' => $desc, 'is_available' => true,
                ]);
                foreach ($variants as $i => [$vName, $price]) {
                    $product->variants()->create(['name' => $vName, 'price' => $price, 'is_default' => $i === 0, 'sort_order' => $i]);
                }
                $product->addons()->sync($addons->only($addonNames)->pluck('id'));
            }
        }

        foreach ([
            ['name' => 'Senior Citizen', 'type' => 'percentage', 'value' => 20, 'is_vat_exempt' => true, 'requires_id' => true],
            ['name' => 'PWD', 'type' => 'percentage', 'value' => 20, 'is_vat_exempt' => true, 'requires_id' => true],
            ['name' => 'Employee', 'type' => 'percentage', 'value' => 10, 'is_vat_exempt' => false, 'requires_id' => false],
            ['name' => 'Loyalty ₱20 Off', 'type' => 'fixed', 'value' => 20, 'is_vat_exempt' => false, 'requires_id' => false],
        ] as $d) {
            Discount::create($d + ['is_active' => true]);
        }

        Category::$auditingEnabled = Product::$auditingEnabled = Addon::$auditingEnabled = Discount::$auditingEnabled = true;
    }
}
