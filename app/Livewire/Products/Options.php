<?php

namespace App\Livewire\Products;

use App\Livewire\Concerns\WithToasts;
use App\Models\Addon;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Rules\DisplayName;
use App\Rules\UniqueName;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Categories & add-ons')]
class Options extends Component
{
    use WithToasts;

    public const ICONS = ['coffee' => 'Coffee', 'cup' => 'Iced cup', 'snowflake' => 'Frozen', 'leaf' => 'Tea / leaf', 'cake' => 'Pastry'];

    public bool $showCategory = false;
    #[Locked] // set only by the server (open/edit methods), never by the browser
    public ?int $categoryId = null;
    public array $cat = [];

    public bool $showAddon = false;
    #[Locked] // set only by the server (open/edit methods), never by the browser
    public ?int $addonId = null;
    public array $addon = [];
    /** Inventory effect of the add-on: '' (none) | replace | add. */
    public array $rule = [];

    /* ── Categories ─────────────────────────────────── */

    public function newCategory(): void
    {
        $this->resetValidation();
        $this->categoryId = null;
        $this->cat = ['name' => '', 'description' => '', 'icon' => 'coffee', 'sort_order' => (int) Category::max('sort_order') + 1, 'is_active' => true];
        $this->showCategory = true;
    }

    public function editCategory(int $id): void
    {
        $this->resetValidation();
        $c = Category::findOrFail($id);
        $this->categoryId = $id;
        $this->cat = ['name' => $c->name, 'description' => $c->description ?? '', 'icon' => $c->icon ?: 'coffee', 'sort_order' => $c->sort_order, 'is_active' => $c->is_active];
        $this->showCategory = true;
    }

    public function saveCategory(): void
    {
        $data = $this->validate([
            'cat.name' => ['required', 'string', 'max:100', new DisplayName('“Espresso Classics”'), new UniqueName('categories', 'name', $this->categoryId)],
            'cat.description' => ['nullable', 'string', 'max:255'],
            'cat.icon' => ['required', Rule::in(array_keys(self::ICONS))],
            'cat.sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'cat.is_active' => ['boolean'],
        ], [], ['cat.name' => 'category name', 'cat.sort_order' => 'position'])['cat'];
        $data['description'] = $data['description'] ?: null;

        $this->categoryId ? Category::findOrFail($this->categoryId)->update($data) : Category::create($data);
        $this->toast($this->categoryId ? 'Category updated.' : "Category “{$data['name']}” added.");
        $this->showCategory = false;
    }

    public function moveCategory(int $id, int $direction): void
    {
        // Re-number all categories so positions are always 0, 1, 2… then swap the neighbours.
        $ordered = Category::orderBy('sort_order')->orderBy('name')->get()->values();
        $index = $ordered->search(fn ($c) => $c->id === $id);
        $swap = $index + $direction;
        if ($index === false || $swap < 0 || $swap >= $ordered->count()) {
            return;
        }
        [$ordered[$index], $ordered[$swap]] = [$ordered[$swap], $ordered[$index]];
        Category::$auditingEnabled = false; // one readable log entry instead of one per category
        foreach ($ordered as $pos => $c) {
            if ($c->sort_order !== $pos) {
                $c->update(['sort_order' => $pos]);
            }
        }
        Category::$auditingEnabled = true;
        AuditLogger::log('products', 'updated', 'Reordered menu categories', null, null, ['order' => $ordered->pluck('name')->all()]);
    }

    public function archiveCategory(int $id): void
    {
        $c = Category::withCount('products')->findOrFail($id);
        if ($c->products_count > 0) {
            $this->toast("“{$c->name}” still has {$c->products_count} product(s). Move or archive them first.", 'error');

            return;
        }
        $c->delete();
        $this->toast("Category “{$c->name}” archived.");
    }

    /* ── Add-ons ────────────────────────────────────── */

    public function newAddon(): void
    {
        $this->resetValidation();
        $this->addonId = null;
        $this->addon = ['name' => '', 'price' => '', 'is_active' => true];
        $this->rule = ['action' => '', 'role' => '', 'inventory_item_id' => ''];
        $this->showAddon = true;
    }

    public function editAddon(int $id): void
    {
        $this->resetValidation();
        $a = Addon::with('ingredientRules')->findOrFail($id);
        $this->addonId = $id;
        $this->addon = ['name' => $a->name, 'price' => (float) $a->price, 'is_active' => $a->is_active];
        $r = $a->ingredientRules->first();
        $this->rule = $r
            ? ['action' => $r->action, 'role' => $r->role, 'inventory_item_id' => (string) $r->inventory_item_id]
            : ['action' => '', 'role' => '', 'inventory_item_id' => ''];
        $this->showAddon = true;
    }

    public function saveAddon(): void
    {
        $data = $this->validate([
            'addon.name' => ['required', 'string', 'max:100', new DisplayName('“Extra espresso shot”'), new UniqueName('addons', 'name', $this->addonId)],
            'addon.price' => ['required', 'numeric', 'min:0', 'max:10000'],
            'addon.is_active' => ['boolean'],
            'rule.action' => ['nullable', Rule::in(['', 'add', 'replace'])],
            'rule.role' => ['exclude_if:rule.action,', 'required', 'string', 'max:30', new DisplayName('“milk”')],
            'rule.inventory_item_id' => ['exclude_if:rule.action,', 'required', Rule::exists('inventory_items', 'id')->whereNull('deleted_at')],
        ], [
            'rule.role.required' => 'Name the role, e.g. milk.',
            'rule.inventory_item_id.required' => 'Choose the inventory item.',
        ], ['addon.name' => 'add-on name', 'addon.price' => 'price'])['addon'];

        DB::transaction(function () use ($data) {
            $addon = $this->addonId ? tap(Addon::findOrFail($this->addonId))->update($data) : Addon::create($data);

            // Inventory effect (Sales ↔ Inventory link): one rule per add-on from this form.
            $before = $addon->ingredientRules()->with('item')->get()->map(fn ($r) => "{$r->action} {$r->role}: {$r->item->name}")->all();
            $addon->ingredientRules()->delete();
            if (filled($this->rule['action'] ?? '')) {
                $addon->ingredientRules()->create([
                    'action' => $this->rule['action'],
                    'role' => mb_strtolower(trim($this->rule['role'])),
                    'inventory_item_id' => (int) $this->rule['inventory_item_id'],
                ]);
            }
            $after = $addon->ingredientRules()->with('item')->get()->map(fn ($r) => "{$r->action} {$r->role}: {$r->item->name}")->all();
            if ($before != $after) {
                AuditLogger::log('products', 'ingredients_updated', "Updated the inventory effect of add-on \"{$addon->name}\"", $addon, $before ?: null, $after ?: null);
            }
        });
        $this->toast($this->addonId ? 'Add-on updated.' : "Add-on “{$data['name']}” created. Allow it on products from the product form.");
        $this->showAddon = false;
    }

    public function archiveAddon(int $id): void
    {
        $a = Addon::findOrFail($id);
        $a->delete();
        $this->toast("Add-on “{$a->name}” archived.");
    }

    public function render()
    {
        return view('livewire.products.options', [
            'categories' => Category::withCount('products')->orderBy('sort_order')->orderBy('name')->get(),
            'addons' => Addon::withCount('products')->with('ingredientRules.item')->orderBy('name')->get(),
            'inventoryItems' => InventoryItem::where('is_active', true)->orderBy('name')->get(['id', 'name', 'unit']),
        ]);
    }
}
