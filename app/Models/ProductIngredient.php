<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This product uses this inventory item" — no quantities, because HEIM has no
 * standardised recipes. The role (milk, espresso…) lets an add-on replace the item.
 */
class ProductIngredient extends Model
{
    /** Suggested role names; any short label works. */
    public const ROLES = ['espresso', 'milk', 'base', 'sweetener', 'sauce', 'topping', 'other'];

    protected $fillable = ['product_id', 'inventory_item_id', 'role', 'servings'];

    protected function casts(): array
    {
        return ['servings' => 'float'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id')->withTrashed();
    }
}
