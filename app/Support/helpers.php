<?php

/*
 * Global view/format helpers. Registered through composer.json → autoload.files.
 */

if (! function_exists('peso')) {
    /** Format an amount as Philippine pesos, e.g. 1234.5 → "₱1,234.50". */
    function peso(float|int|string|null $amount, bool $withSymbol = true): string
    {
        $value = number_format((float) $amount, 2);

        return $withSymbol ? '₱'.$value : $value;
    }
}

if (! function_exists('pct_change')) {
    /** Percentage change between two values; null when there is no baseline. */
    function pct_change(float|int $current, float|int $previous): ?float
    {
        if ((float) $previous == 0.0) {
            return null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }
}
