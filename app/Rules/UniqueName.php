<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Unique among ACTIVE (not archived) records, ignoring letter case and surrounding/repeated spaces —
 * "Café Latte", "café latte" and " Café  Latte " count as the same name. (Laravel's Rule::unique is
 * case-sensitive on PostgreSQL, which let near-duplicates through or hit a database error.)
 */
class UniqueName implements ValidationRule
{
    public function __construct(
        private string $table,
        private string $column = 'name',
        private ?int $ignoreId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalised = mb_strtolower(DisplayName::clean((string) $value));
        if ($normalised === '') {
            return;
        }

        $exists = DB::table($this->table)
            ->whereRaw("lower(regexp_replace(trim({$this->column}), '\\s+', ' ', 'g')) = ?", [$normalised])
            ->whereNull('deleted_at')
            ->when($this->ignoreId, fn ($q) => $q->where('id', '!=', $this->ignoreId))
            ->exists();

        if ($exists) {
            $fail('The :attribute is already used by another record.');
        }
    }
}
