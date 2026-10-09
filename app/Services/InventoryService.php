<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryItem;
use App\Models\ItemUsage;
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
     * Take ONE unit out of storage for use at the bar (FEFO batch selection).
     * Blocked while another unit of the same item is still open.
     */
    public function stockOut(InventoryItem $item, ?string $notes = null, ?User $by = null, ?Carbon $at = null): ItemUsage
    {
        $by ??= Auth::user();
        $at ??= now();

        try {
            return DB::transaction(function () use ($item, $notes, $by, $at) {
                $item = InventoryItem::lockForUpdate()->findOrFail($item->id);

                $open = $item->currentUsage()->with('opener')->first();
                if ($open) {
                    throw new BusinessRuleException(
                        "A unit of {$item->name} is still in use (opened {$open->opened_at->diffForHumans()} by {$open->opener->name}). Mark it as depleted first."
                    );
                }
                if ($item->quantity_on_hand <= 0) {
                    throw new BusinessRuleException("{$item->name} is out of stock.");
                }

                $batch = $item->availableBatches()
                    ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $at->toDateString()))
                    ->lockForUpdate()->first();
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

                AuditLogger::log('inventory', 'stock_out',
                    "Stocked out 1 {$item->unit} of {$item->name} (remaining: {$balance})",
                    $usage, ['quantity_on_hand' => $balance + 1], ['quantity_on_hand' => $balance], $by);

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

    private function closeUsage(ItemUsage $usage, string $status, ?string $notes, ?User $by, ?Carbon $at = null): ItemUsage
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

        $item = $usage->loadMissing('item')->item;
        $label = $status === ItemUsage::STATUS_DEPLETED ? 'depleted' : 'discarded';
        AuditLogger::log('inventory', $label,
            ucfirst($label)." open unit of {$item->name} after {$usage->durationLabel()}".($notes ? ": {$notes}" : ''),
            $usage, ['status' => 'in_use'], ['status' => $status], $by);

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
