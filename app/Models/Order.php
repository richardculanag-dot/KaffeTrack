<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Orders are logged explicitly by OrderService (placed, ready, completed, cancelled, refunded)
 * rather than by the generic Auditable trait, so the audit trail reads like the café workflow.
 */
class Order extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_READY = 'ready';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        'pending' => 'Pending',
        'ready' => 'Ready',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded',
    ];

    public const TYPES = ['dine_in' => 'Dine-in', 'takeout' => 'Takeout'];

    protected $fillable = [
        'order_number', 'queue_number', 'user_id', 'cash_shift_id', 'order_type', 'customer_name', 'table_number',
        'status', 'payment_status', 'subtotal', 'discount_id', 'discount_name', 'discount_id_number',
        'discount_amount', 'vatable_sales', 'vat_amount', 'vat_exempt_sales', 'total_amount', 'notes',
        'ready_at', 'completed_at', 'cancelled_at', 'cancel_reason', 'created_at', 'updated_at'
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'vatable_sales' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'vat_exempt_sales' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'queue_number' => 'integer',
            'ready_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id')->withTrashed();
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class)->latestOfMany();
    }

    /** Orders that count as revenue: paid (completed or still being prepared) and not refunded. */
    public function scopeRevenue(Builder $q): Builder
    {
        return $q->where('payment_status', 'paid');
    }

    public function scopeActiveQueue(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_PENDING, self::STATUS_READY]);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function canMarkReady(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canComplete(): bool
    {
        return $this->status === self::STATUS_READY && $this->isPaid();
    }

    public function canCancel(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_READY]) && ! $this->isPaid();
    }

    public function canRefund(): bool
    {
        return $this->isPaid() && $this->status !== self::STATUS_REFUNDED;
    }

    public function itemCount(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function auditLabel(): string
    {
        return $this->order_number;
    }
}
