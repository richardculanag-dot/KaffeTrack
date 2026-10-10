<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\NormalisesName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use NormalisesName, Auditable, SoftDeletes;

    protected string $auditModule = 'inventory';

    protected $fillable = ['name', 'contact_person', 'phone', 'email', 'address', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function stockIns(): HasMany
    {
        return $this->hasMany(StockIn::class);
    }
}
