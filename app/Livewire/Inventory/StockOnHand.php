<?php

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\WithToasts;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\ItemUsage;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Rules\DisplayName;
use App\Rules\UniqueName;
use App\Services\InventoryService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stock on hand + the bar's open units.
 * Staff: stock out one unit, mark the open unit depleted / discarded.
 * Manager: also add/edit/archive items, adjust quantities, manage item categories.
 */
#[Title('Stock on hand')]
class StockOnHand extends Component
{
    use WithPagination, WithToasts;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $filter = ''; // low | out | in_use | expiring

    public $historyId = null;

    // Item form
    public bool $showForm = false;
    #[Locked] // set only by the server (open/edit methods), never by the browser
    public ?int $editingId = null;
    public array $form = [];

    // Adjustment
    public bool $showAdjust = false;
    #[Locked] // set only by the server (open/edit methods), never by the browser
    public ?int $adjustingId = null;
    public string $adjustType = 'adjustment_out';
    public $adjustQty = 1;
    public $adjustBatch = '';
    public string $adjustReason = '';

    // Categories
    public bool $showCategories = false;
    public string $newCategory = '';
    public ?int $renamingId = null;

    // Stock out dialog
    public bool $showStockOut = false;
    #[Locked] // set only by the server (open/edit methods), never by the browser
    public ?int $stockOutId = null;
    public $stockOutQty = 1;
    public string $stockOutLast = 'open'; // open | empty — the last unit taken out
    public string $renameValue = '';

    public function updating($name): void
    {
        if (in_array($name, ['search', 'category', 'filter'])) {
            $this->resetPage();
        }
    }

    /* ── Bar actions (staff & manager) ──────────────────── */

    /** Open the Stock out dialog (usually 1 unit; more when recording later, e.g. "opened 3 today"). */
    public function startStockOut(int $id): void
    {
        $this->resetValidation();
        $this->stockOutId = InventoryItem::findOrFail($id)->id;
        $this->stockOutQty = 1;
        $this->stockOutLast = 'open';
        $this->showStockOut = true;
    }

    public function stockOut(InventoryService $service): void
    {
        $item = InventoryItem::findOrFail($this->stockOutId);
        $this->validate([
            'stockOutQty' => ['required', 'integer', 'min:1', 'max:'.max(1, $item->quantity_on_hand)],
            'stockOutLast' => ['required', 'in:open,empty'],
        ], ['stockOutQty.max' => "Only {$item->quantity_on_hand} in storage."], ['stockOutQty' => 'quantity']);

        $qty = (int) $this->stockOutQty;
        $usage = $this->attempt(fn () => $service->stockOut($item, null, null, null, $qty, $this->stockOutLast === 'empty'));
        if ($usage) {
            $this->showStockOut = false;
            // Units are opened one after another (one open per item): earlier ones are marked used up.
            $this->toast($qty === 1
                ? "1 {$item->unit} of {$item->name} ".($this->stockOutLast === 'empty' ? 'recorded as used up.' : 'is now in use.')
                    .($service->finishedPrevious ? ' The previous one was marked as used up.' : '')
                : "{$qty} {$item->unit} of {$item->name} stocked out — "
                    .($this->stockOutLast === 'empty' ? 'all marked as used up.' : 'the last one is in use, the others are marked used up.'));
            $this->dispatch('stock-changed');
        }
    }

    public function markDepleted(InventoryService $service, int $usageId): void
    {
        $usage = ItemUsage::with('item')->findOrFail($usageId);
        if ($this->attempt(fn () => $service->markDepleted($usage), "{$usage->item->name} marked as used up. You can stock out a new one.")) {
            $this->dispatch('stock-changed');
        }
    }

    public function discard(InventoryService $service, int $usageId, string $reason = ''): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            $this->toast('Enter why the open unit is being discarded.', 'error');

