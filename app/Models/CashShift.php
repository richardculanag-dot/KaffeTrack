<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashShift extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'opened_by', 'opened_at', 'opening_cash', 'closed_by', 'closed_at', 'expected_cash',
        'counted_cash', 'variance', 'denominations', 'status', 'notes', 'created_at', 'updated_at'
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'variance' => 'decimal:2',
            'denominations' => 'array',
        ];
    }

    public static function current(): ?self
    {
        return static::where('status', 'open')->latest('opened_at')->first();
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by')->withTrashed();
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** End-of-shift stock check lines (Sales ↔ Inventory). */
    public function stockChecks(): HasMany
    {
        return $this->hasMany(ShiftStockCheck::class);
    }

    /**
     * Expected cash in drawer = opening + cash sales − cash refunds + cash in − cash out.
     * Card / online payments never touch the drawer.
     */
    public function computeExpectedCash(): float
    {
        $cashSales = (float) $this->payments()->where('method', 'cash')->sum('amount');
        $cashRefunds = (float) $this->refunds()->where('method', 'cash')->sum('amount');
        $cashIn = (float) $this->cashMovements()->where('type', 'cash_in')->sum('amount');
        $cashOut = (float) $this->cashMovements()->where('type', 'cash_out')->sum('amount');

        return round((float) $this->opening_cash + $cashSales - $cashRefunds + $cashIn - $cashOut, 2);
    }

    /** Breakdown used on the close-shift screen and the shift report. */
    public function summary(): array
    {
        $byMethod = $this->payments()->selectRaw('method, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('method')->get()->keyBy('method');

        return [
            'opening_cash' => (float) $this->opening_cash,
            'cash_sales' => (float) ($byMethod['cash']->total ?? 0),
            'card_sales' => (float) ($byMethod['card']->total ?? 0),
            'online_sales' => (float) ($byMethod['online']->total ?? 0),
            'transactions' => (int) $byMethod->sum('count'),
            'cash_refunds' => (float) $this->refunds()->where('method', 'cash')->sum('amount'),
            'other_refunds' => (float) $this->refunds()->where('method', '!=', 'cash')->sum('amount'),
            'cash_in' => (float) $this->cashMovements()->where('type', 'cash_in')->sum('amount'),
            'cash_out' => (float) $this->cashMovements()->where('type', 'cash_out')->sum('amount'),
            'expected_cash' => $this->computeExpectedCash(),
        ];
    }
}
