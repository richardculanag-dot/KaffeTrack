<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\AddonIngredientRule;
use App\Models\CashShift;
use App\Models\InventoryItem;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductIngredient;
use App\Models\ShiftStockCheck;
use App\Models\StockMovement;
use App\Services\CashShiftService;
use App\Services\RecipeResolver;
use Illuminate\Database\Seeder;

/**
 * DEMO links between the seeded menu and the seeded inventory (Sales ↔ Inventory integration).
 *
 * Safe to run more than once, and on a database that is already seeded:
 *   php artisan db:seed --class=SalesInventoryLinkSeeder
 * It only creates what is missing:
 *   1. which inventory items each demo product uses, and what each add-on changes;
 *   2. for the last 7 days of demo orders, which items each sold line used;
 *   3. for the last 7 days of closed demo shifts, an end-of-shift stock check that matched
 *      (so the reports have history to show — a live demo shift shows real differences).
 */
class SalesInventoryLinkSeeder extends Seeder
{
    private const DEMO_DAYS = 7;

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
        $items = InventoryItem::whereNotNull('sku')->get()->keyBy(fn ($i) => strtoupper($i->sku));

        foreach ($this->links as $code => $roles) {
            $product = Product::whereRaw('lower(code) = ?', [strtolower($code)])->first();
            foreach ($roles as $role => $sku) {
                if ($product && isset($items[$sku])) {
                    ProductIngredient::firstOrCreate(['product_id' => $product->id, 'role' => $role], ['inventory_item_id' => $items[$sku]->id]);
                }
            }
        }

        foreach ($this->rules as $name => [$action, $role, $sku]) {
            $addon = Addon::where('name', $name)->first();
            if ($addon && isset($items[$sku])) {
                AddonIngredientRule::firstOrCreate(['addon_id' => $addon->id, 'role' => $role], ['action' => $action, 'inventory_item_id' => $items[$sku]->id]);
            }
        }

        $this->backfillOrderItems();
        $this->demoStockChecks();
    }

    /** Demo orders were created before the links existed: record which items their lines used. */
    private function backfillOrderItems(): void
    {
        $resolver = new RecipeResolver;
        $addons = Addon::withTrashed()->with('ingredientRules')->get()->keyBy('id');

        OrderItem::query()
            ->whereHas('order', fn ($q) => $q->whereNotNull('ready_at')->where('created_at', '>=', today()->subDays(self::DEMO_DAYS)))
            ->whereDoesntHave('ingredients')
            ->with(['product.ingredients', 'addons', 'order:id,created_at'])
            ->chunkById(500, function ($lines) use ($resolver, $addons) {
                foreach ($lines as $line) {
                    if (! $line->product) {
                        continue;
                    }
                    $chosen = $line->addons->map(fn ($a) => $addons[$a->addon_id] ?? null)->filter();
                    foreach ($resolver->itemsFor($line->product, $chosen) as $itemId) {
                        $line->ingredients()->create(['inventory_item_id' => $itemId, 'created_at' => $line->order->created_at]);
                    }
                }
            });
    }

    /** Closed demo shifts of the last week get a stock check where the shelf matched the records. */
    private function demoStockChecks(): void
    {
        $service = app(CashShiftService::class);

        CashShift::where('status', 'closed')->where('opened_at', '>=', today()->subDays(self::DEMO_DAYS))
            ->whereDoesntHave('stockChecks')->orderBy('opened_at')->get()
            ->each(function (CashShift $shift) use ($service) {
                $at = $shift->closed_at->copy()->subMinutes(3);
                foreach ($service->itemsToCheck($shift) as $item) {
                    // What the ledger said was in storage at that moment.
                    $balance = (int) (StockMovement::where('inventory_item_id', $item->id)->where('created_at', '<=', $at)
                        ->latest('created_at')->latest('id')->value('balance_after') ?? $item->quantity_on_hand);
                    ShiftStockCheck::create([
                        'cash_shift_id' => $shift->id,
                        'inventory_item_id' => $item->id,
                        'expected_sealed' => $balance,
                        'counted_sealed' => $balance,
                        'stocked_out' => 0,
                        'wasted' => 0,
                        'open_unit_finished' => false,
                        'checked_by' => $shift->closed_by ?? $shift->opened_by,
                        'checked_at' => $at,
                    ]);
                }
            });
    }
}
