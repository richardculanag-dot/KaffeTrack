<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const METHODS = ['cash' => 'Cash', 'card' => 'Card', 'online' => 'Online'];

    public const PROVIDERS = [
        'card' => ['Visa', 'Mastercard', 'JCB', 'Amex', 'Other card'],
        'online' => ['GCash', 'Maya', 'QR Ph', 'Bank transfer', 'Other e-wallet'],
    ];

    protected $fillable = [
        'order_id', 'received_by', 'cash_shift_id', 'method', 'provider', 'reference_number',
        'amount', 'amount_tendered', 'change_amount', 'created_at', 'updated_at'
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'amount_tendered' => 'decimal:2', 'change_amount' => 'decimal:2'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by')->withTrashed();
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }
}
