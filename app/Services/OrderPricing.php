<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\Setting;

/**
 * Computes order totals following Philippine retail rules.
 *
 * Menu prices are VAT-inclusive.
 *  • Regular sale (VAT-registered):  vatable = total ÷ 1.12,  VAT = total − vatable
 *  • Senior Citizen / PWD discount (is_vat_exempt = true):
 *        1. remove VAT:      base = subtotal ÷ 1.12        (shown as "Less: VAT")
 *        2. apply discount:  discount = base × 20%
 *        3. total = base − discount;  the sale is recorded as VAT-exempt
 *  • Other discounts (promo, employee): taken off the VAT-inclusive subtotal.
 *  • Non-VAT-registered store: no VAT lines at all.
 *
 * Simplification (documented): a Senior/PWD discount applies to the whole order, so the
 * cashier should ring up the senior's items as a separate order when sharing a table.
 */
class OrderPricing
{
    /**
     * @param  float  $subtotal  Σ line totals (VAT-inclusive)
     * @return array{subtotal: float, less_vat: float, discount_amount: float, vatable_sales: float, vat_amount: float, vat_exempt_sales: float, total_amount: float}
     */
    public static function compute(float $subtotal, ?Discount $discount = null, ?bool $vatRegistered = null, ?float $vatRate = null): array
    {
        $vatRegistered ??= Setting::bool('vat_registered');
        $vatRate ??= (float) Setting::get('vat_rate', 12);
        $divisor = 1 + ($vatRate / 100);

        $subtotal = round($subtotal, 2);
        $lessVat = 0.0;
        $discountAmount = 0.0;
        $vatable = 0.0;
        $vat = 0.0;
        $vatExempt = 0.0;

        if ($discount && $discount->is_vat_exempt) {
            $base = $vatRegistered ? round($subtotal / $divisor, 2) : $subtotal;
            $lessVat = round($subtotal - $base, 2);
            $discountAmount = self::discountOn($base, $discount);
            $total = round($base - $discountAmount, 2);
            $vatExempt = $vatRegistered ? $base : 0.0;
        } else {
            $discountAmount = $discount ? self::discountOn($subtotal, $discount) : 0.0;
            $total = round($subtotal - $discountAmount, 2);
            if ($vatRegistered) {
                $vatable = round($total / $divisor, 2);
                $vat = round($total - $vatable, 2);
            }
        }

        return [
            'subtotal' => $subtotal,
            'less_vat' => $lessVat,
            'discount_amount' => $discountAmount,
            'vatable_sales' => $vatable,
            'vat_amount' => $vat,
            'vat_exempt_sales' => $vatExempt,
            'total_amount' => max(0, $total),
        ];
    }

    private static function discountOn(float $amount, Discount $discount): float
    {
        $value = (float) $discount->value;
        $off = $discount->type === 'percentage' ? $amount * ($value / 100) : $value;

        return round(min($off, $amount), 2);
    }
}
