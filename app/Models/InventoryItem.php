<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use Auditable, SoftDeletes;

    protected string $auditModule = 'inventory';

    // quantity_on_hand is deliberately NOT fillable: it only changes through InventoryService.
    protected $fillable = [
        'item_category_id', 'default_supplier_id', 'name', 'sku', 'unit',
        'reorder_level', 'track_expiry', 'notes', 'is_active',
        'tracks_servings', 'expected_servings', 'servings_alert_percent',
    ];

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'integer',
            'reorder_level' => 'integer',
            'track_expiry' => 'boolean',
            'is_active' => 'boolean',
            'tracks_servings' => 'boolean',
            'expected_servings' => 'integer',
            'servings_alert_percent' => 'integer',
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

    /** Products that use this item. */
    public function productLinks(): HasMany
    {
        return $this->hasMany(ProductIngredient::class);
    }

    /** Alert % for this item, falling back to the system default (Admin → Settings). */
    public function alertPercent(): int
    {
        return (int) ($this->servings_alert_percent ?: Setting::get('servings_alert_percent', 80));
    }

    /**
     * How many drinks one unit of this item usually serves: the average of the last few units that
     * were used up (excluding discarded units and resolved exceptions), or the manager's starting
     * guess until at least ServingTracker::MIN_HISTORY units exist. Null = no basis for alerts yet.
     *
     * @return array{value: ?float, source: string, sample: int}
     */
    public function usualServings(): array
    {
        $history = $this->usages()
            ->where('status', ItemUsage::STATUS_DEPLETED)
            ->where('exclude_from_history', false)
            ->where(fn ($q) => $q->whereNull('flagged_at')->orWhereNotNull('resolved_at')) // unreviewed exceptions don't count
            ->where('servings_used', '>', 0)
            ->latest('closed_at')
            ->limit(\App\Services\ServingTracker::HISTORY_SIZE)
            ->pluck('servings_used');

        if ($history->count() >= \App\Services\ServingTracker::MIN_HISTORY) {
            return ['value' => round((float) $history->avg(), 1), 'source' => 'history', 'sample' => $history->count()];
        }

        return ['value' => $this->expected_servings ? (float) $this->expected_servings : null, 'source' => 'manual', 'sample' => $history->count()];
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
