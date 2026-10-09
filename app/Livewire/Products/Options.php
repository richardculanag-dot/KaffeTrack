<?php

namespace App\Livewire\Products;

use App\Livewire\Concerns\WithToasts;
use App\Models\Addon;
use App\Models\Category;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Categories & add-ons')]
class Options extends Component
{
    use WithToasts;

    public const ICONS = ['coffee' => 'Coffee', 'cup' => 'Iced cup', 'snowflake' => 'Frozen', 'leaf' => 'Tea / leaf', 'cake' => 'Pastry'];

    public bool $showCategory = false;
    public ?int $categoryId = null;
    public array $cat = [];

    public bool $showAddon = false;
    public ?int $addonId = null;
    public array $addon = [];

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
            'cat.name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->ignore($this->categoryId)->whereNull('deleted_at')],
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
        $this->showAddon = true;
    }

    public function editAddon(int $id): void
    {
        $this->resetValidation();
        $a = Addon::findOrFail($id);
        $this->addonId = $id;
        $this->addon = ['name' => $a->name, 'price' => (float) $a->price, 'is_active' => $a->is_active];
        $this->showAddon = true;
    }

    public function saveAddon(): void
    {
        $data = $this->validate([
            'addon.name' => ['required', 'string', 'max:100', Rule::unique('addons', 'name')->ignore($this->addonId)->whereNull('deleted_at')],
            'addon.price' => ['required', 'numeric', 'min:0', 'max:10000'],
            'addon.is_active' => ['boolean'],
        ], [], ['addon.name' => 'add-on name', 'addon.price' => 'price'])['addon'];

        $this->addonId ? Addon::findOrFail($this->addonId)->update($data) : Addon::create($data);
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
            'addons' => Addon::withCount('products')->orderBy('name')->get(),
        ]);
    }
}
