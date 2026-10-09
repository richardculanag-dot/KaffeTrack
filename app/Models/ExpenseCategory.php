<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpenseCategory extends Model
{
    use Auditable, SoftDeletes;

    public const INVENTORY_PURCHASES = 'Inventory Purchases';

    protected string $auditModule = 'finance';

    protected $fillable = ['name', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public static function inventoryPurchases(): self
    {
        return static::firstOrCreate(
            ['name' => self::INVENTORY_PURCHASES],
            ['description' => 'Automatically recorded from supplier stock-ins', 'is_system' => true],
        );
    }
}
