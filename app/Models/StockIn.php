<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Logged explicitly by InventoryService (one readable entry per delivery).
 */
class StockIn extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'reference_no', 'supplier_id', 'received_by', 'received_date', 'invoice_number', 'total_cost', 'notes', 'created_at', 'updated_at'
    ];

    protected function casts(): array
    {
        return ['received_date' => 'date', 'total_cost' => 'decimal:2'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by')->withTrashed();
    }

    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    public function expense(): HasOne
    {
        return $this->hasOne(Expense::class);
    }
}
