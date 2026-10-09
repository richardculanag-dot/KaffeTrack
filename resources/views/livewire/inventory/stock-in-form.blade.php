<form wire:submit="save" class="mx-auto max-w-6xl space-y-6">
    <a href="{{ route('inventory.stock-ins') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-semibold text-sage-600 hover:text-ink">
        <x-icon name="chevron-left" class="size-4" /> Back to deliveries
    </a>

    <section class="panel">
        <div class="panel-header"><h2 class="panel-title">Delivery details</h2></div>
        <div class="grid gap-4 p-5 md:grid-cols-4">
            <x-select label="Supplier" name="supplierId" wire:model.live="supplierId" :options="$suppliers" placeholder="Choose a supplier" required class="md:col-span-2" />
            <x-input label="Date received" name="receivedDate" type="date" max="{{ today()->toDateString() }}" wire:model.blur="receivedDate" required />
            <x-input label="Supplier invoice no." name="invoiceNumber" wire:model.blur="invoiceNumber" optional />
        </div>
    </section>

    <section class="panel">
        <div class="panel-header flex-wrap">
            <div>
                <h2 class="panel-title">Items received</h2>
                <p class="text-[13px] text-sage-500">Expiry is required for items that track it. Quantities are in each item's unit.</p>
            </div>
            <button type="button" class="btn-secondary btn-sm" wire:click="addLowStock" @disabled(! $supplierId) title="{{ $supplierId ? '' : 'Choose a supplier first' }}">
                <x-icon name="alert" class="size-4" /> Add this supplier's low-stock items
            </button>
        </div>

        <div class="divide-y divide-sage-100">
            @foreach ($lines as $i => $line)
                @php $item = $line['item_id'] ? ($itemsById[(int) $line['item_id']] ?? null) : null; @endphp
                <div wire:key="line-{{ $i }}" class="grid items-start gap-3 px-5 py-4 md:grid-cols-12">
                    <div class="md:col-span-4">
                        <x-select :label="$i === 0 ? 'Item' : null" name="lines.{{ $i }}.item_id" wire:model.live="lines.{{ $i }}.item_id" placeholder="Choose an item" aria-label="Item" required>
                            @foreach ($items as $opt)
                                <option value="{{ $opt->id }}">{{ $opt->name }} — {{ $opt->unit }}</option>
                            @endforeach
                        </x-select>
                        @if ($item)
                            <p class="mt-1 text-xs text-sage-500">{{ $item->quantity_on_hand }} in storage now{{ $item->isLowStock() ? ' · low stock' : '' }}</p>
                        @endif
                    </div>
                    <x-input :label="$i === 0 ? 'Qty' : null" name="lines.{{ $i }}.quantity" type="number" min="1" wire:model.live.debounce.400ms="lines.{{ $i }}.quantity" class="md:col-span-1" aria-label="Quantity" />
                    <x-input :label="$i === 0 ? 'Unit cost' : null" name="lines.{{ $i }}.unit_cost" type="number" min="0" step="0.01" prefix="₱" wire:model.live.debounce.400ms="lines.{{ $i }}.unit_cost" class="md:col-span-2" aria-label="Unit cost" />
                    <x-input :label="$i === 0 ? 'Expiry date' : null" name="lines.{{ $i }}.expiry_date" type="date" min="{{ $receivedDate }}" wire:model.blur="lines.{{ $i }}.expiry_date" class="md:col-span-2" aria-label="Expiry date"
                             :help="$item && ! $item->track_expiry ? 'Optional for this item' : null" />
                    <x-input :label="$i === 0 ? 'Batch / lot' : null" name="lines.{{ $i }}.batch_code" wire:model.blur="lines.{{ $i }}.batch_code" class="md:col-span-1" aria-label="Batch code" placeholder="—" />
                    <div class="flex items-center justify-between gap-2 md:col-span-2 {{ $i === 0 ? 'md:pt-7' : '' }}">
                        <p class="font-semibold tabular" title="Line total">{{ peso((float) ($line['quantity'] ?: 0) * (float) ($line['unit_cost'] ?: 0)) }}</p>
                        <button type="button" class="btn-icon hover:text-danger-700" wire:click="removeLine({{ $i }})" aria-label="Remove line {{ $i + 1 }}"><x-icon name="trash" class="size-4" /></button>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-sage-200 px-5 py-4">
            <button type="button" class="btn-secondary" wire:click="addLine"><x-icon name="plus" class="size-4" /> Add another item</button>
            <p class="text-sage-600">Delivery total <span class="ml-2 font-display text-2xl font-bold text-ink tabular">{{ peso($this->total) }}</span></p>
        </div>
    </section>

    <section class="panel">
        <div class="grid gap-4 p-5 md:grid-cols-2">
            <div class="space-y-3">
                <x-toggle label="Record as an expense in Finance" description="Adds an “Inventory Purchases” expense for the delivery total." wire:model.live="recordExpense" />
                @if ($recordExpense)
                    <x-select label="Paid by" name="paymentMethod" wire:model="paymentMethod" :options="\App\Models\Expense::PAYMENT_METHODS" required />
                @endif
            </div>
            <x-textarea label="Notes" name="notes" rows="4" wire:model.blur="notes" optional placeholder="e.g. 2 cartons arrived dented — supplier will replace" />
        </div>
    </section>

    @if ($errors->any())
        <div class="flex gap-2 rounded-xl border border-danger-600/25 bg-danger-50 p-4 text-sm text-danger-700" role="alert">
            <x-icon name="alert" class="size-5" /> Please fix the {{ $errors->count() }} highlighted field(s) before saving.
        </div>
    @endif

    <div class="flex justify-end gap-2 pb-8">
        <a href="{{ route('inventory.stock-ins') }}" wire:navigate class="btn-secondary">Cancel</a>
        <x-submit target="save" class="btn-lg"><x-icon name="check" class="size-4" /> Save delivery</x-submit>
    </div>
</form>
