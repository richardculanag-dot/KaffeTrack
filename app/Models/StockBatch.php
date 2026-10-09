<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockBatch extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'stock_in_id', 'inventory_item_id', 'batch_code', 'quantity_received', 'quantity_remaining', 'unit_cost', 'expiry_date', 'created_at', 'updated_at'
    ];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'integer',
            'quantity_remaining' => 'integer',
            'unit_cost' => 'decimal:2',
            'expiry_date' => 'date',
        ];
    }

    public function stockIn(): BelongsTo
    {
        return $this->belongsTo(StockIn::class)->withTrashed();
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id')->withTrashed();
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->lt(today());
    }

    public function expiresWithin(int $days): bool
    {
        return $this->expiry_date !== null && ! $this->isExpired() && $this->expiry_date->lte(today()->addDays($days));
    }
}
