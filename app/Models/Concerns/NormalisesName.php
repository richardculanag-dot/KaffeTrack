<?php

namespace App\Models\Concerns;

use App\Rules\DisplayName;

/**
 * Names are stored trimmed with single spaces ("  Café   Latte " → "Café Latte"), whichever screen
 * or seeder saves them, so duplicates and search behave predictably.
 */
trait NormalisesName
{
    public function setNameAttribute($value): void
    {
        $this->attributes['name'] = $value === null ? null : DisplayName::clean($value);
    }
}
