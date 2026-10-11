@php $isManager = auth()->user()->isManager(); @endphp
<div class="space-y-6">
    <x-page-header description="Units in storage and what's currently open at the bar. Stock out takes units out of storage (usually one; the open one is marked used up). During a rush you can skip it — the end-of-shift stock check at the cash drawer records what you missed.">
        <x-slot:actions>
            @if ($isManager)
                <button type="button" class="btn-secondary" wire:click="$set('showCategories', true)"><x-icon name="layers" class="size-4" /> Item categories</button>
                <a href="{{ route('inventory.stock-ins.create') }}" wire:navigate class="btn-secondary"><x-icon name="truck" class="size-4" /> Receive stock</a>
                <button type="button" class="btn-primary" wire:click="create"><x-icon name="plus" class="size-4" /> Add item</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- Quick filters double as summary tiles --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        @foreach ([
            ['', 'All items', $stats['total'], 'package', 'text-ink'],
            ['low', 'Low stock', $stats['low'], 'alert', 'text-warn-700'],
            ['out', 'Out of stock', $stats['out'], 'x-circle', 'text-danger-700'],
            ['in_use', 'Open at the bar', $stats['in_use'], 'box-open', 'text-info-600'],
            ['expiring', 'Expiring ≤ 7 days', $stats['expiring'], 'hourglass', 'text-crema-700'],
        ] as [$key, $label, $count, $icon, $color])
            <button type="button" wire:click="$set('filter', '{{ $key }}')" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}"
                    class="panel flex items-center gap-3 p-4 text-left transition hover:border-sage-300 {{ $filter === $key ? 'ring-2 ring-leaf-500 border-leaf-500' : '' }}">
                <x-icon :name="$icon" class="size-5 {{ $color }}" />
                <span>
                    <span class="block font-display text-xl font-semibold leading-tight {{ $color }}">{{ $count }}</span>
                    <span class="block text-[13px] text-sage-600">{{ $label }}</span>
                </span>
            </button>
        @endforeach
        @if ($isManager)
            <div class="panel flex items-center gap-3 p-4" title="Σ remaining units × their purchase cost">
                <x-icon name="banknote" class="size-5 text-leaf-700" />
                <span>
                    <span class="block font-display text-xl font-semibold leading-tight tabular">{{ peso($stats['value']) }}</span>
                    <span class="block text-[13px] text-sage-600">Stock value (at cost)</span>
                </span>
            </div>
        @endif
    </div>

    <section class="panel overflow-hidden">
        <div class="panel-header flex-wrap">
            <div class="flex flex-wrap items-center gap-2">
                <x-search-input wire:model.live.debounce.300ms="search" placeholder="Search items or SKU" />
                <select wire:model.live="category" class="field w-auto py-2 pr-8" aria-label="Category">
                    <option value="">All categories</option>
                    @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
            </div>
            @if ($search || $category || $filter)
                <button type="button" class="btn-ghost btn-sm" wire:click="$set('search', ''); $set('category', ''); $set('filter', '')">Clear filters</button>
            @endif
        </div>

        <div class="relative overflow-x-auto">
            <div wire:loading.flex wire:target="search, category, filter, gotoPage, nextPage, previousPage" class="absolute inset-0 z-10 items-center justify-center bg-white/60"><x-spinner class="size-6 text-leaf-600" /></div>
            <table class="table">
                <thead>
                    <tr><th>Item</th><th>In storage</th><th>At the bar</th><th>Next expiry</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        @php $usage = $item->currentUsage; $next = $item->availableBatches->firstWhere('expiry_date', '!=', null); @endphp
                        <tr wire:key="item-{{ $item->id }}" class="{{ ! $item->is_active ? 'opacity-60' : '' }}">
                            <td>
                                <button type="button" class="text-left" wire:click="$set('historyId', {{ $item->id }})">
                                    <span class="block font-semibold hover:underline">{{ $item->name }}</span>
                                    <span class="block text-xs text-sage-500">{{ $item->sku ?: 'No SKU' }} · {{ $item->category->name }}@if (! $item->is_active) · inactive @endif</span>
                                </button>
                            </td>
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="font-display text-lg font-semibold tabular {{ $item->stockStatus() === 'out' ? 'text-danger-700' : ($item->stockStatus() === 'low' ? 'text-warn-700' : '') }}">{{ $item->quantity_on_hand }}</span>
                                    <span class="text-xs leading-tight text-sage-500">{{ $item->unit }}<br>reorder at {{ $item->reorder_level }}</span>
                                </div>
                                <x-status-badge :status="$item->stockStatus()" class="mt-1" />
                            </td>
                            <td>
                                @if ($usage)
                                    <div class="flex items-center gap-2">
                                        <span class="relative flex size-2"><span class="absolute inline-flex size-full animate-ping rounded-full bg-info-600 opacity-40"></span><span class="relative size-2 rounded-full bg-info-600"></span></span>
                                        <span class="text-sm">Open {{ $usage->durationLabel() }}</span>
                                    </div>
                                    <p class="text-xs text-sage-500">by {{ $usage->opener->name }}, {{ $usage->opened_at->format('M j g:i A') }}</p>
                                @else
                                    <span class="text-sm text-sage-500">Nothing open</span>
                                @endif
                            </td>
                            <td>
                                @if ($next)
                                    <span class="text-sm {{ $next->isExpired() ? 'font-semibold text-danger-700' : ($next->expiresWithin(7) ? 'font-semibold text-warn-700' : '') }}">
                                        {{ $next->expiry_date->format('M j, Y') }}
                                    </span>
                                    @if ($next->isExpired())<span class="badge-red ml-1">Expired</span>@endif
                                @else
                                    <span class="text-sm text-sage-400">—</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex items-center justify-end gap-1.5">
                                    @if ($usage)
                                        <button type="button" class="btn-secondary btn-sm"
                                                @click="$confirm({ title: @js('Mark '.$item->name.' as used up?'), message: @js('The open '.$item->unit.' will be recorded as fully consumed.'), confirmText: 'Mark depleted' }, () => $wire.markDepleted({{ $usage->id }}))">
                                            <x-icon name="check-circle" class="size-4" /> Mark depleted
                                        </button>
                                    @endif
                                    {{-- Opening a new unit while one is open marks the old one used up (one open unit per item). --}}
                                    <button type="button" class="{{ $usage ? 'btn-secondary' : 'btn-primary' }} btn-sm" @disabled($item->quantity_on_hand <= 0 || ! $item->is_active)
                                            wire:click="startStockOut({{ $item->id }})">
                                        <x-icon name="box-open" class="size-4" /> Stock out
                                    </button>

                                    <div x-data="{ open: false }" class="relative">
                                        <button type="button" class="btn-icon" @click="open = !open" aria-label="More actions for {{ $item->name }}"><x-icon name="more" /></button>
                                        <div x-show="open" x-cloak @click.outside="open = false" x-transition.origin.top.right
                                             class="absolute right-0 z-20 mt-1 w-56 overflow-hidden rounded-xl border border-sage-200 bg-white text-left text-sm shadow-xl">
                                            <button type="button" class="block w-full px-4 py-2.5 text-left hover:bg-sage-50" wire:click="$set('historyId', {{ $item->id }})" @click="open = false">View batches & history</button>
                                            @if ($usage)
                                                <button type="button" class="block w-full px-4 py-2.5 text-left hover:bg-sage-50"
                                                        @click="open = false; $confirm({ title: @js('Discard the open '.$item->unit.'?'), message: 'Use this when an opened unit spoiled or spilled before it was used up.', confirmText: 'Discard', tone: 'warn', input: { label: 'Reason', placeholder: 'e.g. milk went sour', required: true } }, (r) => $wire.discard({{ $usage->id }}, r))">
                                                    Discard open unit…
                                                </button>
                                            @endif
                                            @if ($isManager)
                                                <div class="my-1 border-t border-sage-100"></div>
                                                <button type="button" class="block w-full px-4 py-2.5 text-left hover:bg-sage-50" wire:click="startAdjust({{ $item->id }}, 'adjustment_in')" @click="open = false">Adjust quantity…</button>
                                                <button type="button" class="block w-full px-4 py-2.5 text-left hover:bg-sage-50" wire:click="startAdjust({{ $item->id }}, 'waste')" @click="open = false">Write off waste / expired…</button>
                                                <button type="button" class="block w-full px-4 py-2.5 text-left hover:bg-sage-50" wire:click="edit({{ $item->id }})" @click="open = false">Edit item</button>
                                                <button type="button" class="block w-full px-4 py-2.5 text-left text-danger-700 hover:bg-danger-50"
                                                        @click="open = false; $confirm({ title: @js('Archive '.$item->name.'?'), message: 'It will be hidden from inventory but kept in the database with its full history. You can restore it from Archive.', confirmText: 'Archive item', tone: 'danger' }, () => $wire.archive({{ $item->id }}))">
                                                    Archive item
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5">
                            <x-empty-state icon="package" title="{{ $search || $category || $filter ? 'No items match these filters' : 'No inventory items yet' }}"
                                           description="{{ $search || $category || $filter ? 'Try clearing the filters.' : 'Add the ingredients and supplies you stock, then record a delivery.' }}">
                                @if ($isManager && ! ($search || $category || $filter))
                                    <button type="button" class="btn-primary" wire:click="create"><x-icon name="plus" class="size-4" /> Add item</button>
                                @endif
                            </x-empty-state>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($items->hasPages())<div class="border-t border-sage-200 px-5 py-3">{{ $items->links() }}</div>@endif
    </section>

    {{-- ─────────────── Item detail slide-over ─────────────── --}}
    <div x-data="{ open: @entangle('historyId').live }" x-show="open" x-cloak class="fixed inset-0 z-[60]" @keydown.escape.window="open = null" role="dialog" aria-modal="true">
        <div x-show="open" x-transition.opacity class="absolute inset-0 bg-pine-950/40" @click="open = null"></div>
        <div x-show="open" x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
             class="absolute inset-y-0 right-0 flex w-full max-w-lg flex-col bg-white shadow-2xl">
            @if ($history)
                <div class="flex items-start justify-between border-b border-sage-200 px-6 py-4">
                    <div>
                        <h2 class="font-display text-xl font-semibold">{{ $history->name }}</h2>
                        <p class="text-sm text-sage-500">{{ $history->category->name }} · {{ $history->unit }}{{ $history->defaultSupplier ? ' · '.$history->defaultSupplier->name : '' }}</p>
                    </div>
                    <button type="button" class="btn-icon -mr-2" @click="open = null" aria-label="Close"><x-icon name="x" /></button>
                </div>
                <div class="flex-1 space-y-6 overflow-y-auto px-6 py-5">
                    <div class="grid grid-cols-3 gap-3">
                        <div class="rounded-xl bg-sage-100 p-3"><p class="text-xs text-sage-600">In storage</p><p class="font-display text-xl font-semibold">{{ $history->quantity_on_hand }}</p></div>
                        <div class="rounded-xl bg-sage-100 p-3"><p class="text-xs text-sage-600">Reorder at</p><p class="font-display text-xl font-semibold">{{ $history->reorder_level }}</p></div>
                        @php $closed = $history->usages->where('status', 'depleted')->filter(fn ($u) => $u->closed_at); @endphp
                        <div class="rounded-xl bg-sage-100 p-3" title="Average time an opened unit lasts before it's used up">
                            <p class="text-xs text-sage-600">A unit lasts</p>
                            <p class="font-display text-xl font-semibold">
                                @if ($closed->count())
                                    @php $avg = $closed->avg(fn ($u) => $u->opened_at->diffInMinutes($u->closed_at)); @endphp
                                    {{ $avg >= 1440 ? round($avg / 1440, 1).' days' : round($avg / 60, 1).' hrs' }}
                                @else — @endif
                            </p>
                        </div>
                    </div>

                    <div>
                        <h3 class="mb-2 text-sm font-semibold text-sage-600">Batches in storage (used first-expired, first-out)</h3>
                        <ul class="divide-y divide-sage-100 rounded-xl border border-sage-200">
                            @forelse ($history->batches as $batch)
                                <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                    <div>
                                        <p class="font-semibold">{{ $batch->quantity_remaining }} of {{ $batch->quantity_received }} left <span class="font-normal text-sage-500">· {{ peso($batch->unit_cost) }} each</span></p>
                                        <p class="text-xs text-sage-500">{{ $batch->stockIn ? $batch->stockIn->reference_no.' · '.$batch->stockIn->supplier->name.' · '.$batch->stockIn->received_date->format('M j') : 'Manual adjustment' }}{{ $batch->batch_code ? ' · '.$batch->batch_code : '' }}</p>
                                    </div>
                                    @if ($batch->expiry_date)
                                        <span class="{{ $batch->isExpired() ? 'badge-red' : ($batch->expiresWithin(7) ? 'badge-amber' : 'badge-gray') }}">{{ $batch->isExpired() ? 'Expired ' : 'Exp. ' }}{{ $batch->expiry_date->format('M j') }}</span>
                                    @endif
                                </li>
                            @empty
                                <li class="px-4 py-6 text-center text-sm text-sage-500">No stock in storage.</li>
                            @endforelse
                        </ul>
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-sage-600">Recent use at the bar</h3>
                            <a href="{{ route('inventory.movements', ['item' => $history->id]) }}" wire:navigate class="text-[13px] font-semibold text-leaf-700 hover:underline">Full movement history</a>
                        </div>
                        <ul class="space-y-2">
                            @forelse ($history->usages as $u)
                                <li class="flex items-start justify-between gap-3 rounded-xl border border-sage-200 px-4 py-2.5 text-sm">
                                    <div>
                                        <p>Opened by {{ $u->opener->name }} · {{ $u->opened_at->format('M j, g:i A') }}</p>
                                        @if ($u->closed_at)<p class="text-xs text-sage-500">{{ ucfirst($u->status) }} by {{ $u->closer?->name }} after {{ $u->durationLabel() }}{{ $u->notes ? ' — '.$u->notes : '' }}</p>@endif
                                    </div>
                                    <x-status-badge :status="$u->status" />
                                </li>
                            @empty
                                <li class="text-sm text-sage-500">This item hasn't been opened yet.</li>
                            @endforelse
                        </ul>
                    </div>
                    @if ($history->notes)
                        <div><h3 class="mb-1 text-sm font-semibold text-sage-600">Notes</h3><p class="text-sm">{{ $history->notes }}</p></div>
                    @endif
                </div>
            @else
                <div class="space-y-3 p-6"><div class="skeleton h-7 w-48"></div><div class="skeleton h-4 w-64"></div><div class="skeleton h-24 w-full"></div></div>
            @endif
        </div>
    </div>


    {{-- Stock out (staff & manager): usually 1; more when recording later, e.g. "opened 3 today". --}}
    <x-modal wire:model="showStockOut" :title="$stockingOut ? 'Stock out '.$stockingOut->name : 'Stock out'"
             :description="$stockingOut ? $stockingOut->quantity_on_hand.' '.$stockingOut->unit.' in storage' : null" size="sm" icon="box-open">
        @if ($stockingOut)
            <form id="stockout-form" wire:submit="stockOut" class="space-y-4">
                <x-input label="How many did you take out of storage?" name="stockOutQty" type="number" min="1" max="{{ $stockingOut->quantity_on_hand }}" step="1" inputmode="numeric"
                         wire:model.live.debounce.300ms="stockOutQty" required class="max-w-40" />
                <div>
                    <span class="field-label">{{ (int) $stockOutQty > 1 ? 'The last one you took out' : 'This one' }}</span>
                    <div class="grid grid-cols-2 gap-1 rounded-xl bg-sage-100 p-1" role="radiogroup" aria-label="Is it still in use?">
                        @foreach (['open' => 'In use now', 'empty' => 'Already used up'] as $val => $text)
                            <label class="cursor-pointer">
                                <input type="radio" class="peer sr-only" value="{{ $val }}" wire:model.live="stockOutLast">
                                <span class="block rounded-lg px-2 py-1.5 text-center text-sm font-semibold text-sage-600 transition peer-checked:bg-white peer-checked:text-ink peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-leaf-500">{{ $text }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <p class="rounded-xl bg-sage-100 px-3.5 py-2.5 text-sm text-sage-700">
                    @php $n = max(1, (int) $stockOutQty); @endphp
                    {{ $n }} {{ $stockingOut->unit }} leave{{ $n === 1 ? 's' : '' }} storage ({{ max(0, $stockingOut->quantity_on_hand - $n) }} will remain).
                    @if ($stockingOut->currentUsage) The one open now (since {{ $stockingOut->currentUsage->opened_at->format('M j, g:i A') }}) will be marked used up.@endif
                    @if ($n > 1) Only one is open at a time, so the earlier ones are marked used up.@endif
                </p>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="show = false">Cancel</button>
            <x-submit form="stockout-form" target="stockOut">Stock out</x-submit>
        </x-slot:footer>
    </x-modal>

    @if ($isManager)
        {{-- Item form --}}
        <x-modal wire:model="showForm" :title="$editingId ? 'Edit item' : 'Add inventory item'" description="Items are counted in whole units, e.g. bags, cartons or bottles." size="lg" icon="package">
            <form id="item-form" wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                <x-input label="Item name" name="form.name" wire:model.blur="form.name" required class="sm:col-span-2" placeholder="e.g. Fresh Milk" />
                <x-select label="Category" name="form.item_category_id" wire:model="form.item_category_id" :options="$categories->pluck('name', 'id')" placeholder="Choose a category" required />
                <x-input label="Unit" name="form.unit" wire:model.blur="form.unit" required placeholder="e.g. carton (1 L)" help="What one unit you open looks like." />
                <x-input label="SKU" name="form.sku" wire:model.blur="form.sku" optional placeholder="e.g. DY-MILK" />
                <x-input label="Reorder level" name="form.reorder_level" type="number" min="0" wire:model.blur="form.reorder_level" required help="Alert when units in storage reach this number." />
                <x-select label="Usual supplier" name="form.default_supplier_id" wire:model="form.default_supplier_id" :options="$suppliers" placeholder="None" optional class="sm:col-span-2" />
                <x-textarea label="Notes" name="form.notes" rows="2" wire:model.blur="form.notes" optional class="sm:col-span-2" />
                <div class="space-y-2 sm:col-span-2">
                    <x-toggle label="Track expiry dates" description="Require an expiry date on every delivery and warn before it expires." wire:model="form.track_expiry" />
                    <x-toggle label="Active" description="Inactive items stay visible but can't be stocked out." wire:model="form.is_active" />
                </div>
            </form>
            <x-slot:footer>
                <button type="button" class="btn-secondary" @click="show = false">Cancel</button>
                <x-submit form="item-form" target="save">{{ $editingId ? 'Save changes' : 'Add item' }}</x-submit>
            </x-slot:footer>
        </x-modal>

        {{-- Adjust --}}
        <x-modal wire:model="showAdjust" title="Adjust stock" :description="$adjusting ? $adjusting->name.' · '.$adjusting->quantity_on_hand.' '.$adjusting->unit.' in storage' : null" icon="sliders">
            <form id="adjust-form" wire:submit="adjust" class="space-y-4">
                <fieldset>
                    <legend class="field-label">What happened?</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ([
                            'adjustment_in' => ['Found more (+)', 'Physical count is higher'],
                            'adjustment_out' => ['Count correction (−)', 'Physical count is lower'],
                            'waste' => ['Damaged / spoiled (−)', 'Unopened unit can\'t be used'],
                            'expired' => ['Expired (−)', 'Write off expired units'],
                        ] as $type => [$title, $desc])
                            <label class="cursor-pointer rounded-xl border-2 p-3 transition {{ $adjustType === $type ? 'border-leaf-600 bg-leaf-50' : 'border-sage-200 hover:border-sage-300' }}">
                                <input type="radio" class="sr-only" wire:model.live="adjustType" value="{{ $type }}">
                                <span class="block text-sm font-semibold">{{ $title }}</span>
                                <span class="block text-xs text-sage-500">{{ $desc }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-input label="Quantity" name="adjustQty" type="number" min="1" wire:model="adjustQty" required :suffix="$adjusting?->unit ? \Illuminate\Support\Str::before($adjusting->unit, ' ') : null" />
                    @if ($adjustType !== 'adjustment_in' && $adjusting)
                        <x-select label="From batch" name="adjustBatch" wire:model="adjustBatch" placeholder="Oldest first (automatic)" optional>
                            @foreach ($adjusting->availableBatches as $b)
                                <option value="{{ $b->id }}">{{ $b->quantity_remaining }} left{{ $b->expiry_date ? ' · exp '.$b->expiry_date->format('M j') : '' }}{{ $b->batch_code ? ' · '.$b->batch_code : '' }}</option>
                            @endforeach
                        </x-select>
                    @endif
                </div>
                <x-textarea label="Reason" name="adjustReason" rows="2" wire:model="adjustReason" required placeholder="Recorded in the movement ledger and audit log" />
            </form>
            <x-slot:footer>
                <button type="button" class="btn-secondary" @click="show = false">Cancel</button>
                <x-submit form="adjust-form" target="adjust" :variant="$adjustType === 'adjustment_in' ? 'primary' : 'warn'">Save adjustment</x-submit>
            </x-slot:footer>
        </x-modal>

        {{-- Categories --}}
        <x-modal wire:model="showCategories" title="Item categories" description="Group ingredients and supplies so they're easier to find." icon="layers">
            <form wire:submit="addCategory" class="flex items-start gap-2">
                <x-input name="newCategory" wire:model="newCategory" placeholder="New category name" class="flex-1" aria-label="New category name" />
                <x-submit target="addCategory" class="mt-0">Add</x-submit>
            </form>
            <ul class="mt-4 divide-y divide-sage-100 rounded-xl border border-sage-200">
                @foreach ($categories as $c)
                    <li class="flex items-center gap-3 px-4 py-2.5" wire:key="ic-{{ $c->id }}">
                        @if ($renamingId === $c->id)
                            <form wire:submit="renameCategory" class="flex flex-1 items-start gap-2">
                                <x-input name="renameValue" wire:model="renameValue" class="flex-1" aria-label="Category name" autofocus />
                                <button type="submit" class="btn-primary btn-sm mt-1">Save</button>
                                <button type="button" class="btn-ghost btn-sm mt-1" wire:click="$set('renamingId', null)">Cancel</button>
                            </form>
                        @else
                            <span class="flex-1 text-sm font-semibold">{{ $c->name }}</span>
                            <span class="text-xs text-sage-500">{{ $c->items_count }} item(s)</span>
                            <button type="button" class="btn-icon" wire:click="$set('renamingId', {{ $c->id }}); $set('renameValue', @js($c->name))" aria-label="Rename {{ $c->name }}"><x-icon name="pencil" class="size-4" /></button>
                            <button type="button" class="btn-icon hover:text-danger-700" aria-label="Archive {{ $c->name }}"
                                    @click="$confirm({ title: @js('Archive “'.$c->name.'”?'), message: 'Only empty categories can be archived.', confirmText: 'Archive', tone: 'danger' }, () => $wire.archiveCategory({{ $c->id }}))"><x-icon name="archive" class="size-4" /></button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-modal>
    @endif
</div>
