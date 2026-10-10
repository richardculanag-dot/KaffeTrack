<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\NormalisesName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Addon extends Model
{
    use NormalisesName, Auditable, SoftDeletes;

    protected string $auditModule = 'products';

    protected $fillable = ['name', 'price', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    /** What this add-on changes in a drink's inventory use (replace a role / add an item). */
    public function ingredientRules(): HasMany
    {
        return $this->hasMany(AddonIngredientRule::class)->orderBy('id');
    }
}
