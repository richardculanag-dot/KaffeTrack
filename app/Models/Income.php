<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Income extends Model
{
    use Auditable, SoftDeletes;

    protected string $auditModule = 'finance';

    protected $fillable = ['recorded_by', 'source', 'amount', 'income_date', 'payment_method', 'reference_number', 'notes'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'income_date' => 'date'];
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }

    public function auditLabel(): string
    {
        return $this->source;
    }
}
