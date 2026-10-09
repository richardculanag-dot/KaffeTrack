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
        'inventory_item_id', 'stock_batch_id', 'status', 'opened_by', 'opened_at', 'closed_by', 'closed_at', 'notes', 'created_at', 'updated_at'
    ];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime'];
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
