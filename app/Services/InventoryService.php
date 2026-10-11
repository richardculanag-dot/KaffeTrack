<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CashShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryItem;
use App\Models\ItemUsage;
use App\Models\ShiftStockCheck;
use App\Models\StockBatch;
use App\Models\StockIn;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Every change to stock quantity goes through this service.
 *
 * Invariant maintained inside each DB transaction:
 *   inventory_items.quantity_on_hand == Σ stock_batches.quantity_remaining (for that item)
 * and every change is written to the stock_movements ledger.
 */
class InventoryService
{
    /** The unit stockOut() marked finished before opening a new one (null if none was open). */
    public ?ItemUsage $finishedPrevious = null;

    /**
     * Receive a supplier delivery.
     *
     * @param  array<int, array{inventory_item_id:int, quantity:int, unit_cost:float, expiry_date?:?string, batch_code?:?string}>  $lines
     */
    public function stockIn(
        Supplier $supplier,
        array $lines,
        string|Carbon $receivedDate,
        ?string $invoiceNumber = null,
        ?string $notes = null,
        bool $recordExpense = true,
        ?string $paymentMethod = 'cash',
        ?User $by = null,
        ?Carbon $at = null,
    ): StockIn {
        $by ??= Auth::user();
        $at ??= now();
        $receivedDate = Carbon::parse($receivedDate);

        if (empty($lines)) {
            throw new BusinessRuleException('Add at least one item to the delivery.');
        }

        return DB::transaction(function () use ($supplier, $lines, $receivedDate, $invoiceNumber, $notes, $recordExpense, $paymentMethod, $by, $at) {
            $stockIn = StockIn::create([
                'reference_no' => $this->nextStockInReference($at),
                'supplier_id' => $supplier->id,
                'received_by' => $by->id,
                'received_date' => $receivedDate->toDateString(),
                'invoice_number' => $invoiceNumber,
                'notes' => $notes,
                'total_cost' => 0,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $total = 0.0;
            $summary = [];

            foreach ($lines as $line) {
                $qty = (int) $line['quantity'];
                if ($qty <= 0) {
                    throw new BusinessRuleException('Quantities must be at least 1.');
                }
                $item = InventoryItem::lockForUpdate()->findOrFail($line['inventory_item_id']);
                if ($item->track_expiry && empty($line['expiry_date'])) {
                    throw new BusinessRuleException("Expiry date is required for {$item->name}.");
                }

                $batch = StockBatch::create([
                    'stock_in_id' => $stockIn->id,
                    'inventory_item_id' => $item->id,
                    'batch_code' => $line['batch_code'] ?? null,
                    'quantity_received' => $qty,
                    'quantity_remaining' => $qty,
                    'unit_cost' => round((float) $line['unit_cost'], 2),
                    'expiry_date' => $line['expiry_date'] ?? null,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);

                $balance = $this->changeOnHand($item, $qty);
                $this->ledger($item, $batch, $by, 'stock_in', $qty, $balance, $batch->unit_cost, $stockIn, "Received from {$supplier->name}", $at);

                $total += $qty * (float) $batch->unit_cost;
                $summary[] = "{$qty} × {$item->name}";
            }

            $stockIn->forceFill(['total_cost' => round($total, 2)])->save();

            // Requirement: supplier purchases flow into Finance automatically.
            if ($recordExpense && $total > 0) {
                Expense::withoutEvents(fn () => Expense::create([
                    'expense_category_id' => ExpenseCategory::inventoryPurchases()->id,
                    'supplier_id' => $supplier->id,
                    'stock_in_id' => $stockIn->id,
                    'recorded_by' => $by->id,
                    'description' => "Stock purchase {$stockIn->reference_no} — {$supplier->name}",
                    'amount' => round($total, 2),
                    'expense_date' => $receivedDate->toDateString(),
                    'payment_method' => $paymentMethod,
                    'reference_number' => $invoiceNumber,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]));
            }

            AuditLogger::log('inventory', 'stock_in',
                "Stock in {$stockIn->reference_no} from {$supplier->name}: ".implode(', ', $summary).' ('.peso($total).')',
                $stockIn, null, ['supplier' => $supplier->name, 'items' => $summary, 'total_cost' => round($total, 2)], $by);

            return $stockIn->load('batches.item');
        });
    }

    /**
     * Take units out of storage for use at the bar (FEFO batch selection) — usually 1, or several at
     * once when staff record later ("opened 3 cartons today").
     *
     * Only one unit of an item is open at a time, so units are opened one after another: a unit that
     * is still open is marked finished first, and every unit opened here except the last is finished
     * too. $lastFinished = true also finishes the last one (it was used up as well).
     * Check $finishedPrevious afterwards to tell the user an open unit was closed.
     */
    public function stockOut(InventoryItem $item, ?string $notes = null, ?User $by = null, ?Carbon $at = null, int $quantity = 1, bool $lastFinished = false): ItemUsage
    {
        $by ??= Auth::user();
        $at ??= now();
        $this->finishedPrevious = null;

        if ($quantity < 1) {
            throw new BusinessRuleException('Stock out at least 1 unit.');
        }

        try {
            return DB::transaction(function () use ($item, $notes, $by, $at, $quantity, $lastFinished) {
                $item = InventoryItem::lockForUpdate()->findOrFail($item->id);
                if ($item->quantity_on_hand <= 0) {
                    throw new BusinessRuleException("{$item->name} is out of stock.");
                }
                if ($quantity > $item->quantity_on_hand) {
                    throw new BusinessRuleException("Only {$item->quantity_on_hand} {$item->unit} of {$item->name} are in storage.");
                }

                $usage = null;
                for ($i = 0; $i < $quantity; $i++) {
                    if ($open = $item->currentUsage()->first()) {
                        $closed = $this->closeUsage($open, ItemUsage::STATUS_DEPLETED, 'Finished — the next unit was opened', $by, $at, audit: $i === 0);
                        $this->finishedPrevious ??= $i === 0 ? $closed : null;
                    }
                    $usage = $this->openOne($item, $by, $at, $notes, allowExpired: false);
                }
                if ($lastFinished) {
                    $this->closeUsage($usage, ItemUsage::STATUS_DEPLETED, 'Used up — recorded with the stock-out', $by, $at, audit: false);
                }

                $remaining = $item->quantity_on_hand - $quantity;
                AuditLogger::log('inventory', 'stock_out',
                    "Stocked out {$quantity} {$item->unit} of {$item->name} (remaining: {$remaining})"
                        .($quantity > 1 ? ' — '.($lastFinished ? 'all used up' : 'the last one is still open') : ($lastFinished ? ' — already used up' : '')),
                    $usage, ['quantity_on_hand' => $item->quantity_on_hand], ['quantity_on_hand' => $remaining], $by);

                return $usage;
            });
        } catch (QueryException $e) {
            // Two staff pressed "Stock out" at the same moment: the partial unique index caught it.
            if (str_contains($e->getMessage(), 'item_usages_one_open_per_item')) {
                throw new BusinessRuleException("Another staff member just opened a unit of {$item->name}.");
            }
            throw $e;
        }
    }

    /** The open unit is fully consumed. */
    public function markDepleted(ItemUsage $usage, ?string $notes = null, ?User $by = null, ?Carbon $at = null): ItemUsage
    {
        return $this->closeUsage($usage, ItemUsage::STATUS_DEPLETED, $notes, $by, $at);
    }

    /** The open unit spoiled / spilled before it was used up. */
    public function discard(ItemUsage $usage, string $reason, ?User $by = null): ItemUsage
    {
        return $this->closeUsage($usage, ItemUsage::STATUS_DISCARDED, $reason, $by);
    }

    /**
     * One line of the END-OF-SHIFT STOCK CHECK (Sales ↔ Inventory).
     *
     * Staff count the SEALED units left on the shelf for an item the shift's sales used. Nothing is
     * estimated: the difference between the system's count and the shelf is recorded as real ledger
     * movements, split the way staff say —
     *   $wasted          units thrown away (spoiled, dropped)       → "waste" movements
     *   the rest         units opened for use but not recorded yet  → stock-outs
     * Units are opened one after another (one open unit per item), so every unit opened here except
     * the last is marked finished; $openFinished says whether the unit open at the bar now is empty.
     * A count HIGHER than the system's changes nothing (usually an unrecorded delivery) and is noted.
     */
    public function recordShiftCheck(
        CashShift $shift,
        InventoryItem $item,
        int $counted,
        int $wasted = 0,
        ?bool $openFinished = null,
        ?string $note = null,
        ?User $by = null,
        ?Carbon $at = null,
    ): ShiftStockCheck {
        $by ??= Auth::user();
        $at ??= now();
        $label = "end-of-shift check (shift #{$shift->id})";

        if ($counted < 0 || $wasted < 0) {
            throw new BusinessRuleException('Counts cannot be negative.');
        }

        return DB::transaction(function () use ($shift, $item, $counted, $wasted, $openFinished, $note, $by, $at, $label) {
            $item = InventoryItem::lockForUpdate()->findOrFail($item->id);
            if (ShiftStockCheck::where('cash_shift_id', $shift->id)->where('inventory_item_id', $item->id)->exists()) {
                throw new BusinessRuleException("{$item->name} was already checked for this shift.");
            }

            $expected = (int) $item->quantity_on_hand;
            $missing = max(0, $expected - $counted);
            if ($wasted > $missing) {
                throw new BusinessRuleException("{$item->name}: only {$missing} {$item->unit} are missing from the shelf, so no more than {$missing} can be marked as thrown away.");
            }
            $opened = $missing - $wasted;
            if ($counted > $expected) {
                $note = trim('More than recorded on the shelf — check for a delivery that was not entered. '.($note ?? ''));
            }

            $check = ShiftStockCheck::create([
                'cash_shift_id' => $shift->id,
                'inventory_item_id' => $item->id,
                'expected_sealed' => $expected,
                'counted_sealed' => $counted,
                'stocked_out' => $opened,
                'wasted' => $wasted,
                'open_unit_finished' => null,
                'note' => $note ? mb_substr($note, 0, 255) : null,
                'checked_by' => $by->id,
                'checked_at' => $at,
            ]);

            // Thrown away straight from storage (never opened at the bar).
            if ($wasted > 0) {
                $left = $wasted;
                foreach ($item->availableBatches()->lockForUpdate()->get() as $batch) {
                    $take = min($left, $batch->quantity_remaining);
                    $batch->decrement('quantity_remaining', $take);
                    $balance = $this->changeOnHand($item, -$take);
                    $this->ledger($item, $batch, $by, 'waste', -$take, $balance, $batch->unit_cost, $check, "Thrown away — found at the {$label}", $at);
                    $left -= $take;
                    if ($left === 0) {
                        break;
                    }
                }
            }

            // Opened for use during the shift but not recorded: open them one after another.
            for ($i = 0; $i < $opened; $i++) {
                if ($open = $item->currentUsage()->first()) {
                    $this->closeUsage($open, ItemUsage::STATUS_DEPLETED, "Finished — recorded at the {$label}", $by, $at, audit: false);
                }
                $this->openOne($item, $by, $at, "Opened during the shift — recorded at the {$label}", allowExpired: true);
            }

            // Is the unit at the bar now empty? Only meaningful when one is open.
            $open = $item->currentUsage()->first();
            if ($open) {
                $finished = (bool) $openFinished;
                if ($finished) {
                    $this->closeUsage($open, ItemUsage::STATUS_DEPLETED, "Empty at the {$label}", $by, $at, audit: false);
                }
                $check->forceFill(['open_unit_finished' => $finished])->save();
            } elseif ($openFinished === false && $counted <= $expected) {
                // Staff say one is open at the bar, but none was ever taken out of storage in the records
                // (and the shelf count shows none missing). Nothing is guessed — the manager is told.
                $check->forceFill(['note' => mb_substr(trim('Staff reported an open unit at the bar that was never stocked out — recount or check for an unrecorded delivery. '.($check->note ?? '')), 0, 255)])->save();
            }

            AuditLogger::log('inventory', 'shift_stock_check',
                "{$item->name}: counted {$counted} sealed (system had {$expected}) — {$check->outcome()}"
                    .($check->open_unit_finished === true ? '; open unit finished' : ($check->open_unit_finished === false ? '; open unit still in use' : '')),
                $check, ['quantity_on_hand' => $expected], ['quantity_on_hand' => $expected - $opened - $wasted], $by);

            return $check;
        });
    }

    /** Take one unit from the FEFO batch, create the open unit and its ledger line. Call inside a transaction. */
    private function openOne(InventoryItem $item, User $by, Carbon $at, ?string $notes, bool $allowExpired): ItemUsage
    {
        $batches = $item->availableBatches()->lockForUpdate();
        $batch = (clone $batches)->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $at->toDateString()))->first();
        if (! $batch && $allowExpired) {
            // The shelf count says it is gone, so record it even if the batch had expired.
            $batch = $batches->first();
        }
        if (! $batch) {
            throw new BusinessRuleException("All remaining {$item->name} stock is expired. Ask a manager to write it off.");
        }

        $batch->decrement('quantity_remaining');
        $balance = $this->changeOnHand($item, -1);

        $usage = ItemUsage::create([
            'inventory_item_id' => $item->id,
            'stock_batch_id' => $batch->id,
            'status' => ItemUsage::STATUS_IN_USE,
            'opened_by' => $by->id,
            'opened_at' => $at,
            'notes' => $notes,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $this->ledger($item, $batch, $by, 'stock_out', -1, $balance, $batch->unit_cost, $usage, $notes ?: 'Opened for use', $at);

        return $usage;
    }

    private function closeUsage(ItemUsage $usage, string $status, ?string $notes, ?User $by, ?Carbon $at = null, bool $audit = true): ItemUsage
    {
        $by ??= Auth::user();
        $at ??= now();

        if ($usage->status !== ItemUsage::STATUS_IN_USE) {
            throw new BusinessRuleException('This unit has already been closed.');
        }

        $usage->forceFill([
            'status' => $status,
            'closed_by' => $by->id,
            'closed_at' => $at,
            'notes' => $notes ?: $usage->notes,
            'updated_at' => $at,
        ])->save();

        if ($audit) {
            $item = $usage->loadMissing('item')->item;
            $label = $status === ItemUsage::STATUS_DEPLETED ? 'depleted' : 'discarded';
            AuditLogger::log('inventory', $label,
                ucfirst($label)." open unit of {$item->name} after {$usage->durationLabel()}".($notes ? ": {$notes}" : ''),
                $usage, ['status' => 'in_use'], ['status' => $status], $by);
        }

        return $usage;
    }

    /**
     * Manager stock correction.
     *  adjustment_in  → adds units (new batch, cost = last known unit cost)
     *  adjustment_out / waste / expired → removes units (specific batch, or FEFO across batches)
     */
    public function adjust(InventoryItem $item, string $type, int $quantity, string $reason, ?int $batchId = null, ?User $by = null): void
    {
        $by ??= Auth::user();

        if (! in_array($type, ['adjustment_in', 'adjustment_out', 'waste', 'expired'])) {
            throw new BusinessRuleException('Unknown adjustment type.');
        }
        if ($quantity <= 0) {
            throw new BusinessRuleException('Quantity must be at least 1.');
        }

        DB::transaction(function () use ($item, $type, $quantity, $reason, $batchId, $by) {
            $item = InventoryItem::lockForUpdate()->findOrFail($item->id);
            $before = $item->quantity_on_hand;

            if ($type === 'adjustment_in') {
                $cost = (float) ($item->batches()->latest('id')->value('unit_cost') ?? 0);
                $batch = StockBatch::create([
                    'stock_in_id' => null,
                    'inventory_item_id' => $item->id,
                    'batch_code' => 'ADJ',
                    'quantity_received' => $quantity,
                    'quantity_remaining' => $quantity,
                    'unit_cost' => $cost,
                ]);
                $balance = $this->changeOnHand($item, $quantity);
                $this->ledger($item, $batch, $by, $type, $quantity, $balance, $cost, null, $reason);
            } else {
                if ($quantity > $item->quantity_on_hand) {
                    throw new BusinessRuleException("Cannot remove {$quantity} — only {$item->quantity_on_hand} {$item->unit} in storage.");
                }
                $batches = $batchId
                    ? $item->batches()->whereKey($batchId)->where('quantity_remaining', '>', 0)->lockForUpdate()->get()
                    : $item->availableBatches()->lockForUpdate()->get();

                $left = $quantity;
                foreach ($batches as $batch) {
                    if ($left === 0) {
                        break;
                    }
                    $take = min($left, $batch->quantity_remaining);
                    $batch->decrement('quantity_remaining', $take);
                    $balance = $this->changeOnHand($item, -$take);
                    $this->ledger($item, $batch, $by, $type, -$take, $balance, $batch->unit_cost, null, $reason);
                    $left -= $take;
                }
                if ($left > 0) {
                    throw new BusinessRuleException('The selected batch does not have enough units.');
                }
            }

            $item->refresh();
            AuditLogger::log('inventory', in_array($type, ['waste', 'expired']) ? $type : 'adjustment',
                StockMovement::TYPES[$type]." of {$quantity} {$item->unit} for {$item->name}: {$reason}",
                $item, ['quantity_on_hand' => $before], ['quantity_on_hand' => $item->quantity_on_hand], $by);
        });
    }

    /** Update the cached on-hand count without firing model events; returns the new balance. */
    private function changeOnHand(InventoryItem $item, int $delta): int
    {
        InventoryItem::whereKey($item->id)->update(['quantity_on_hand' => DB::raw('quantity_on_hand + '.(int) $delta)]);

        return (int) InventoryItem::whereKey($item->id)->value('quantity_on_hand');
    }

    private function ledger(InventoryItem $item, ?StockBatch $batch, User $by, string $type, int $qty, int $balance, $unitCost, $reference, ?string $reason, ?Carbon $at = null): void
    {
        StockMovement::create([
            'inventory_item_id' => $item->id,
            'stock_batch_id' => $batch?->id,
            'user_id' => $by->id,
            'type' => $type,
            'quantity' => $qty,
            'balance_after' => $balance,
            'unit_cost' => $unitCost,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'reason' => $reason,
            'created_at' => $at ?? now(),
        ]);
    }

    private function nextStockInReference(Carbon $at): string
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(?)', [7302]);
        }
        $count = StockIn::withTrashed()->whereDate('created_at', $at->toDateString())->count() + 1;

        return 'SI-'.$at->format('Ymd').'-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }
}
