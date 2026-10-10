<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One physical unit taken out of storage and opened at the bar.
 */
class ItemUsage extends Model
{
    use SoftDeletes;

    public const STATUS_IN_USE = 'in_use';
    public const STATUS_DEPLETED = 'depleted';
    public const STATUS_DISCARDED = 'discarded';

    protected $fillable = [
        'inventory_item_id', 'stock_batch_id', 'status', 'opened_by', 'opened_at', 'closed_by', 'closed_at', 'notes', 'created_at', 'updated_at',
        'servings_used', 'expected_servings', 'alert_percent', 'alerted_at', 'reached_at', 'flagged_at',
        'acknowledged_by', 'acknowledged_at', 'resolved_by', 'resolved_at', 'resolution_note', 'exclude_from_history',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'servings_used' => 'float',
            'expected_servings' => 'float',
            'alert_percent' => 'integer',
            'alerted_at' => 'datetime',
            'reached_at' => 'datetime',
            'flagged_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'exclude_from_history' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id')->withTrashed();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id')->withTrashed();
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by')->withTrashed();
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by')->withTrashed();
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by')->withTrashed();
    }

    /** Drinks counted against this unit. */
    public function servedLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderItemIngredient::class);
    }

    /** % of the expected servings already used (null when there is no expectation yet). */
    public function percentUsed(): ?int
    {
        return $this->expected_servings > 0 ? (int) floor($this->servings_used / $this->expected_servings * 100) : null;
    }

    /** none | alert | reached | flagged — how far past the expectation this unit is. */
    public function servingStage(): string
    {
        return match (true) {
            $this->flagged_at !== null => 'flagged',
            $this->reached_at !== null => 'reached',
            $this->alerted_at !== null => 'alert',
            default => 'none',
        };
    }

    /** Whole drinks, but keep a decimal when a size or add-on counted as a fraction. */
    public static function servingsLabel(float $value): string
    {
        return fmod($value, 1.0) === 0.0 ? (string) (int) $value : number_format($value, 1);
    }

    /** How long the unit lasted (or has been open so far), e.g. "2d 4h". */
    public function durationLabel(): string
    {
        $end = $this->closed_at ?? now();
        $mins = (int) $this->opened_at->diffInMinutes($end);
        $d = intdiv($mins, 1440);
        $h = intdiv($mins % 1440, 60);
        $m = $mins % 60;

        return $d > 0 ? "{$d}d {$h}h" : ($h > 0 ? "{$h}h {$m}m" : "{$m}m");
    }
}
