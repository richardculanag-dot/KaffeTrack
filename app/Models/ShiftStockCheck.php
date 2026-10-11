<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the end-of-shift stock check: for an item the shift's sales used, how many sealed units
 * the system had in storage, how many staff counted on the shelf, and what the difference was recorded as.
 */
class ShiftStockCheck extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'cash_shift_id', 'inventory_item_id', 'expected_sealed', 'counted_sealed', 'stocked_out', 'wasted',
        'open_unit_finished', 'note', 'checked_by', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_sealed' => 'integer',
            'counted_sealed' => 'integer',
            'stocked_out' => 'integer',
            'wasted' => 'integer',
            'open_unit_finished' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id')->withTrashed();
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id')->withTrashed();
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by')->withTrashed();
    }

    /** Counted minus expected: negative = fewer on the shelf than recorded, positive = more. */
    public function difference(): int
    {
        return $this->counted_sealed - $this->expected_sealed;
    }

    /** Worth a manager's look: the shelf differed from the records, or staff left a note. */
    public function needsAttention(): bool
    {
        return $this->outcome() !== 'Matched' || filled($this->note);
    }

    /** Short description for lists and reports, e.g. "2 stocked out · 1 wasted" or "Matched". */
    public function outcome(): string
    {
        $parts = [];
        if ($this->stocked_out > 0) {
            $parts[] = $this->stocked_out.' stocked out';
        }
        if ($this->wasted > 0) {
            $parts[] = $this->wasted.' wasted';
        }
        if ($this->difference() > 0) {
            $parts[] = $this->difference().' more than recorded';
        }

        return $parts ? implode(' · ', $parts) : 'Matched';
    }
}