            return;
        }
        $usage = ItemUsage::with('item')->findOrFail($usageId);
        if ($this->attempt(fn () => $service->discard($usage, mb_substr(trim($reason), 0, 200)), "Open unit of {$usage->item->name} discarded.")) {
            $this->dispatch('stock-changed');
        }
    }

    /* ── Item CRUD (manager) ─────────────────────────────── */

    private function blankForm(): array
    {
        return ['name' => '', 'sku' => '', 'item_category_id' => '', 'default_supplier_id' => '', 'unit' => '', 'reorder_level' => 2, 'track_expiry' => true, 'notes' => '', 'is_active' => true];
    }

    public function create(): void
    {
        $this->authorize('manage');
        $this->resetValidation();
        $this->editingId = null;
        $this->form = $this->blankForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('manage');
        $this->resetValidation();
        $item = InventoryItem::findOrFail($id);
        $this->editingId = $id;
        $this->form = $item->only(['name', 'sku', 'item_category_id', 'default_supplier_id', 'unit', 'reorder_level', 'track_expiry', 'notes', 'is_active']);
        $this->form['sku'] ??= '';
        $this->form['notes'] ??= '';
        $this->form['default_supplier_id'] ??= '';
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('manage');
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:150', new DisplayName('“Fresh Milk”'), new UniqueName('inventory_items', 'name', $this->editingId)],
            'form.sku' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-_.]*$/', new UniqueName('inventory_items', 'sku', $this->editingId)],
            'form.item_category_id' => ['required', Rule::exists('item_categories', 'id')->whereNull('deleted_at')],
            'form.default_supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'form.unit' => ['required', 'string', 'max:40', new DisplayName('“carton (1 L)”')],
            'form.reorder_level' => ['required', 'integer', 'min:0', 'max:10000'],
            'form.track_expiry' => ['boolean'],
            'form.notes' => ['nullable', 'string', 'max:1000'],
            'form.is_active' => ['boolean'],
        ], [], [
            'form.name' => 'item name', 'form.sku' => 'SKU', 'form.item_category_id' => 'category',
            'form.default_supplier_id' => 'supplier', 'form.unit' => 'unit', 'form.reorder_level' => 'reorder level',
        ])['form'];

        $data['sku'] = filled($data['sku'] ?? null) ? strtoupper(trim($data['sku'])) : null;
        $data['default_supplier_id'] = $data['default_supplier_id'] ?: null;
        $data['notes'] = $data['notes'] ?: null;

        if ($this->editingId) {
            InventoryItem::findOrFail($this->editingId)->update($data);
            $this->toast("{$data['name']} updated.");
        } else {
            InventoryItem::create($data);
            $this->toast("{$data['name']} added. Record a stock in to give it quantity.");
        }
        $this->showForm = false;
    }

    public function archive(int $id): void
    {
        $this->authorize('manage');
        $item = InventoryItem::findOrFail($id);
        // Only archive what is truly finished: nothing in storage, nothing open, nothing on the menu uses it
        // (otherwise its value would stay in the books and sales would keep listing it in stock checks).
        $usedBy = \App\Models\Product::whereIn('id', $item->productLinks()->select('product_id'))->orderBy('name')->pluck('name');
        $addons = \App\Models\Addon::whereHas('ingredientRules', fn ($q) => $q->where('inventory_item_id', $item->id))->orderBy('name')->pluck('name');
        $reason = match (true) {
            $item->quantity_on_hand > 0 => "{$item->name} still has {$item->quantity_on_hand} {$item->unit} in storage. Use them or write them off (Adjust) first.",
            $item->currentUsage()->exists() => "A unit of {$item->name} is still open. Mark it as depleted first.",
            $usedBy->isNotEmpty() => "{$item->name} is still used by: ".$usedBy->join(', ').'. Remove it from those products first.',
            $addons->isNotEmpty() => "{$item->name} is still used by the add-on(s): ".$addons->join(', ').'. Change those add-ons first.',
            default => null,
        };
        if ($reason) {
            $this->toast($reason, 'error');

            return;
        }
        $item->delete();
        $this->toast("{$item->name} moved to the archive. A manager can restore it from Archive.");
        $this->dispatch('stock-changed');
    }

    /* ── Adjustments (manager) ───────────────────────────── */

    public function startAdjust(int $id, string $type = 'adjustment_out'): void
    {
        $this->authorize('manage');
        $this->resetValidation();
        $this->adjustingId = $id;
        $this->adjustType = $type;
        $this->adjustQty = 1;
        $this->adjustBatch = '';
        $this->adjustReason = '';
        $this->showAdjust = true;
    }

    public function adjust(InventoryService $service): void
    {
        $this->authorize('manage');
        $this->validate([
            'adjustType' => ['required', Rule::in(['adjustment_in', 'adjustment_out', 'waste', 'expired'])],
            'adjustQty' => ['required', 'integer', 'min:1', 'max:10000'],
            'adjustBatch' => ['nullable', 'integer'],
            'adjustReason' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['adjustQty' => 'quantity', 'adjustReason' => 'reason']);

        $item = InventoryItem::findOrFail($this->adjustingId);
        $ok = $this->attempt(fn () => $service->adjust($item, $this->adjustType, (int) $this->adjustQty, trim($this->adjustReason), $this->adjustBatch ? (int) $this->adjustBatch : null));
        if ($ok) {
            $this->showAdjust = false;
            $this->toast("Stock of {$item->name} adjusted.");
            $this->dispatch('stock-changed');
        }
    }

    /* ── Item categories (manager) ───────────────────────── */

    public function addCategory(): void
    {
        $this->authorize('manage');
        $this->validate(['newCategory' => ['required', 'string', 'max:100', new DisplayName('“Dairy & Alternatives”'), new UniqueName('item_categories')]], [], ['newCategory' => 'category name']);
        ItemCategory::create(['name' => trim($this->newCategory)]);
        $this->toast("Category “{$this->newCategory}” added.");
        $this->newCategory = '';
    }

    public function renameCategory(): void
    {
        $this->authorize('manage');
        $this->validate(['renameValue' => ['required', 'string', 'max:100', new DisplayName('“Dairy & Alternatives”'), new UniqueName('item_categories', 'name', $this->renamingId)]], [], ['renameValue' => 'category name']);
        ItemCategory::findOrFail($this->renamingId)->update(['name' => trim($this->renameValue)]);
        $this->renamingId = null;
        $this->toast('Category renamed.');
    }

    public function archiveCategory(int $id): void
    {
        $this->authorize('manage');
        $cat = ItemCategory::withCount('items')->findOrFail($id);
        if ($cat->items_count > 0) {
            $this->toast("“{$cat->name}” still has {$cat->items_count} item(s). Move them to another category first.", 'error');

            return;
        }
        $cat->delete();
        $this->toast("Category “{$cat->name}” archived.");
    }

    public function render()
    {
        $this->category = (string) (clean_id($this->category) ?? '');
        $this->filter = in_array($this->filter, ['low', 'out', 'in_use', 'expiring'], true) ? $this->filter : '';

        $items = InventoryItem::query()
            ->with(['category', 'currentUsage.opener', 'availableBatches'])
            ->when($this->search, fn ($q) => $q->where(fn ($w) => $w->whereRaw('lower(name) like ?', ['%'.mb_strtolower($this->search).'%'])->orWhereRaw('lower(sku) like ?', ['%'.mb_strtolower($this->search).'%'])))
            ->when($this->category, fn ($q) => $q->where('item_category_id', $this->category))
            ->when($this->filter === 'low', fn ($q) => $q->lowStock()->where('quantity_on_hand', '>', 0))
            ->when($this->filter === 'out', fn ($q) => $q->where('quantity_on_hand', '<=', 0))
            ->when($this->filter === 'in_use', fn ($q) => $q->whereHas('currentUsage'))
            ->when($this->filter === 'expiring', fn ($q) => $q->whereHas('batches', fn ($b) => $b->where('quantity_remaining', '>', 0)->whereNotNull('expiry_date')->where('expiry_date', '<=', today()->addDays(7))))
            ->orderByRaw('CASE WHEN quantity_on_hand <= reorder_level THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->paginate(15);

        $stats = [
            'total' => InventoryItem::active()->count(),
            'low' => InventoryItem::active()->lowStock()->where('quantity_on_hand', '>', 0)->count(),
            'out' => InventoryItem::active()->where('quantity_on_hand', '<=', 0)->count(),
            'in_use' => ItemUsage::where('status', 'in_use')->count(),
            'expiring' => StockBatch::where('quantity_remaining', '>', 0)->whereNotNull('expiry_date')->where('expiry_date', '<=', today()->addDays(7))->distinct('inventory_item_id')->count('inventory_item_id'),
            'value' => (float) StockBatch::where('quantity_remaining', '>', 0)->selectRaw('COALESCE(SUM(quantity_remaining * unit_cost),0) as v')->value('v'),
        ];

        $history = null;
        if ($this->historyId) {
            $history = InventoryItem::with([
                'category', 'defaultSupplier',
                'batches' => fn ($q) => $q->where('quantity_remaining', '>', 0)->orderByRaw('expiry_date ASC NULLS LAST')->with('stockIn.supplier'),
                'usages' => fn ($q) => $q->latest('opened_at')->limit(15)->with(['opener', 'closer']),
            ])->find($this->historyId);
        }

        return view('livewire.inventory.stock-on-hand', [
            'items' => $items,
            'stats' => $stats,
            'categories' => ItemCategory::withCount('items')->orderBy('name')->get(),
            'suppliers' => Supplier::active()->orderBy('name')->pluck('name', 'id'),
            'history' => $history,
            'stockingOut' => $this->stockOutId ? InventoryItem::with('currentUsage.opener')->find($this->stockOutId) : null,
            'adjusting' => $this->adjustingId ? InventoryItem::with(['availableBatches'])->find($this->adjustingId) : null,
        ]);
    }
}
