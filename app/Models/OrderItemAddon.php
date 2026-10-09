<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemAddon extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_item_id', 'addon_id', 'addon_name', 'price'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
