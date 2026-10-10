<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A name people read on screens and receipts (products, sizes…).
 *
 * Numbers ARE allowed — real menu names use them ("7-Up Float", "3-in-1 Coffee", "22 oz") — but a name
 * must contain at least one letter and may only use common punctuation. This rejects values like
 * "-213123123", "123" or "<test>" that mean nothing to a cashier.
 */
class DisplayName implements ValidationRule
{
    /** Letters (any language, incl. ñ/é), digits, spaces and - & ' ’ . , ( ) / + % # ₱ ! */
    private const ALLOWED = "/^[\\p{L}\\p{N}][\\p{L}\\p{N}\\s\\-&'’.,()\\/+%#₱!]*$/u";

    public function __construct(private ?string $example = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = trim((string) $value);
        if ($value === '') {
            return; // "required" handles empty values
        }

        if (! preg_match('/\p{L}/u', $value)) {
            $fail('The :attribute must include letters'.($this->example ? ", e.g. {$this->example}" : '').'.');

            return;
        }

        if (! preg_match(self::ALLOWED, $value)) {
            $fail('The :attribute must start with a letter or number and use only letters, numbers, spaces and - & \' . , ( ) / + % # ₱ !');
        }
    }

    /** Trim and collapse repeated spaces before saving ("Café   Latte " → "Café Latte"). */
    public static function clean(?string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim((string) $value));
    }
}
