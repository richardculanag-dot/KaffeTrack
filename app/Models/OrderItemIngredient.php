<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which inventory items a sold line used (resolved when the order is placed) and which open unit
 * it was counted against (set once, when the drink is marked Ready).
 */
class OrderItemIngredient extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_item_id', 'inventory_item_id', 'servings', 'item_usage_id', 'counted_at', 'created_at'];

    protected function casts(): array
    {
        return ['servings' => 'float', 'counted_at' => 'datetime'];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id')->withTrashed();
    }

    public function usage(): BelongsTo
    {
        return $this->belongsTo(ItemUsage::class, 'item_usage_id');
    }
}
