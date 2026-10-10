<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Immutable audit trail entry.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const MODULES = [
        'auth' => 'Authentication',
        'users' => 'Users',
        'products' => 'Products',
        'inventory' => 'Inventory',
        'sales' => 'Sales / POS',
        'finance' => 'Finance',
        'reports' => 'Reports',
        'settings' => 'Settings',
        'system' => 'System',
    ];

    protected $fillable = [
        'user_id', 'user_name', 'module', 'event', 'auditable_type', 'auditable_id', 'description',
        'old_values', 'new_values', 'ip_address', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /** Colour family for the event badge. */
    public function tone(): string
    {
        return match ($this->event) {
            'created', 'stock_in', 'login', 'restored', 'shift_opened', 'order_completed', 'payment_received' => 'green',
            'updated', 'order_ready', 'adjustment', 'settings_updated', 'shift_stock_check' => 'blue',
            'archived', 'cancelled', 'logout', 'shift_closed', 'stock_out', 'depleted' => 'amber',
            'login_failed', 'refund', 'waste', 'expired', 'discarded', 'deleted' => 'red',
            default => 'gray',
        };
    }
}
