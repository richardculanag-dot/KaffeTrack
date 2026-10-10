<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\AddonIngredientRule;
use App\Models\InventoryItem;
use App\Models\ItemUsage;
use App\Models\Product;
use App\Models\ProductIngredient;
use App\Services\ServingTracker;
use Illuminate\Database\Seeder;

/**
 * DEMO links between the seeded menu and the seeded inventory (Sales ↔ Inventory integration).
 *
 * Safe to run more than once, and on a database that is already seeded:
 *   php artisan db:seed --class=SalesInventoryLinkSeeder
 * It only creates missing links and fills demo "drinks served" numbers that are still empty.
 *
 * The "expected servings" below are STARTING GUESSES for the demo, not HEIM's real figures —
 * the system replaces them with the average of real used-up units once 3 exist.
 */
class SalesInventoryLinkSeeder extends Seeder
{
    /** sku => [starting guess of drinks per unit] — items whose open units are estimated. */
    private array $tracked = [
        'DY-MILK' => 6,     // Fresh Milk, carton (1 L)
        'DY-OAT' => 6,      // Oat Milk, carton (1 L)
        'DY-ALM' => 6,      // Almond Milk, carton (1 L)
        'DY-CON' => 12,     // Condensed Milk, can (390 g)
        'BN-HOUSE' => 50,   // House Blend Beans, bag (1 kg)
        'BN-APO' => 25,     // Mt. Apo Single-Origin, bag (500 g)
        'PW-MAT' => 60,     // Matcha Powder, pack (500 g)
        'BK-CRO' => 24,     // Croissant Dough, box (24 pcs)
    ];

    /** product code => [role => item sku] */
    private array $links = [
        'ESP-AME' => ['espresso' => 'BN-HOUSE'],
        'ESP-LAT' => ['espresso' => 'BN-HOUSE', 'milk' => 'DY-MILK'],
        'ESP-CAP' => ['espresso' => 'BN-HOUSE', 'milk' => 'DY-MILK'],
        'ESP-SPL' => ['espresso' => 'BN-HOUSE', 'milk' => 'DY-MILK', 'sweetener' => 'DY-CON'],
        'ESP-CMA' => ['espresso' => 'BN-HOUSE', 'milk' => 'DY-MILK', 'sweetener' => 'SY-VAN', 'sauce' => 'SY-CAR'],
        'ESP-MOC' => ['espresso' => 'BN-HOUSE', 'milk' => 'DY-MILK', 'sauce' => 'SY-CHO'],
        'ESP-POV' => ['base' => 'BN-APO'],
        'NCF-MAT' => ['base' => 'PW-MAT', 'milk' => 'DY-MILK'],
        'NCF-CHO' => ['sauce' => 'SY-CHO', 'milk' => 'DY-MILK'],
        'NCF-STR' => ['milk' => 'DY-MILK'],
        'FRP-JAV' => ['base' => 'PW-FRP', 'espresso' => 'BN-HOUSE', 'milk' => 'DY-MILK'],
        'FRP-CAR' => ['base' => 'PW-FRP', 'espresso' => 'BN-HOUSE', 'milk' => 'DY-MILK', 'sauce' => 'SY-CAR'],
        'FRP-MAT' => ['base' => 'PW-FRP', 'other' => 'PW-MAT', 'milk' => 'DY-MILK', 'topping' => 'DY-CRM'],
        'PAS-CRO' => ['base' => 'BK-CRO'],
    ];

    /** add-on name => [action, role, item sku] */
    private array $rules = [
        'Extra espresso shot' => ['add', 'extra shot', 'BN-HOUSE'],
        'Oat milk' => ['replace', 'milk', 'DY-OAT'],
        'Almond milk' => ['replace', 'milk', 'DY-ALM'],
        'Vanilla syrup' => ['add', 'vanilla', 'SY-VAN'],
        'Caramel drizzle' => ['add', 'caramel', 'SY-CAR'],
        'Whipped cream' => ['add', 'cream', 'DY-CRM'],
        'Sea-salt cream foam' => ['add', 'cream', 'DY-CRM'],
    ];

    public function run(): void
    {
        InventoryItem::$auditingEnabled = false;
        $items = InventoryItem::whereNotNull('sku')->get()->keyBy(fn ($i) => strtoupper($i->sku));

        foreach ($this->tracked as $sku => $guess) {
            $item = $items[$sku] ?? null;
            // Never overwrite a value a manager already set.
            if ($item && $item->expected_servings === null) {
                $item->forceFill(['tracks_servings' => true, 'expected_servings' => $guess])->save();
            }
        }

        foreach ($this->links as $code => $roles) {
            $product = Product::whereRaw('lower(code) = ?', [strtolower($code)])->first();
            foreach ($roles as $role => $sku) {
                if ($product && isset($items[$sku])) {
                    ProductIngredient::firstOrCreate(
                        ['product_id' => $product->id, 'role' => $role],
                        ['inventory_item_id' => $items[$sku]->id, 'servings' => 1],
                    );
                }
            }
        }

        foreach ($this->rules as $name => [$action, $role, $sku]) {
            $addon = Addon::where('name', $name)->first();
            if ($addon && isset($items[$sku])) {
                AddonIngredientRule::firstOrCreate(
                    ['addon_id' => $addon->id, 'role' => $role],
                    ['action' => $action, 'inventory_item_id' => $items[$sku]->id, 'servings' => 1],
                );
            }
        }

        // filter by SKU key (Eloquent's only() would filter by primary key instead)
        $this->demoHistory($items->filter(fn ($item, $sku) => isset($this->tracked[$sku])));
        InventoryItem::$auditingEnabled = true;
    }

    /**
     * Demo only: units used up before this feature existed have no "drinks served" yet. Give them
     * plausible numbers around the guess so the "usually serves" average can be demonstrated, and
     * start the estimate on units that are open right now.
     */
    private function demoHistory($items): void
    {
        mt_srand(2026);
        $tracker = new ServingTracker;

        foreach ($items as $item) {
            $guess = (int) $item->expected_servings;
            if ($guess <= 0) {
                continue;
            }

            ItemUsage::where('inventory_item_id', $item->id)
                ->where('status', ItemUsage::STATUS_DEPLETED)
                ->where('servings_used', 0)
                ->get()
                ->each(fn ($u) => $u->forceFill(['servings_used' => max(1, $guess + mt_rand(-(int) ceil($guess * 0.2), (int) ceil($guess * 0.2)))])->saveQuietly());

            $open = ItemUsage::where('inventory_item_id', $item->id)->where('status', ItemUsage::STATUS_IN_USE)
                ->whereNull('expected_servings')->first();
            if ($open) {
                $tracker->startUnit($item, $open);
                $open->refresh();
                if ($open->expected_servings) {
                    $used = (int) floor($open->expected_servings * mt_rand(30, 70) / 100);
                    $open->forceFill(['servings_used' => $used])->saveQuietly();
                }
            }
        }
    }
}
