<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use Auditable, SoftDeletes;

    protected string $auditModule = 'products';

    protected $fillable = ['category_id', 'name', 'code', 'description', 'image_path', 'is_available'];

    protected function casts(): array
    {
        return ['is_available' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('price');
    }

    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class);
    }

    /** Inventory items this product uses (Sales ↔ Inventory link). */
    public function ingredients(): HasMany
    {
        return $this->hasMany(ProductIngredient::class)->orderBy('id');
    }

    public function scopeAvailable(Builder $q): Builder
    {
        return $q->where('is_available', true);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? asset('uploads/'.$this->image_path) : null;
    }

    /** "₱120 – ₱160" style label for cards and tables. */
    public function priceRange(): string
    {
        $prices = $this->variants->pluck('price')->map(fn ($p) => (float) $p);
        if ($prices->isEmpty()) {
            return '—';
        }
        $min = peso($prices->min());
        $max = peso($prices->max());

        return $min === $max ? $min : "{$min} – {$max}";
    }
}
