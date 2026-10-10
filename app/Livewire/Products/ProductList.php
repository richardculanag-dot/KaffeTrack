<?php

namespace App\Livewire\Products;

use App\Livewire\Concerns\WithToasts;
use App\Models\Addon;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\OrderItem;
use App\Models\Product;
use App\Rules\DisplayName;
use App\Rules\UniqueName;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Menu products. A product can list the inventory items it uses ("Café Latte uses Fresh Milk and
 * House Blend Beans") — no amounts, because drinks are hand-made without fixed recipes. The link is
 * used to count drinks served per open unit (see App\Services\ServingTracker).
 */
#[Title('Products')]
class ProductList extends Component
{
    use WithFileUploads, WithPagination, WithToasts;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $availability = '';

    public bool $showForm = false;
    #[Locked] // set only by the server (open/edit methods), never by the browser
    public ?int $editingId = null;
    public array $form = [];
    public array $variants = [];
    public array $addonIds = [];
    /** @var array<int, array{role:string, inventory_item_id:string|int, servings:float|string}> */
    public array $ingredients = [];
    public $photo = null;
    public bool $removePhoto = false;

    public function updating($name): void
    {
        if (in_array($name, ['search', 'category', 'availability'])) {
            $this->resetPage();
        }
    }

    private function blankVariant(bool $default = false): array
    {
        return ['id' => null, 'name' => '', 'price' => '', 'is_default' => $default];
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->form = ['name' => '', 'code' => '', 'category_id' => $this->category ?: '', 'description' => '', 'is_available' => true];
        $this->variants = [$this->blankVariant(true)];
        $this->addonIds = [];
        $this->ingredients = [];
        $this->photo = null;
        $this->removePhoto = false;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->resetValidation();
        $product = Product::with(['variants', 'addons', 'ingredients'])->findOrFail($id);
        $this->editingId = $id;
        $this->form = [
            'name' => $product->name,
            'code' => $product->code ?? '',
            'category_id' => $product->category_id,
            'description' => $product->description ?? '',
            'is_available' => $product->is_available,
            'image_url' => $product->imageUrl(),
        ];
        $this->variants = $product->variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'price' => (float) $v->price, 'is_default' => $v->is_default])->all();
        $this->addonIds = $product->addons->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->ingredients = $product->ingredients->map(fn ($i) => ['role' => $i->role, 'inventory_item_id' => (string) $i->inventory_item_id, 'servings' => $i->servings])->all();
        $this->photo = null;
        $this->removePhoto = false;
        $this->showForm = true;
    }

    public function addIngredient(): void
    {
        $this->ingredients[] = ['role' => '', 'inventory_item_id' => '', 'servings' => 1];
    }

    public function removeIngredient(int $index): void
    {
        unset($this->ingredients[$index]);
        $this->ingredients = array_values($this->ingredients);
    }

    public function addVariant(): void
    {
        $this->variants[] = $this->blankVariant(empty($this->variants));
    }

    public function removeVariant(int $index): void
    {
        if (count($this->variants) <= 1) {
            $this->toast('A product needs at least one size or price.', 'error');

            return;
        }
        $wasDefault = $this->variants[$index]['is_default'];
        unset($this->variants[$index]);
        $this->variants = array_values($this->variants);
        if ($wasDefault) {
            $this->variants[0]['is_default'] = true;
        }
    }

    public function setDefault(int $index): void
    {
        foreach ($this->variants as $i => $v) {
            $this->variants[$i]['is_default'] = $i === $index;
        }
    }

    public function save(): void
    {
        $this->validate([
            'form.name' => ['required', 'string', 'max:150', new DisplayName('“Café Latte” or “7-Up Float”'), new UniqueName('products', 'name', $this->editingId)],
            'form.code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-_.]*$/', new UniqueName('products', 'code', $this->editingId)],
            'form.category_id' => ['required', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'form.description' => ['nullable', 'string', 'max:500'],
            'form.is_available' => ['boolean'],
            'variants' => ['required', 'array', 'min:1', 'max:8'],
            'variants.*.name' => ['required', 'string', 'max:60', 'distinct:ignore_case', new DisplayName('“12 oz Hot”, “18 oz” or “Regular”')],
            'variants.*.price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'addonIds' => ['array'],
            'addonIds.*' => [Rule::exists('addons', 'id')->whereNull('deleted_at')],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'ingredients' => ['array', 'max:10'],
            'ingredients.*.role' => ['required', 'string', 'max:30', 'distinct:ignore_case'],
            'ingredients.*.inventory_item_id' => ['required', Rule::exists('inventory_items', 'id')->whereNull('deleted_at')],
            'ingredients.*.servings' => ['required', 'numeric', 'min:0.25', 'max:10'],
        ], [
            'ingredients.*.role.required' => 'Name what this item is for, e.g. milk.',
            'ingredients.*.role.distinct' => 'Each role can be used once (an add-on replaces it by this name).',
            'ingredients.*.inventory_item_id.required' => 'Choose an inventory item.',
            'variants.*.name.required' => 'Name this size, e.g. “12 oz Hot” or “Regular”.',
            'variants.*.name.distinct' => 'Each size needs a different name.',
            'variants.*.price.required' => 'Enter a price.',
            'photo.max' => 'The photo must be 2 MB or smaller.',
        ], [
            'form.name' => 'product name', 'form.code' => 'product code', 'form.category_id' => 'category',
            'variants.*.price' => 'price', 'variants.*.name' => 'size name',
            'ingredients.*.role' => 'role', 'ingredients.*.inventory_item_id' => 'inventory item', 'ingredients.*.servings' => 'servings',
        ]);

        DB::transaction(function () {
            $data = [
                'name' => DisplayName::clean($this->form['name']),
                'code' => filled($this->form['code']) ? strtoupper(trim($this->form['code'])) : null,
                'category_id' => $this->form['category_id'],
                'description' => $this->form['description'] ?: null,
                'is_available' => (bool) $this->form['is_available'],
            ];

            $product = $this->editingId ? Product::with('variants')->findOrFail($this->editingId) : new Product;
            $oldPrices = $product->exists ? $product->variants->mapWithKeys(fn ($v) => [$v->name => (float) $v->price])->all() : [];

            if ($this->photo) {
                $this->deletePhoto($product);
                $data['image_path'] = $this->photo->store('products', 'uploads');
            } elseif ($this->removePhoto) {
                $this->deletePhoto($product);
                $data['image_path'] = null;
            }

            $product->fill($data)->save();

            // Sync sizes: update kept rows, add new ones, archive removed ones (old orders keep their snapshot).
            $keep = [];
            foreach (array_values($this->variants) as $i => $v) {
                $attrs = ['name' => DisplayName::clean($v['name']), 'price' => round((float) $v['price'], 2), 'is_default' => (bool) $v['is_default'], 'sort_order' => $i];
                $row = $v['id'] ? $product->variants()->whereKey($v['id'])->first() : null;
                $row ? $row->update($attrs) : $row = $product->variants()->create($attrs);
                $keep[] = $row->id;
            }
            $product->variants()->whereNotIn('id', $keep)->get()->each->delete();
            $product->addons()->sync($this->addonIds);

            // Inventory items this product uses (replace the whole list; sales keep their own snapshot).
            $itemNames = InventoryItem::withTrashed()->pluck('name', 'id');
            $describe = fn ($rows) => collect($rows)->mapWithKeys(fn ($r) => [mb_strtolower(trim($r['role'])) => $itemNames[$r['inventory_item_id']].((float) $r['servings'] != 1.0 ? ' ×'.(float) $r['servings'] : '')])->sortKeys()->all();
            $before = $product->exists ? $describe($product->ingredients()->get()->toArray()) : [];
            $product->ingredients()->delete();
            foreach ($this->ingredients as $row) {
                $product->ingredients()->create([
                    'role' => mb_strtolower(trim($row['role'])),
                    'inventory_item_id' => (int) $row['inventory_item_id'],
                    'servings' => round((float) $row['servings'], 2),
                ]);
            }
            $after = $describe($this->ingredients);
            if ($before != $after) {
                AuditLogger::log('products', 'ingredients_updated', "Updated inventory items used by \"{$product->name}\"", $product, $before ?: null, $after ?: null);
            }

            $newPrices = collect($this->variants)->mapWithKeys(fn ($v) => [DisplayName::clean($v['name']) => round((float) $v['price'], 2)])->all();
            if ($this->editingId && $oldPrices != $newPrices) {
                AuditLogger::log('products', 'updated', "Updated sizes & prices of \"{$product->name}\"", $product, $oldPrices, $newPrices);
            }
        });

        $this->toast($this->editingId ? "{$this->form['name']} updated." : "{$this->form['name']} added to the menu.");
        $this->showForm = false;
        $this->photo = null;
    }

    private function deletePhoto(Product $product): void
    {
        if ($product->image_path) {
            Storage::disk('uploads')->delete($product->image_path);
        }
    }

    public function toggleAvailability(int $id): void
    {
        $product = Product::findOrFail($id);
        $product->update(['is_available' => ! $product->is_available]);
        $this->toast($product->is_available ? "{$product->name} is back on the POS menu." : "{$product->name} hidden from the POS (marked sold out).", 'info');
    }

    public function archive(int $id): void
    {
        $product = Product::findOrFail($id);
        $product->delete();
        $this->toast("{$product->name} archived. Sales history is kept; restore it any time from Archive.");
    }

    public function render()
    {
        $this->category = (string) (clean_id($this->category) ?? '');
        $this->availability = in_array($this->availability, ['', '0', '1'], true) ? $this->availability : '';

        $sold = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.payment_status', 'paid')
            ->where('orders.created_at', '>=', today()->subDays(29))
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as qty')
            ->pluck('qty', 'product_id');

        return view('livewire.products.product-list', [
            'products' => Product::with(['category', 'variants', 'ingredients.item'])->withCount('addons')
                ->when($this->search, fn ($q) => $q->where(fn ($w) => $w->whereRaw('lower(name) like ?', ['%'.mb_strtolower($this->search).'%'])->orWhereRaw('lower(code) like ?', ['%'.mb_strtolower($this->search).'%'])))
                ->when($this->category, fn ($q) => $q->where('category_id', $this->category))
                ->when($this->availability !== '', fn ($q) => $q->where('is_available', $this->availability === '1'))
                ->orderBy('category_id')->orderBy('name')
                ->paginate(15),
            'sold' => $sold,
            'categories' => Category::orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
            'addons' => Addon::where('is_active', true)->orderBy('name')->get(),
            'inventoryItems' => InventoryItem::where('is_active', true)->orderBy('name')->get(['id', 'name', 'unit']),
        ]);
    }
}
