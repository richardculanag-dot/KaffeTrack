<div class="space-y-6">
    <x-page-header description="Drinks and food sold at the POS. Prices are set per size. Turn a product off to hide it from the POS when it's sold out.">
        <x-slot:actions>
            <a href="{{ route('products.options') }}" wire:navigate class="btn-secondary"><x-icon name="layers" class="size-4" /> Categories & add-ons</a>
            <button type="button" class="btn-primary" wire:click="create"><x-icon name="plus" class="size-4" /> Add product</button>
        </x-slot:actions>
    </x-page-header>

    <section class="panel overflow-hidden">
        <div class="panel-header flex-wrap">
            <div class="flex flex-wrap items-center gap-2">
                <x-search-input wire:model.live.debounce.300ms="search" placeholder="Search name or code" />
                <select wire:model.live="category" class="field w-auto py-2 pr-8" aria-label="Category">
                    <option value="">All categories</option>
                    @foreach ($categories as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <select wire:model.live="availability" class="field w-auto py-2 pr-8" aria-label="Availability">
                    <option value="">On & off the menu</option>
                    <option value="1">On the menu</option>
                    <option value="0">Hidden / sold out</option>
                </select>
            </div>
            <p class="text-sm text-sage-500">{{ $products->total() }} product(s)</p>
        </div>
        <div class="relative overflow-x-auto">
            <div wire:loading.flex wire:target="search, category, availability, gotoPage, nextPage, previousPage" class="absolute inset-0 z-10 items-center justify-center bg-white/60"><x-spinner class="size-6 text-leaf-600" /></div>
            <table class="table">
                <thead><tr><th>Product</th><th>Category</th><th>Sizes & prices</th><th class="text-right">Sold (30 days)</th><th>On the POS</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($products as $product)
                        <tr wire:key="p-{{ $product->id }}">
                            <td>
                                <div class="flex items-center gap-3">
                                    @if ($product->imageUrl())
                                        <img src="{{ $product->imageUrl() }}" alt="" class="size-11 rounded-lg object-cover">
                                    @else
                                        <span class="grid size-11 place-items-center rounded-lg bg-sage-100 text-sage-400"><x-icon name="{{ $product->category->icon ?: 'coffee' }}" class="size-5" /></span>
                                    @endif
                                    <div>
                                        <p class="font-semibold">{{ $product->name }}</p>
                                        <p class="text-xs text-sage-500">{{ $product->code ?: 'No code' }}{{ $product->addons_count ? ' · '.$product->addons_count.' add-on(s)' : '' }}</p>
                                        @if ($product->ingredients->isNotEmpty())
                                            <p class="text-xs text-sage-500">Uses {{ $product->ingredients->map(fn ($i) => $i->item->name)->join(', ') }}</p>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>{{ $product->category->name }}</td>
                            <td>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($product->variants as $v)
                                        <span class="rounded-md border border-sage-200 px-2 py-0.5 text-xs"><span class="text-sage-500">{{ $v->name }}</span> <span class="font-semibold tabular">{{ peso($v->price) }}</span></span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="text-right tabular">{{ number_format($sold[$product->id] ?? 0) }}</td>
                            <td>
                                <button type="button" role="switch" aria-checked="{{ $product->is_available ? 'true' : 'false' }}" wire:click="toggleAvailability({{ $product->id }})"
                                        aria-label="{{ $product->is_available ? 'Hide' : 'Show' }} {{ $product->name }} on the POS"
                                        class="relative inline-flex h-6 w-11 items-center rounded-full transition {{ $product->is_available ? 'bg-leaf-600' : 'bg-sage-300' }}">
                                    <span class="inline-block size-5 rounded-full bg-white shadow transition {{ $product->is_available ? 'translate-x-5.5' : 'translate-x-0.5' }}"></span>
                                </button>
                            </td>
                            <td>
                                <div class="flex justify-end gap-1">
                                    <button type="button" class="btn-icon" wire:click="edit({{ $product->id }})" aria-label="Edit {{ $product->name }}"><x-icon name="pencil" class="size-4" /></button>
                                    <button type="button" class="btn-icon hover:text-danger-700" aria-label="Archive {{ $product->name }}"
                                            @click="$confirm({ title: @js('Archive '.$product->name.'?'), message: 'It disappears from the menu and POS. Past sales keep the product name. You can restore it from Archive.', confirmText: 'Archive product', tone: 'danger' }, () => $wire.archive({{ $product->id }}))">
                                        <x-icon name="archive" class="size-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6">
                            <x-empty-state icon="coffee" title="{{ $search || $category || $availability !== '' ? 'No products match these filters' : 'Your menu is empty' }}" description="Add drinks and food with their sizes and prices.">
                                <button type="button" class="btn-primary" wire:click="create"><x-icon name="plus" class="size-4" /> Add product</button>
                            </x-empty-state>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($products->hasPages())<div class="border-t border-sage-200 px-5 py-3">{{ $products->links() }}</div>@endif
    </section>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit product' : 'Add product'" size="xl" icon="coffee">
        <form id="product-form" wire:submit="save" class="grid gap-6 lg:grid-cols-5">
            <div class="space-y-4 lg:col-span-3">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-input label="Product name" name="form.name" wire:model.blur="form.name" required class="sm:col-span-2" placeholder="e.g. Spanish Latte" />
                    <x-input label="Code" name="form.code" wire:model.blur="form.code" optional placeholder="ESP-SPL" />
                </div>
                <x-select label="Category" name="form.category_id" wire:model="form.category_id" :options="$categories" placeholder="Choose a category" required />
                <x-textarea label="Description" name="form.description" rows="2" wire:model.blur="form.description" optional help="Shown when the cashier customises the drink." />

                <fieldset>
                    <legend class="field-label">Sizes & prices <span class="text-danger-600">*</span></legend>
                    <div class="space-y-2">
                        @foreach ($variants as $i => $v)
                            <div wire:key="v-{{ $i }}" class="flex items-start gap-2">
                                <button type="button" wire:click="setDefault({{ $i }})" title="Pre-selected size at the POS" aria-pressed="{{ $v['is_default'] ? 'true' : 'false' }}"
                                        class="mt-2.5 grid size-6 shrink-0 place-items-center rounded-full border-2 {{ $v['is_default'] ? 'border-leaf-600 bg-leaf-600 text-white' : 'border-sage-300' }}">
                                    @if ($v['is_default'])<x-icon name="check" class="size-3" stroke="3.5" />@endif
                                </button>
                                <x-input name="variants.{{ $i }}.name" wire:model.blur="variants.{{ $i }}.name" placeholder="e.g. 12 oz Hot" aria-label="Size name" class="flex-1" />
                                <x-input name="variants.{{ $i }}.price" type="number" step="0.01" min="0" prefix="₱" wire:model.blur="variants.{{ $i }}.price" placeholder="0.00" aria-label="Price" class="w-36" />
                                <button type="button" class="btn-icon mt-1 hover:text-danger-700" wire:click="removeVariant({{ $i }})" aria-label="Remove size"><x-icon name="trash" class="size-4" /></button>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-2 flex items-center justify-between">
                        <button type="button" class="btn-ghost btn-sm" wire:click="addVariant"><x-icon name="plus" class="size-4" /> Add a size</button>
                        <p class="text-xs text-sage-500">The ticked size is pre-selected at the POS.</p>
                    </div>
                    @error('variants')<p class="field-message">{{ $message }}</p>@enderror
                </fieldset>

                <fieldset>
                    <legend class="field-label">Uses from inventory <span class="font-normal text-sage-400">(optional)</span></legend>
                    <p class="mb-2 text-xs text-sage-500">Which items this drink is made from — no amounts. Each sale counts as one drink served from that item's open unit, so the system can warn before it runs out.</p>
                    <datalist id="ingredient-roles">
                        @foreach (\App\Models\ProductIngredient::ROLES as $role)<option value="{{ $role }}"></option>@endforeach
                    </datalist>
                    <div class="space-y-2">
                        @foreach ($ingredients as $i => $row)
                            <div wire:key="ing-{{ $i }}" class="flex items-start gap-2">
                                <x-input name="ingredients.{{ $i }}.role" wire:model.blur="ingredients.{{ $i }}.role" list="ingredient-roles" placeholder="Role, e.g. milk" aria-label="Role" class="w-32" />
                                <x-select name="ingredients.{{ $i }}.inventory_item_id" wire:model="ingredients.{{ $i }}.inventory_item_id" :options="$inventoryItems->mapWithKeys(fn ($it) => [$it->id => $it->name.' — '.$it->unit])" placeholder="Choose an item" aria-label="Inventory item" class="flex-1" />
                                <x-input name="ingredients.{{ $i }}.servings" type="number" step="0.25" min="0.25" max="10" wire:model.blur="ingredients.{{ $i }}.servings" aria-label="Counts as servings" title="Counts as this many servings per drink (usually 1)" class="w-20" />
                                <button type="button" class="btn-icon mt-1 hover:text-danger-700" wire:click="removeIngredient({{ $i }})" aria-label="Remove item"><x-icon name="trash" class="size-4" /></button>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-2 flex items-center justify-between">
                        <button type="button" class="btn-ghost btn-sm" wire:click="addIngredient"><x-icon name="plus" class="size-4" /> Add an item</button>
                        @if ($ingredients)<p class="text-xs text-sage-500">The number is servings per drink (usually 1).</p>@endif
                    </div>
                </fieldset>
            </div>

            <div class="space-y-4 lg:col-span-2">
                <div>
                    <span class="field-label">Photo <span class="font-normal text-sage-400">(optional)</span></span>
                    <label class="relative flex aspect-[4/3] cursor-pointer flex-col items-center justify-center overflow-hidden rounded-xl border-2 border-dashed border-sage-300 bg-sage-50 text-center hover:border-leaf-400"
                           x-data="{ dragging: false }" @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop="dragging = false" :class="dragging && 'border-leaf-500 bg-leaf-50'">
                        @if ($photo && ! $errors->has('photo'))
                            <img src="{{ $photo->temporaryUrl() }}" alt="New photo preview" class="absolute inset-0 size-full object-cover">
                        @elseif (! $removePhoto && ($form['image_url'] ?? null))
                            <img src="{{ $form['image_url'] }}" alt="Current photo" class="absolute inset-0 size-full object-cover">
                        @else
                            <x-icon name="download" class="size-6 rotate-180 text-sage-400" />
                            <span class="mt-2 text-sm font-semibold">Upload a photo</span>
                            <span class="text-xs text-sage-500">JPG, PNG or WebP · up to 2 MB</span>
                        @endif
                        <input type="file" wire:model="photo" accept="image/jpeg,image/png,image/webp" class="absolute inset-0 cursor-pointer opacity-0" aria-label="Product photo">
                        <div wire:loading.flex wire:target="photo" class="absolute inset-0 items-center justify-center bg-white/70"><x-spinner class="size-6 text-leaf-600" /></div>
                    </label>
                    @error('photo')<p class="field-message">{{ $message }}</p>@enderror
                    @if ($photo || (! $removePhoto && ($form['image_url'] ?? null)))
                        <button type="button" class="btn-ghost btn-sm mt-1" wire:click="$set('photo', null); $set('removePhoto', true)">Remove photo</button>
                    @endif
                </div>

                <fieldset>
                    <legend class="field-label">Allowed add-ons</legend>
                    @if ($addons->isEmpty())
                        <p class="text-sm text-sage-500">No add-ons yet. Create them under Categories & add-ons.</p>
                    @else
                        <div class="flex flex-wrap gap-2">
                            @foreach ($addons as $addon)
                                <label class="cursor-pointer">
                                    <input type="checkbox" value="{{ $addon->id }}" wire:model="addonIds" class="peer sr-only">
                                    <span class="flex items-center gap-1.5 rounded-full border border-sage-300 px-3 py-1.5 text-sm transition peer-checked:border-leaf-600 peer-checked:bg-leaf-600 peer-checked:text-white peer-focus-visible:ring-4 peer-focus-visible:ring-leaf-100">
                                        {{ $addon->name }} <span class="opacity-70">+{{ peso($addon->price) }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </fieldset>

                <x-toggle label="Show on the POS" description="Turn off when sold out or seasonal." wire:model="form.is_available" />
            </div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="show = false">Cancel</button>
            <x-submit form="product-form" target="save, photo">{{ $editingId ? 'Save changes' : 'Add product' }}</x-submit>
        </x-slot:footer>
    </x-modal>
</div>
