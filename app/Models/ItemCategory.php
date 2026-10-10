<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\NormalisesName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ItemCategory extends Model
{
    use NormalisesName, Auditable, SoftDeletes;

    protected string $auditModule = 'inventory';

    protected $fillable = ['name', 'description'];

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }
}
