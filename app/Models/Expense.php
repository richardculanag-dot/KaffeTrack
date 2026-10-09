<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use Auditable, SoftDeletes;

    public const PAYMENT_METHODS = [
        'cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'card' => 'Card', 'online' => 'Online / e-wallet', 'check' => 'Check',
    ];

    protected string $auditModule = 'finance';

    protected $fillable = [
        'expense_category_id', 'supplier_id', 'stock_in_id', 'recorded_by', 'description', 'amount',
        'expense_date', 'payment_method', 'reference_number', 'notes', 'created_at', 'updated_at'
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'expense_date' => 'date'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id')->withTrashed();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function stockIn(): BelongsTo
    {
        return $this->belongsTo(StockIn::class)->withTrashed();
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }

    public function auditLabel(): string
    {
        return $this->description;
    }
}
