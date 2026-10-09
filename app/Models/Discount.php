<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Discount extends Model
{
    use Auditable, SoftDeletes;

    protected string $auditModule = 'products';

    protected $fillable = ['name', 'type', 'value', 'is_vat_exempt', 'requires_id', 'is_active'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'is_vat_exempt' => 'boolean',
            'requires_id' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function label(): string
    {
        return $this->type === 'percentage'
            ? rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.').'%'
            : peso($this->value);
    }
}
