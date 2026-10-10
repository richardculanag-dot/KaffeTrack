<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How an add-on changes what a drink uses:
 *   replace — swap the item in a role (Oat milk replaces the "milk" role). If the product has no such
 *             role (oat milk on an Americano), the item is added instead.
 *   add     — the drink also uses this item (Extra espresso shot → also uses the beans).
 */
class AddonIngredientRule extends Model
{
    public const ACTIONS = ['replace' => 'Replaces', 'add' => 'Adds'];

    protected $fillable = ['addon_id', 'action', 'role', 'inventory_item_id'];

    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class)->withTrashed();
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id')->withTrashed();
    }
}
