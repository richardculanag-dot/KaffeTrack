<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Works out which inventory items ONE drink uses, from the product's ingredient links and the
 * add-ons the customer chose. No quantities (HEIM has no standard recipes) — only "servings":
 * normally 1 per item per drink, more if an add-on adds to it (e.g. an extra shot).
 *
 *   Café Latte                     → Fresh Milk 1, Beans 1
 *   Café Latte + Oat milk          → Oat Milk 1, Beans 1         (replace the "milk" role)
 *   Americano + Extra espresso shot → Beans 2                    (add)
 */
class RecipeResolver
{
    /**
     * @param  Collection<int, \App\Models\Addon>  $addons  with ingredientRules loaded
     * @return array<int, float>  inventory_item_id => servings for one drink
     */
    public function forOneDrink(Product $product, Collection $addons): array
    {
        // role => [item id, servings]
        $roles = [];
        foreach ($product->ingredients as $link) {
            $roles[$link->role] = ['item' => $link->inventory_item_id, 'servings' => (float) $link->servings];
        }

        $extras = [];
        foreach ($addons as $addon) {
            foreach ($addon->ingredientRules as $rule) {
                if ($rule->action === 'replace') {
                    // Swap the item but keep the role's servings. If the product has no such role
                    // (oat milk on an Americano), the add-on simply adds that item.
                    $roles[$rule->role] = [
                        'item' => $rule->inventory_item_id,
                        'servings' => $roles[$rule->role]['servings'] ?? (float) $rule->servings,
                    ];
                } else {
                    $extras[] = ['item' => $rule->inventory_item_id, 'servings' => (float) $rule->servings];
                }
            }
        }

        $totals = [];
        foreach ([...array_values($roles), ...$extras] as $line) {
            $totals[$line['item']] = ($totals[$line['item']] ?? 0) + $line['servings'];
        }

        return $totals;
    }
}
