<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\NormalisesName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use NormalisesName, Auditable, SoftDeletes;

    protected string $auditModule = 'inventory';

    // quantity_on_hand is deliberately NOT fillable: it only changes through InventoryService.
    protected $fillable = [
        'item_category_id', 'default_supplier_id', 'name', 'sku', 'unit',
        'reorder_level', 'track_expiry', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'integer',
            'reorder_level' => 'integer',
            'track_expiry' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id')->withTrashed();
    }

    public function defaultSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'default_supplier_id')->withTrashed();
    }

    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    public function availableBatches(): HasMany
    {
        // FEFO: First-Expired, First-Out (batches without expiry go last, then oldest first)
        return $this->hasMany(StockBatch::class)
            ->where('quantity_remaining', '>', 0)
            ->orderByRaw('expiry_date ASC NULLS LAST')
            ->orderBy('id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(ItemUsage::class);
    }

    public function currentUsage(): HasOne
    {
        return $this->hasOne(ItemUsage::class)->where('status', ItemUsage::STATUS_IN_USE);
    }

    /** Products that use this item (Sales ↔ Inventory link). */
    public function productLinks(): HasMany
    {
        return $this->hasMany(ProductIngredient::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeLowStock(Builder $q): Builder
    {
        return $q->whereColumn('quantity_on_hand', '<=', 'reorder_level');
    }

    public function isLowStock(): bool
    {
        return $this->quantity_on_hand <= $this->reorder_level;
    }

    /** ok | low | out — drives the colour-coded badges in the UI. */
    public function stockStatus(): string
    {
        return match (true) {
            $this->quantity_on_hand <= 0 => 'out',
            $this->isLowStock() => 'low',
            default => 'ok',
        };
    }
}
