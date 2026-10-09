<div class="space-y-6">
    <x-page-header description="Every change to stock is written here and can't be edited. Use it to trace where any unit went." />

    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        @foreach (\App\Models\StockMovement::TYPES as $key => $label)
            <button type="button" wire:click="$set('type', '{{ $type === $key ? '' : $key }}')" aria-pressed="{{ $type === $key ? 'true' : 'false' }}"
                    class="panel p-4 text-left transition hover:border-sage-300 {{ $type === $key ? 'ring-2 ring-leaf-500 border-leaf-500' : '' }}">
                <span class="block font-display text-xl font-semibold tabular {{ in_array($key, ['stock_in', 'adjustment_in']) ? 'text-leaf-700' : (in_array($key, ['waste', 'expired']) ? 'text-danger-700' : '') }}">{{ number_format($totals[$key] ?? 0) }}</span>
                <span class="block text-[13px] text-sage-600">{{ $label }} <span class="text-sage-400">units</span></span>
            </button>
        @endforeach
    </div>

    <section class="panel overflow-hidden">
        <div class="panel-header flex-wrap">
            <div class="tabs" role="tablist">
                <button type="button" role="tab" class="tab" aria-selected="{{ $tab === 'ledger' ? 'true' : 'false' }}" wire:click="$set('tab', 'ledger')">Movement ledger</button>
                <button type="button" role="tab" class="tab" aria-selected="{{ $tab === 'usage' ? 'true' : 'false' }}" wire:click="$set('tab', 'usage')">Bar usage log</button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <select wire:model.live="item" class="field w-auto py-2 pr-8" aria-label="Item">
                    <option value="">All items</option>
                    @foreach ($items as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <select wire:model.live="user" class="field w-auto py-2 pr-8" aria-label="Staff">
                    <option value="">Everyone</option>
                    @foreach ($users as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <input type="date" wire:model.live="from" max="{{ $to }}" class="field w-auto py-2" aria-label="From date">
                <span class="text-sage-400">to</span>
                <input type="date" wire:model.live="to" min="{{ $from }}" max="{{ today()->toDateString() }}" class="field w-auto py-2" aria-label="To date">
                @if ($item || $type || $user)
                    <button type="button" class="btn-ghost btn-sm" wire:click="clearFilters">Clear</button>
                @endif
            </div>
        </div>

        <div class="relative overflow-x-auto">
            <div wire:loading.flex class="absolute inset-0 z-10 items-center justify-center bg-white/60"><x-spinner class="size-6 text-leaf-600" /></div>
            @if ($tab === 'ledger')
                <table class="table">
                    <thead><tr><th>When</th><th>Item</th><th>Movement</th><th class="text-right">Qty</th><th class="text-right">Balance</th><th>By</th><th>Reason / reference</th></tr></thead>
                    <tbody>
                        @forelse ($movements as $m)
                            <tr wire:key="m-{{ $m->id }}">
                                <td class="whitespace-nowrap">{{ $m->created_at->format('M j, g:i A') }}</td>
                                <td><span class="font-semibold">{{ $m->item->name }}</span>@if ($m->batch?->expiry_date)<span class="block text-xs text-sage-500">batch exp. {{ $m->batch->expiry_date->format('M j') }}</span>@endif</td>
                                <td>
                                    <span class="{{ match ($m->type) { 'stock_in', 'adjustment_in' => 'badge-green', 'stock_out' => 'badge-blue', 'waste', 'expired' => 'badge-red', default => 'badge-amber' } }}">{{ $m->typeLabel() }}</span>
                                </td>
                                <td class="text-right font-semibold tabular {{ $m->quantity > 0 ? 'text-leaf-700' : 'text-danger-700' }}">{{ $m->quantity > 0 ? '+' : '' }}{{ $m->quantity }}</td>
                                <td class="text-right tabular">{{ $m->balance_after }}</td>
                                <td>{{ $m->user->name }}</td>
                                <td class="max-w-xs text-sage-600">{{ $m->reason ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><x-empty-state icon="arrows" title="No movements in this period" description="Widen the date range or clear the filters." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            @else
                <table class="table">
                    <thead><tr><th>Item</th><th>Opened</th><th>Closed</th><th>Lasted</th><th>Status</th><th>Notes</th></tr></thead>
                    <tbody>
                        @forelse ($usages as $u)
                            <tr wire:key="u-{{ $u->id }}">
                                <td class="font-semibold">{{ $u->item->name }}</td>
                                <td class="whitespace-nowrap">{{ $u->opened_at->format('M j, g:i A') }}<span class="block text-xs text-sage-500">{{ $u->opener->name }}</span></td>
                                <td class="whitespace-nowrap">@if ($u->closed_at){{ $u->closed_at->format('M j, g:i A') }}<span class="block text-xs text-sage-500">{{ $u->closer?->name }}</span>@else<span class="text-sage-400">—</span>@endif</td>
                                <td class="tabular">{{ $u->durationLabel() }}</td>
                                <td><x-status-badge :status="$u->status" /></td>
                                <td class="max-w-xs text-sage-600">{{ $u->notes ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-empty-state icon="box-open" title="No units opened in this period" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            @endif
        </div>
        @php $pager = $tab === 'ledger' ? $movements : $usages; @endphp
        @if ($pager->hasPages())<div class="border-t border-sage-200 px-5 py-3">{{ $pager->links() }}</div>@endif
    </section>
</div>
