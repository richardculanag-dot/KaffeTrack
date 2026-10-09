<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only inventory ledger. Never updated or deleted.
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = [
        'stock_in' => 'Stock In',
        'stock_out' => 'Stock Out',
        'adjustment_in' => 'Adjustment (+)',
        'adjustment_out' => 'Adjustment (−)',
        'waste' => 'Waste / Damaged',
        'expired' => 'Expired',
    ];

    protected $fillable = [
        'inventory_item_id', 'stock_batch_id', 'user_id', 'type', 'quantity', 'balance_after',
        'unit_cost', 'reference_type', 'reference_id', 'reason', 'created_at',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'balance_after' => 'integer', 'unit_cost' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id')->withTrashed();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
