<?php

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\WithToasts;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Services\InventoryService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Receive stock')]
class StockInForm extends Component
{
    use WithToasts;

    /** How far back a delivery date may be entered (late paperwork), in days. */
    public const BACKDATE_DAYS = 30;

    /** Largest total for one delivery, in pesos (matches the expense amount limit). */
    public const MAX_TOTAL = 10000000;

    public $supplierId = '';
    public string $receivedDate = '';
    public string $invoiceNumber = '';
    public string $notes = '';
    public bool $recordExpense = true;
    public string $paymentMethod = 'cash';

    /** @var array<int, array{item_id: string|int, quantity: string|int, unit_cost: string|float, expiry_date: string, batch_code: string}> */
    public array $lines = [];

    public function mount(): void
    {
        $this->receivedDate = today()->toDateString();
        $this->addLine();
    }

    public function addLine(): void
    {
        $this->lines[] = ['item_id' => '', 'quantity' => 1, 'unit_cost' => '', 'expiry_date' => '', 'batch_code' => ''];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        if (! $this->lines) {
            $this->addLine();
        }
    }

    /** Pre-fill the last unit cost paid for the chosen item (saves typing, fewer mistakes). */
    public function updatedLines($value, $key): void
    {
        [$index, $field] = explode('.', $key);
        if ($field === 'item_id' && $value) {
            $lastCost = StockBatch::where('inventory_item_id', $value)->whereNotNull('stock_in_id')->latest('id')->value('unit_cost');
            if ($lastCost !== null && $this->lines[$index]['unit_cost'] === '') {
                $this->lines[$index]['unit_cost'] = (float) $lastCost;
            }
        }
    }

    /** Shortcut: add every low-stock item usually bought from the selected supplier. */
    public function addLowStock(): void
    {
        if (! $this->supplierId) {
            $this->toast('Choose the supplier first.', 'error');

            return;
        }
        $existing = collect($this->lines)->pluck('item_id')->filter()->map(fn ($v) => (int) $v)->all();
        $items = InventoryItem::active()->lowStock()->where('default_supplier_id', $this->supplierId)->whereNotIn('id', $existing)->get();
        if ($items->isEmpty()) {
            $this->toast('No low-stock items are linked to this supplier.', 'info');

            return;
        }
        $this->lines = array_values(array_filter($this->lines, fn ($l) => $l['item_id'] !== ''));
        foreach ($items as $item) {
            $this->lines[] = [
                'item_id' => $item->id,
                'quantity' => max(1, $item->reorder_level * 2 - $item->quantity_on_hand),
                'unit_cost' => (float) (StockBatch::where('inventory_item_id', $item->id)->whereNotNull('stock_in_id')->latest('id')->value('unit_cost') ?? 0),
                'expiry_date' => '',
                'batch_code' => '',
            ];
        }
        $this->toast("Added {$items->count()} low-stock item(s). Check quantities and expiry dates.", 'info');
    }

    public function getTotalProperty(): float
    {
        return round(collect($this->lines)->sum(fn ($l) => (float) ($l['quantity'] ?: 0) * (float) ($l['unit_cost'] ?: 0)), 2);
    }

    public function save(InventoryService $service)
    {
        $tracked = InventoryItem::whereIn('id', collect($this->lines)->pluck('item_id')->filter())->where('track_expiry', true)->pluck('id')->all();

        $rules = [
            'supplierId' => ['required', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'receivedDate' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.today()->subDays(self::BACKDATE_DAYS)->toDateString()],
            'invoiceNumber' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'paymentMethod' => ['required', Rule::in(array_keys(Expense::PAYMENT_METHODS))],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'distinct', Rule::exists('inventory_items', 'id')->whereNull('deleted_at')],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'lines.*.batch_code' => ['nullable', 'string', 'max:40'],
        ];
        foreach ($this->lines as $i => $line) {
            $rules["lines.$i.expiry_date"] = [in_array((int) $line['item_id'], $tracked) ? 'required' : 'nullable', 'date', 'after_or_equal:today', 'before:'.today()->addYears(10)->toDateString()];
        }

        $this->validate($rules, [
            'lines.*.item_id.required' => 'Choose an item.',
            'lines.*.item_id.distinct' => 'This item is already on another line.',
            'lines.*.expiry_date.required' => 'Required for this item.',
            'lines.*.expiry_date.after_or_equal' => 'This batch is already expired — do not receive it.',
            'lines.*.expiry_date.before' => 'Check the year — that is more than 10 years away.',
            'receivedDate.after_or_equal' => 'Deliveries older than '.self::BACKDATE_DAYS.' days cannot be entered here. Use Stock on hand → Adjust instead.',
            'receivedDate.before_or_equal' => 'The delivery date cannot be in the future.',
        ], [
            'supplierId' => 'supplier', 'receivedDate' => 'delivery date',
            'lines.*.quantity' => 'quantity', 'lines.*.unit_cost' => 'unit cost',
        ]);

        // Per-line limits alone still allow 10,000 × ₱1,000,000 = ₱10 billion, which overflows the
        // money columns and would be a typing mistake anyway. Same ceiling as one expense entry.
        if ($this->total > self::MAX_TOTAL) {
            $this->addError('lines', 'This delivery totals '.peso($this->total).', which is over the '.peso(self::MAX_TOTAL).' limit. Check the quantities and unit costs.');

            return null;
        }

        $stockIn = $this->attempt(fn () => $service->stockIn(
            Supplier::findOrFail($this->supplierId),
            collect($this->lines)->map(fn ($l) => [
                'inventory_item_id' => (int) $l['item_id'],
                'quantity' => (int) $l['quantity'],
                'unit_cost' => (float) $l['unit_cost'],
                'expiry_date' => $l['expiry_date'] ?: null,
                'batch_code' => $l['batch_code'] ?: null,
            ])->all(),
            $this->receivedDate,
            $this->invoiceNumber ?: null,
            $this->notes ?: null,
            $this->recordExpense,
            $this->paymentMethod,
        ));

        if ($stockIn) {
            $this->flashToast("Delivery {$stockIn->reference_no} recorded — ".count($this->lines).' item(s), '.peso($stockIn->total_cost).'.');

            return $this->redirectRoute('inventory.stock-ins', navigate: true);
        }

        return null;
    }

    public function render()
    {
        $items = InventoryItem::active()->orderBy('name')->get(['id', 'name', 'unit', 'track_expiry', 'quantity_on_hand', 'reorder_level', 'default_supplier_id']);

        return view('livewire.inventory.stock-in-form', [
            'suppliers' => Supplier::active()->orderBy('name')->pluck('name', 'id'),
            'items' => $items,
            'itemsById' => $items->keyBy('id'),
        ]);
    }
}
