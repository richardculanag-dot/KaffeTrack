<div class="space-y-6">
    <x-page-header description="Categories group the POS menu (their order is the tab order on the POS). Add-ons are extras like an extra shot or oat milk." />

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="panel overflow-hidden">
            <div class="panel-header">
                <h2 class="panel-title">Menu categories</h2>
                <button type="button" class="btn-primary btn-sm" wire:click="newCategory"><x-icon name="plus" class="size-4" /> Add category</button>
            </div>
            <ul class="divide-y divide-sage-100">
                @forelse ($categories as $c)
                    <li wire:key="cat-{{ $c->id }}" class="flex items-center gap-3 px-5 py-3">
                        <div class="flex flex-col">
                            <button type="button" class="rounded p-0.5 text-sage-400 hover:bg-sage-100 hover:text-ink disabled:opacity-30" wire:click="moveCategory({{ $c->id }}, -1)" @disabled($loop->first) aria-label="Move {{ $c->name }} up"><x-icon name="chevron-up" class="size-4" /></button>
                            <button type="button" class="rounded p-0.5 text-sage-400 hover:bg-sage-100 hover:text-ink disabled:opacity-30" wire:click="moveCategory({{ $c->id }}, 1)" @disabled($loop->last) aria-label="Move {{ $c->name }} down"><x-icon name="chevron-down" class="size-4" /></button>
                        </div>
                        <span class="grid size-10 place-items-center rounded-xl bg-leaf-100 text-leaf-700"><x-icon :name="$c->icon ?: 'coffee'" class="size-5" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold">{{ $c->name }} @unless ($c->is_active)<span class="badge-gray ml-1">Hidden</span>@endunless</p>
                            <p class="truncate text-xs text-sage-500">{{ $c->products_count }} product(s){{ $c->description ? ' · '.$c->description : '' }}</p>
                        </div>
                        <button type="button" class="btn-icon" wire:click="editCategory({{ $c->id }})" aria-label="Edit {{ $c->name }}"><x-icon name="pencil" class="size-4" /></button>
                        <button type="button" class="btn-icon hover:text-danger-700" aria-label="Archive {{ $c->name }}"
                                @click="$confirm({ title: @js('Archive “'.$c->name.'”?'), message: 'Only categories with no products can be archived.', confirmText: 'Archive', tone: 'danger' }, () => $wire.archiveCategory({{ $c->id }}))"><x-icon name="archive" class="size-4" /></button>
                    </li>
                @empty
                    <li><x-empty-state icon="layers" title="No categories yet" description="Create at least one category before adding products." /></li>
                @endforelse
            </ul>
        </section>

        <section class="panel overflow-hidden">
            <div class="panel-header">
                <h2 class="panel-title">Add-ons</h2>
                <button type="button" class="btn-primary btn-sm" wire:click="newAddon"><x-icon name="plus" class="size-4" /> Add add-on</button>
            </div>
            <table class="table">
                <thead><tr><th>Add-on</th><th class="text-right">Price</th><th>Used on</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($addons as $a)
                        <tr wire:key="ad-{{ $a->id }}">
                            <td>
                                <p class="font-semibold">{{ $a->name }}</p>
                                @foreach ($a->ingredientRules as $r)
                                    <p class="text-xs text-sage-500">{{ $r->action === 'replace' ? 'Replaces the '.$r->role.' with '.$r->item->name : 'Adds '.$r->item->name.($r->servings != 1 ? ' ×'.(float) $r->servings : '') }}</p>
                                @endforeach
                            </td>
                            <td class="text-right tabular">+{{ peso($a->price) }}</td>
                            <td class="text-sage-600">{{ $a->products_count }} product(s)</td>
                            <td><x-status-badge :status="$a->is_active ? 'active' : 'inactive'" /></td>
                            <td>
                                <div class="flex justify-end gap-1">
                                    <button type="button" class="btn-icon" wire:click="editAddon({{ $a->id }})" aria-label="Edit {{ $a->name }}"><x-icon name="pencil" class="size-4" /></button>
                                    <button type="button" class="btn-icon hover:text-danger-700" aria-label="Archive {{ $a->name }}"
                                            @click="$confirm({ title: @js('Archive “'.$a->name.'”?'), message: 'It will no longer be offered at the POS. Past orders keep it.', confirmText: 'Archive', tone: 'danger' }, () => $wire.archiveAddon({{ $a->id }}))"><x-icon name="archive" class="size-4" /></button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-empty-state icon="plus" title="No add-ons yet" description="Extras like an extra shot or oat milk." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>

    <x-modal wire:model="showCategory" :title="$categoryId ? 'Edit category' : 'Add category'" icon="layers">
        <form id="cat-form" wire:submit="saveCategory" class="space-y-4">
            <x-input label="Name" name="cat.name" wire:model.blur="cat.name" required placeholder="e.g. Espresso Classics" />
            <x-input label="Description" name="cat.description" wire:model.blur="cat.description" optional />
            <fieldset>
                <legend class="field-label">Icon</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Livewire\Products\Options::ICONS as $icon => $label)
                        <label class="cursor-pointer" title="{{ $label }}">
                            <input type="radio" wire:model="cat.icon" value="{{ $icon }}" class="peer sr-only">
                            <span class="flex items-center gap-2 rounded-xl border-2 border-sage-200 px-3 py-2 text-sm peer-checked:border-leaf-600 peer-checked:bg-leaf-50 peer-focus-visible:ring-4 peer-focus-visible:ring-leaf-100">
                                <x-icon :name="$icon" class="size-4" /> {{ $label }}
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <x-toggle label="Show on the POS" wire:model="cat.is_active" />
        </form>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="show = false">Cancel</button>
            <x-submit form="cat-form" target="saveCategory">{{ $categoryId ? 'Save changes' : 'Add category' }}</x-submit>
        </x-slot:footer>
    </x-modal>

    <x-modal wire:model="showAddon" :title="$addonId ? 'Edit add-on' : 'Add add-on'" size="md" icon="plus">
        <form id="addon-form" wire:submit="saveAddon" class="space-y-4">
            <x-input label="Name" name="addon.name" wire:model.blur="addon.name" required placeholder="e.g. Extra espresso shot" />
            <x-input label="Price" name="addon.price" type="number" step="0.01" min="0" prefix="₱" wire:model.blur="addon.price" required />
            <x-toggle label="Active" wire:model="addon.is_active" />

            <fieldset class="space-y-3 rounded-xl border border-sage-200 p-4">
                <legend class="px-1 text-sm font-semibold">Effect on inventory <span class="font-normal text-sage-400">(optional)</span></legend>
                <x-select label="This add-on…" name="rule.action" wire:model.live="rule.action"
                          :options="['replace' => 'Replaces an item (e.g. oat milk instead of milk)', 'add' => 'Adds an item (e.g. an extra shot uses more beans)']" placeholder="Doesn't affect inventory" />
                @if (filled($rule['action'] ?? ''))
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-input label="{{ $rule['action'] === 'replace' ? 'Role it replaces' : 'Role name' }}" name="rule.role" wire:model.blur="rule.role" list="addon-roles" required
                                 placeholder="{{ $rule['action'] === 'replace' ? 'e.g. milk' : 'e.g. extra shot' }}"
                                 help="{{ $rule['action'] === 'replace' ? 'Same name as on the product, e.g. milk.' : 'A short label for this extra.' }}" />
                        <x-select label="{{ $rule['action'] === 'replace' ? 'Use this item instead' : 'Item it adds' }}" name="rule.inventory_item_id" wire:model="rule.inventory_item_id" required
                                  :options="$inventoryItems->mapWithKeys(fn ($it) => [$it->id => $it->name.' — '.$it->unit])" placeholder="Choose an item" />
                        @if ($rule['action'] === 'add')
                            <x-input label="Counts as" name="rule.servings" type="number" step="0.25" min="0.25" max="10" suffix="serving(s)" wire:model.blur="rule.servings" required class="sm:col-span-2 sm:max-w-48" />
                        @endif
                    </div>
                    <datalist id="addon-roles">
                        @foreach (\App\Models\ProductIngredient::ROLES as $role)<option value="{{ $role }}"></option>@endforeach
                    </datalist>
                @endif
            </fieldset>
        </form>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="show = false">Cancel</button>
            <x-submit form="addon-form" target="saveAddon">{{ $addonId ? 'Save changes' : 'Add add-on' }}</x-submit>
        </x-slot:footer>
    </x-modal>
</div>
