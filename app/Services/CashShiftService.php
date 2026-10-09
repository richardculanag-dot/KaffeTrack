<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cash drawer lifecycle: open shift → (petty cash in/out) → end-of-day count → close.
 */
class CashShiftService
{
    public const DENOMINATIONS = [1000, 500, 200, 100, 50, 20, 10, 5, 1];

    public function open(float $openingCash, ?string $notes = null, ?User $by = null, ?Carbon $at = null): CashShift
    {
        $by ??= Auth::user();
        $at ??= now();

        if (CashShift::current()) {
            throw new BusinessRuleException('A shift is already open. Close it before starting a new one.');
        }
        if ($openingCash < 0) {
            throw new BusinessRuleException('Opening cash cannot be negative.');
        }

        $shift = CashShift::create([
            'opened_by' => $by->id,
            'opened_at' => $at,
            'opening_cash' => round($openingCash, 2),
            'status' => 'open',
            'notes' => $notes,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        AuditLogger::log('sales', 'shift_opened', 'Opened cash drawer with '.peso($openingCash).' starting cash', $shift,
            null, ['opening_cash' => round($openingCash, 2)], $by);

        return $shift;
    }

    public function addMovement(CashShift $shift, string $type, float $amount, string $reason, ?User $by = null): CashMovement
    {
        $by ??= Auth::user();

        if ($shift->status !== 'open') {
            throw new BusinessRuleException('This shift is already closed.');
        }
        if ($amount <= 0) {
            throw new BusinessRuleException('Amount must be greater than zero.');
        }
        if ($type === 'cash_out' && $amount > $shift->computeExpectedCash()) {
            throw new BusinessRuleException('Cash out is more than the cash expected in the drawer ('.peso($shift->computeExpectedCash()).').');
        }

        $movement = $shift->cashMovements()->create([
            'user_id' => $by->id, 'type' => $type, 'amount' => round($amount, 2), 'reason' => $reason,
        ]);

        AuditLogger::log('sales', $type, ($type === 'cash_in' ? 'Cash in: ' : 'Cash out: ').peso($amount)." — {$reason}", $shift,
            null, ['type' => $type, 'amount' => round($amount, 2), 'reason' => $reason], $by);

        return $movement;
    }

    /**
     * End-of-day count. $denominations = [1000 => 2, 500 => 3, …]; counted cash is derived from it
     * so the cashier never has to add up the drawer by hand.
     */
    public function close(CashShift $shift, array $denominations, ?string $notes = null, ?User $by = null, ?Carbon $at = null): CashShift
    {
        $by ??= Auth::user();
        $at ??= now();

        if ($shift->status !== 'open') {
            throw new BusinessRuleException('This shift is already closed.');
        }

        $pending = Order::where('cash_shift_id', $shift->id)->activeQueue()->count();
        if ($pending > 0) {
            throw new BusinessRuleException("There are still {$pending} pending/ready order(s). Complete or cancel them before closing the shift.");
        }

        $clean = [];
        $counted = 0.0;
        foreach (self::DENOMINATIONS as $d) {
            $n = max(0, (int) ($denominations[$d] ?? $denominations[(string) $d] ?? 0));
            if ($n > 0) {
                $clean[(string) $d] = $n;
                $counted += $d * $n;
            }
        }

        return DB::transaction(function () use ($shift, $clean, $counted, $notes, $by, $at) {
            $expected = $shift->computeExpectedCash();
            $variance = round($counted - $expected, 2);

            $shift->forceFill([
                'closed_by' => $by->id,
                'closed_at' => $at,
                'expected_cash' => $expected,
                'counted_cash' => round($counted, 2),
                'variance' => $variance,
                'denominations' => $clean,
                'status' => 'closed',
                'notes' => trim(($shift->notes ? $shift->notes."\n" : '').($notes ?? '')) ?: null,
                'updated_at' => $at,
            ])->save();

            $label = $variance == 0 ? 'balanced' : ($variance > 0 ? 'over by '.peso($variance) : 'short by '.peso(abs($variance)));
            AuditLogger::log('sales', 'shift_closed',
                'Closed cash drawer — expected '.peso($expected).', counted '.peso($counted)." ({$label})",
                $shift, null, ['expected' => $expected, 'counted' => round($counted, 2), 'variance' => $variance], $by);

            return $shift;
        });
    }
}
