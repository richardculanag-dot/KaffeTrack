<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Works out WHICH inventory items one drink uses, from the product's ingredient links and the add-ons
 * the customer chose. No amounts — HEIM has no standard recipes, so only the items are recorded:
 *
 *   Café Latte                       → Beans, Fresh Milk
 *   Café Latte + Oat milk            → Beans, Oat Milk       (Oat milk replaces the "milk" role)
 *   Americano + Caramel syrup        → Beans, Caramel Syrup  (the add-on adds an item)
 */
class RecipeResolver
{
    /**
     * @param  Collection<int, \App\Models\Addon>  $addons  with ingredientRules loaded
     * @return array<int, int>  inventory item ids
     */
    public function itemsFor(Product $product, Collection $addons): array
    {
        // role => item id
        $roles = $product->ingredients->pluck('inventory_item_id', 'role')->all();
        $extras = [];

        foreach ($addons as $addon) {
            foreach ($addon->ingredientRules as $rule) {
                if ($rule->action === 'replace') {
                    // Swap the item in that role. If the product has no such role (oat milk on an
                    // Americano), the add-on simply adds the item.
                    $roles[$rule->role] = $rule->inventory_item_id;
                } else {
                    $extras[] = $rule->inventory_item_id;
                }
            }
        }

        return array_values(array_unique([...array_values($roles), ...$extras]));
    }
}
