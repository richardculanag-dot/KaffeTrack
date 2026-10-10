<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItemIngredient;
use App\Models\ShiftStockCheck;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cash drawer lifecycle: open shift → (petty cash in/out) → end-of-shift stock check → cash count → close.
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

        try {
            $shift = CashShift::create([
                'opened_by' => $by->id,
                'opened_at' => $at,
                'opening_cash' => round($openingCash, 2),
                'status' => 'open',
                'notes' => $notes,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Two people pressed "Open drawer" at the same moment: the one-open-shift index caught it.
            if (str_contains($e->getMessage(), 'cash_shifts_one_open')) {
                throw new BusinessRuleException('Someone just opened the drawer. Refresh the page.');
            }
            throw $e;
        }

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
     * Sales ↔ Inventory: the items this shift's sales used that have not been checked yet.
     * Only drinks that were actually made count (marked Ready at some point); an order cancelled
     * while still pending never used anything.
     *
     * @return Collection<int, InventoryItem>  with currentUsage loaded
     */
    public function itemsToCheck(CashShift $shift): Collection
    {
        $used = OrderItemIngredient::query()
            ->join('order_items', 'order_items.id', '=', 'order_item_ingredients.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.cash_shift_id', $shift->id)
            ->whereNotNull('orders.ready_at')
            ->distinct()
            ->pluck('order_item_ingredients.inventory_item_id');

        $checked = ShiftStockCheck::where('cash_shift_id', $shift->id)->pluck('inventory_item_id');

        return InventoryItem::with('currentUsage')
            ->whereIn('id', $used->diff($checked))
            ->orderBy('name')
            ->get();
    }

    /**
     * Record the end-of-shift stock check, all lines at once (all or nothing).
     *
     * @param  array<int, array{counted:int, wasted?:int, open_finished?:?bool, note?:?string}>  $lines  keyed by inventory item id
     * @return Collection<int, ShiftStockCheck>
     */
    public function recordStockCheck(CashShift $shift, array $lines, ?User $by = null, ?Carbon $at = null): Collection
    {
        $by ??= Auth::user();
        $at ??= now();

        if ($shift->status !== 'open') {
            throw new BusinessRuleException('This shift is already closed.');
        }

        return DB::transaction(function () use ($shift, $lines, $by, $at) {
            $inventory = app(InventoryService::class);
            $results = collect();
            foreach ($this->itemsToCheck($shift) as $item) {
                $line = $lines[$item->id] ?? null;
                if ($line === null || ! isset($line['counted'])) {
                    throw new BusinessRuleException("Count {$item->name} before saving the stock check.");
                }
                $results->push($inventory->recordShiftCheck($shift, $item, (int) $line['counted'], (int) ($line['wasted'] ?? 0),
                    $line['open_finished'] ?? null, $line['note'] ?? null, $by, $at));
            }

            return $results;
        });
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

        $unchecked = $this->itemsToCheck($shift);
        if ($unchecked->isNotEmpty()) {
            throw new BusinessRuleException('Finish the end-of-shift stock check first ('.$unchecked->pluck('name')->join(', ').').');
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
