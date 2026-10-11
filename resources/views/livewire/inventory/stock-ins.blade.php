<div class="space-y-6">
    <x-page-header description="Deliveries received from suppliers. Each one adds stock in batches and, unless you turn it off, records the purchase as an expense in Finance.">
        <x-slot:actions>
            <a href="{{ route('inventory.stock-ins.create') }}" wire:navigate class="btn-primary"><x-icon name="plus" class="size-4" /> Receive stock</a>
        </x-slot:actions>
    </x-page-header>

    <section class="panel overflow-hidden">
        <div class="panel-header flex-wrap">
            <div class="flex flex-wrap items-center gap-2">
                <x-search-input wire:model.live.debounce.300ms="search" placeholder="Reference or invoice no." />
                <select wire:model.live="supplier" class="field w-auto py-2 pr-8" aria-label="Supplier">
                    <option value="">All suppliers</option>
                    @foreach ($suppliers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <input type="month" wire:model.live="month" class="field w-auto py-2" aria-label="Month">
            </div>
            <p class="text-sm text-sage-600">Total purchases: <span class="font-display text-lg font-semibold text-ink tabular">{{ peso($total) }}</span></p>
        </div>
        <div class="relative overflow-x-auto">
            <div wire:loading.flex class="absolute inset-0 z-10 items-center justify-center bg-white/60"><x-spinner class="size-6 text-leaf-600" /></div>
            <table class="table">
                <thead><tr><th>Reference</th><th>Received</th><th>Supplier</th><th>Invoice</th><th>Lines</th><th>Received by</th><th class="text-right">Total cost</th><th></th></tr></thead>
                <tbody>
                    @forelse ($stockIns as $si)
                        <tr wire:key="si-{{ $si->id }}" class="cursor-pointer" wire:click="$set('viewingId', {{ $si->id }})">
                            <td class="font-semibold">{{ $si->reference_no }}</td>
                            <td class="whitespace-nowrap">{{ $si->received_date->format('M j, Y') }}</td>
                            <td>{{ $si->supplier->name }}</td>
                            <td class="text-sage-600">{{ $si->invoice_number ?: '—' }}</td>
                            <td>{{ $si->batches_count }}</td>
                            <td>{{ $si->receiver->name }}</td>
                            <td class="text-right font-semibold tabular">{{ peso($si->total_cost) }}</td>
                            <td class="text-right"><x-icon name="chevron-right" class="size-4 text-sage-400" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="8">
                            <x-empty-state icon="truck" title="No deliveries recorded" description="When a supplier delivers, record it here so stock and expenses stay accurate.">
                                <a href="{{ route('inventory.stock-ins.create') }}" wire:navigate class="btn-primary"><x-icon name="plus" class="size-4" /> Receive stock</a>
                            </x-empty-state>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($stockIns->hasPages())<div class="border-t border-sage-200 px-5 py-3">{{ $stockIns->links() }}</div>@endif
    </section>

    <x-modal wire:model="viewingId" :title="$viewing?->reference_no ?? 'Delivery'" :description="$viewing ? $viewing->supplier->name.' · received '.$viewing->received_date->format('M j, Y').' by '.$viewing->receiver->name : null" size="lg" icon="truck">
        @if ($viewing)
            <div class="overflow-x-auto rounded-xl border border-sage-200">
                <table class="table">
                    <thead><tr><th>Item</th><th class="text-right">Qty</th><th class="text-right">Unit cost</th><th>Expiry</th><th class="text-right">Line total</th></tr></thead>
                    <tbody>
                        @foreach ($viewing->batches as $b)
                            <tr>
                                <td><span class="font-semibold">{{ $b->item->name }}</span><span class="block text-xs text-sage-500">{{ $b->item->unit }}{{ $b->batch_code ? ' · '.$b->batch_code : '' }} · {{ $b->quantity_remaining }} still in storage</span></td>
                                <td class="text-right tabular">{{ $b->quantity_received }}</td>
                                <td class="text-right tabular">{{ peso($b->unit_cost) }}</td>
                                <td>{{ $b->expiry_date?->format('M j, Y') ?? '—' }}</td>
                                <td class="text-right font-semibold tabular">{{ peso($b->quantity_received * $b->unit_cost) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
                <p class="text-sage-600">
                    Invoice {{ $viewing->invoice_number ?: '—' }} ·
                    @if ($viewing->expense)
                        <span class="text-leaf-700">Recorded in Finance as an expense</span>
                    @else
                        <span>Not recorded as an expense</span>
                    @endif
                </p>
                <p class="font-display text-xl font-semibold">Total {{ peso($viewing->total_cost) }}</p>
            </div>
            @if ($viewing->notes)<p class="mt-3 rounded-xl bg-sage-100 p-3 text-sm">{{ $viewing->notes }}</p>@endif
        @endif
    </x-modal>
</div>
