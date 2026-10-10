<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which inventory items a sold line used, resolved once when the order is placed (like product names
 * and prices, so later recipe edits never rewrite history). The end-of-shift stock check lists the
 * items used by the shift's made orders.
 */
class OrderItemIngredient extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_item_id', 'inventory_item_id', 'created_at'];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id')->withTrashed();
    }
}
