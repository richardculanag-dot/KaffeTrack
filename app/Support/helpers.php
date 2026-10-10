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

/*
 * Guards for values that arrive in the URL (?date=…, ?category=…). They can be edited by hand,
 * so anything malformed is ignored instead of reaching the database and causing an error page.
 */

if (! function_exists('clean_date')) {
    /** "2026-10-05" → "2026-10-05"; anything else (bad format, impossible date) → null. */
    function clean_date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[1] >= 2000 && (int) $m[1] <= 2100 ? $value : null;
    }
}

if (! function_exists('clean_month')) {
    /** "2026-10" → "2026-10"; anything else → null. */
    function clean_month(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/', $value) ? $value : null;
    }
}

if (! function_exists('clean_id')) {
    /** "12" or 12 → 12; empty, text, zero, negative or absurdly large → null. */
    function clean_id(mixed $value): ?int
    {
        $string = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : '');

        return $string !== '' && ctype_digit($string) && strlen($string) <= 18 && (int) $string > 0 ? (int) $string : null;
    }
}
