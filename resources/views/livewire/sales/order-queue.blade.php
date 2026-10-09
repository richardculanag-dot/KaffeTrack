<div class="space-y-8">
    {{-- ─────────────── Live board ─────────────── --}}
    <div wire:poll.10s.visible class="grid gap-6 xl:grid-cols-2">
        @foreach (['pending' => ['Being prepared', 'warn', $pending, 'hourglass'], 'ready' => ['Ready for pickup', 'info', $ready, 'bell']] as $key => [$label, $tone, $orders, $icon])
            <section class="flex flex-col rounded-[var(--radius-panel)] border border-sage-200 bg-sage-100/60">
                <header class="flex items-center gap-2.5 px-5 py-4">
                    <span class="grid size-8 place-items-center rounded-lg {{ $tone === 'warn' ? 'bg-warn-50 text-warn-700' : 'bg-info-50 text-info-600' }}"><x-icon :name="$icon" class="size-[18px]" /></span>
                    <h2 class="font-display text-lg font-semibold">{{ $label }}</h2>
                    <span class="rounded-full bg-white px-2.5 py-0.5 text-sm font-semibold text-sage-700">{{ $orders->count() }}</span>
                    @if ($key === 'pending')
                        <span class="ml-auto text-[13px] text-sage-500">Oldest first · refreshes every 10 s</span>
                    @endif
                </header>

                <div class="grid gap-3 px-3 pb-3 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                    @forelse ($orders as $order)
                        <article wire:key="q-{{ $order->id }}" class="flex flex-col rounded-xl border border-sage-200 bg-white p-4 shadow-[0_1px_0_rgba(26,37,32,.04)]"
                                 x-data="{ mins: 0, tick() { this.mins = Math.floor((Date.now() - {{ $order->created_at->getTimestampMs() }}) / 60000) } }"
                                 x-init="tick(); setInterval(() => tick(), 30000)">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="font-display text-3xl font-bold leading-none text-crema-700">#{{ $order->queue_number }}</p>
                                    <p class="mt-1 text-[13px] text-sage-600">
                                        {{ \App\Models\Order::TYPES[$order->order_type] }}{{ $order->table_number ? ' · Table '.$order->table_number : '' }}{{ $order->customer_name ? ' · '.$order->customer_name : '' }}
                                    </p>
                                </div>
                                {{-- Elapsed time turns amber at 8 min and red at 15 min so slow orders stand out --}}
                                <span class="badge" :class="mins >= 15 ? 'badge-red' : (mins >= 8 ? 'badge-amber' : 'badge-gray')">
                                    <x-icon name="clock" class="size-3.5" /><span x-text="mins < 1 ? 'just now' : mins + ' min'"></span>
                                </span>
                            </div>

                            <ul class="mt-3 space-y-1.5 border-t border-dashed border-sage-200 pt-3 text-sm">
                                @foreach ($order->items as $item)
                                    <li>
                                        <span class="font-semibold">{{ $item->quantity }}×</span> {{ $item->product_name }}
                                        <span class="text-sage-500">· {{ $item->variant_name }}</span>
                                        @if ($item->addons->isNotEmpty())<span class="block pl-5 text-[13px] text-sage-500">+ {{ $item->addons->pluck('addon_name')->join(', ') }}</span>@endif
                                        @if ($item->notes)<span class="block pl-5 text-[13px] italic text-crema-700">“{{ $item->notes }}”</span>@endif
                                    </li>
                                @endforeach
                            </ul>

                            <div class="mt-auto flex flex-wrap items-center justify-between gap-2 pt-4">
                                @if ($order->isPaid())
                                    <span class="badge-green"><x-icon name="check" class="size-3" stroke="3" />Paid</span>
                                @else
                                    <span class="badge-amber">Unpaid · {{ peso($order->total_amount) }}</span>
                                @endif
                                <div class="flex items-center gap-1">
                                    <div x-data="{ open: false }" class="relative">
                                        <button type="button" class="btn-icon" @click="open = !open" aria-label="More actions for order {{ $order->queue_number }}"><x-icon name="more" /></button>
                                        <div x-show="open" x-cloak @click.outside="open = false" x-transition.origin.bottom.right
                                             class="absolute bottom-full right-0 z-10 mb-1 w-48 overflow-hidden rounded-xl border border-sage-200 bg-white text-sm shadow-xl">
                                            <button type="button" class="block w-full px-4 py-2.5 text-left hover:bg-sage-50" wire:click="view({{ $order->id }})" @click="open = false">View details</button>
                                            <a href="{{ route('orders.receipt', $order) }}" target="_blank" class="block px-4 py-2.5 hover:bg-sage-50">Print receipt</a>
                                            @if ($order->canCancel())
                                                <button type="button" class="block w-full px-4 py-2.5 text-left text-danger-700 hover:bg-danger-50"
                                                        @click="open = false; $confirm({ title: 'Cancel order #{{ $order->queue_number }}?', message: 'The order will be removed from the queue. This cannot be undone.', confirmText: 'Cancel order', cancelText: 'Keep order', tone: 'danger', input: { label: 'Reason', placeholder: 'e.g. customer changed their mind', required: true } }, (reason) => $wire.cancel({{ $order->id }}, reason))">
                                                    Cancel order
                                                </button>
                                            @elseif ($order->isPaid())
                                                <button type="button" class="block w-full px-4 py-2.5 text-left text-danger-700 hover:bg-danger-50" wire:click="openRefund({{ $order->id }})" @click="open = false">Refund…</button>
                                            @endif
                                        </div>
                                    </div>
                                    @if ($key === 'pending')
                                        <button type="button" class="btn-primary btn-sm" wire:click="markReady({{ $order->id }})" wire:loading.attr="disabled" wire:target="markReady({{ $order->id }})">
                                            <x-icon name="check" class="size-4" /> Mark ready
                                        </button>
                                    @else
                                        <button type="button" class="{{ $order->isPaid() ? 'btn-primary' : 'btn-warn' }} btn-sm" wire:click="complete({{ $order->id }})" wire:loading.attr="disabled" wire:target="complete({{ $order->id }})">
                                            @if ($order->isPaid())<x-icon name="check-circle" class="size-4" /> Hand over @else<x-icon name="banknote" class="size-4" /> Collect payment @endif
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-xl border border-dashed border-sage-300 px-4 py-10 text-center text-sm text-sage-500 sm:col-span-2 xl:col-span-1 2xl:col-span-2">
                            {{ $key === 'pending' ? 'No orders being prepared. New orders from the POS appear here.' : 'Nothing waiting for pickup.' }}
                        </div>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>

    {{-- ─────────────── History ─────────────── --}}
    <section class="panel overflow-hidden">
        <div class="panel-header flex-wrap">
            <h2 class="panel-title">All orders</h2>
            <div class="flex flex-wrap items-center gap-2">
                <x-search-input wire:model.live.debounce.350ms="search" placeholder="Order no., queue # or name" />
                <input type="date" wire:model.live="date" max="{{ today()->toDateString() }}" class="field w-auto py-2" aria-label="Date">
                <select wire:model.live="status" class="field w-auto py-2 pr-8" aria-label="Status">
                    <option value="">All statuses</option>
                    @foreach (\App\Models\Order::STATUSES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                </select>
            </div>
        </div>
        <div class="relative overflow-x-auto">
            <div wire:loading.flex wire:target="search, date, status, gotoPage, nextPage, previousPage" class="absolute inset-0 z-10 items-center justify-center bg-white/60"><x-spinner class="size-6 text-leaf-600" /></div>
            <table class="table">
                <thead><tr><th>Queue</th><th>Order no.</th><th>Time</th><th>Customer</th><th>Cashier</th><th>Payment</th><th>Status</th><th class="text-right">Total</th><th></th></tr></thead>
                <tbody>
                    @forelse ($history as $order)
                        <tr wire:key="h-{{ $order->id }}" class="cursor-pointer" wire:click="view({{ $order->id }})">
                            <td class="font-display text-lg font-semibold text-crema-700">#{{ $order->queue_number }}</td>
                            <td class="text-sage-600">{{ $order->order_number }}</td>
                            <td class="whitespace-nowrap">{{ $order->created_at->format('g:i A') }}</td>
                            <td>{{ $order->customer_name ?: '—' }}</td>
                            <td>{{ $order->cashier->name }}</td>
                            <td>{{ $order->payment ? $order->payment->methodLabel().($order->payment->provider ? ' · '.$order->payment->provider : '') : '—' }}</td>
                            <td><x-status-badge :status="$order->status" /></td>
                            <td class="text-right font-semibold tabular">{{ peso($order->total_amount) }}</td>
                            <td class="text-right"><x-icon name="chevron-right" class="size-4 text-sage-400" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><x-empty-state icon="orders" title="No orders found" description="Try another date or clear the filters." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($history->hasPages())
            <div class="border-t border-sage-200 px-5 py-3">{{ $history->links() }}</div>
        @endif
    </section>

    {{-- ─────────────── Order details slide-over ─────────────── --}}
    <div x-data="{ open: @entangle('viewingId').live }" x-show="open" x-cloak class="fixed inset-0 z-[60]" @keydown.escape.window="open = null" role="dialog" aria-modal="true">
        <div x-show="open" x-transition.opacity class="absolute inset-0 bg-pine-950/40" @click="open = null"></div>
        <div x-show="open" x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
             class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col bg-white shadow-2xl">
            @if ($viewing)
                <div class="flex items-start justify-between border-b border-sage-200 px-6 py-4">
                    <div>
                        <p class="font-display text-3xl font-bold text-crema-700">#{{ $viewing->queue_number }}</p>
                        <p class="text-sm text-sage-500">{{ $viewing->order_number }} · {{ $viewing->created_at->format('M j, Y g:i A') }}</p>
                    </div>
                    <button type="button" class="btn-icon -mr-2" @click="open = null" aria-label="Close"><x-icon name="x" /></button>
                </div>
                <div class="flex-1 space-y-6 overflow-y-auto px-6 py-5">
                    <div class="flex flex-wrap gap-2">
                        <x-status-badge :status="$viewing->status" />
                        <x-status-badge :status="$viewing->payment_status" />
                        <span class="badge-gray">{{ \App\Models\Order::TYPES[$viewing->order_type] }}{{ $viewing->table_number ? ' · Table '.$viewing->table_number : '' }}</span>
                    </div>

                    <div>
                        <h3 class="mb-2 text-sm font-semibold text-sage-600">Items</h3>
                        <ul class="divide-y divide-sage-100 rounded-xl border border-sage-200">
                            @foreach ($viewing->items as $item)
                                <li class="flex justify-between gap-3 px-4 py-3 text-sm">
                                    <div>
                                        <p class="font-semibold">{{ $item->quantity }}× {{ $item->product_name }}</p>
                                        <p class="text-sage-500">{{ $item->variant_name }} @ {{ peso($item->unit_price) }}@if ($item->addons->isNotEmpty()) · + {{ $item->addons->pluck('addon_name')->join(', ') }}@endif</p>
                                        @if ($item->notes)<p class="italic text-crema-700">“{{ $item->notes }}”</p>@endif
                                    </div>
                                    <p class="font-semibold tabular">{{ peso($item->line_total) }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between text-sage-600"><dt>Subtotal</dt><dd class="tabular">{{ peso($viewing->subtotal) }}</dd></div>
                        @if ((float) $viewing->vat_exempt_sales > 0)<div class="flex justify-between text-sage-600"><dt>Less: VAT</dt><dd class="tabular">−{{ peso($viewing->subtotal - $viewing->vat_exempt_sales) }}</dd></div>@endif
                        @if ((float) $viewing->discount_amount > 0)<div class="flex justify-between text-leaf-700"><dt>{{ $viewing->discount_name }} discount{{ $viewing->discount_id_number ? ' (ID '.$viewing->discount_id_number.')' : '' }}</dt><dd class="tabular">−{{ peso($viewing->discount_amount) }}</dd></div>@endif
                        <div class="flex justify-between border-t border-sage-200 pt-2 font-display text-lg font-semibold"><dt>Total</dt><dd class="tabular">{{ peso($viewing->total_amount) }}</dd></div>
                    </dl>

                    <div>
                        <h3 class="mb-3 text-sm font-semibold text-sage-600">Timeline</h3>
                        @php
                            $events = collect([
                                ['Placed by '.$viewing->cashier->name, $viewing->created_at, 'leaf'],
                                $viewing->payment ? ['Paid by '.$viewing->payment->methodLabel().($viewing->payment->provider ? ' ('.$viewing->payment->provider.')' : '').($viewing->payment->reference_number ? ' · Ref '.$viewing->payment->reference_number : '').($viewing->payment->method === 'cash' ? ' · change '.peso($viewing->payment->change_amount) : ''), $viewing->payment->created_at, 'leaf'] : null,
                                $viewing->ready_at ? ['Marked ready', $viewing->ready_at, 'info'] : null,
                                $viewing->completed_at && $viewing->status !== 'cancelled' ? ['Handed to customer', $viewing->completed_at, 'leaf'] : null,
                                $viewing->cancelled_at ? ['Cancelled: '.$viewing->cancel_reason, $viewing->cancelled_at, 'danger'] : null,
                                $viewing->refund ? ['Refunded '.peso($viewing->refund->amount).' — approved by '.$viewing->refund->approver->name.': '.$viewing->refund->reason, $viewing->refund->created_at, 'danger'] : null,
                            ])->filter()->sortBy(fn ($e) => $e[1]);
                        @endphp
                        <ol class="relative space-y-4 border-l-2 border-sage-200 pl-5">
                            @foreach ($events as [$text, $at, $tone])
                                <li class="relative">
                                    <span class="absolute -left-[27px] top-1 size-3 rounded-full ring-4 ring-white {{ $tone === 'danger' ? 'bg-danger-600' : ($tone === 'info' ? 'bg-info-600' : 'bg-leaf-500') }}"></span>
                                    <p class="text-sm">{{ $text }}</p>
                                    <p class="text-xs text-sage-500">{{ $at->format('M j, g:i A') }}</p>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                </div>
                <div class="flex gap-2 border-t border-sage-200 bg-sage-50/60 px-6 py-4">
                    <a href="{{ route('orders.receipt', $viewing) }}" target="_blank" class="btn-secondary flex-1"><x-icon name="printer" class="size-4" /> Receipt</a>
                    @if ($viewing->canRefund())
                        <button type="button" class="btn-danger flex-1" wire:click="openRefund({{ $viewing->id }})"><x-icon name="undo" class="size-4" /> Refund</button>
                    @endif
                </div>
            @else
                <div class="p-6"><div class="skeleton h-8 w-24"></div><div class="skeleton mt-3 h-4 w-48"></div></div>
            @endif
        </div>
    </div>

    {{-- ─────────────── Collect payment ─────────────── --}}
    <x-modal wire:model="showPayment" title="Collect payment" :description="$paying ? 'Order #'.$paying->queue_number.' · '.peso($paying->total_amount).' due' : null" icon="banknote">
        <form id="collect-form" wire:submit="collectPayment" class="space-y-4">
            <div class="tabs w-full" role="radiogroup" aria-label="Payment method">
                @foreach (\App\Models\Payment::METHODS as $m => $label)
                    <button type="button" role="radio" class="tab flex-1" aria-selected="{{ $payMethod === $m ? 'true' : 'false' }}" wire:click="$set('payMethod', '{{ $m }}')">{{ $label }}</button>
                @endforeach
            </div>
            @if ($payMethod === 'cash')
                <x-input label="Cash received" name="payTendered" type="number" step="0.01" min="0" prefix="₱" wire:model.live.debounce.300ms="payTendered" required />
                @if ($paying && is_numeric($payTendered) && $payTendered >= $paying->total_amount)
                    <p class="rounded-xl bg-leaf-50 px-4 py-3 text-sm">Change: <span class="font-display text-lg font-bold text-leaf-800">{{ peso($payTendered - $paying->total_amount) }}</span></p>
                @endif
            @else
                <div class="grid grid-cols-5 gap-3">
                    <x-select label="Provider" name="payProvider" wire:model="payProvider" :options="array_combine(\App\Models\Payment::PROVIDERS[$payMethod], \App\Models\Payment::PROVIDERS[$payMethod])" class="col-span-2" />
                    <x-input label="Reference no." name="payReference" wire:model="payReference" required class="col-span-3" autocomplete="off" />
                </div>
            @endif
        </form>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="show = false">Cancel</button>
            <x-submit form="collect-form" target="collectPayment">Record payment</x-submit>
        </x-slot:footer>
    </x-modal>

    {{-- ─────────────── Refund ─────────────── --}}
    <x-modal wire:model="showRefund" title="Refund order" :description="$refunding ? 'Order #'.$refunding->queue_number.' · '.peso($refunding->total_amount).' via '.($refunding->payment?->methodLabel() ?? '—') : null" icon="undo">
        <form id="refund-form" wire:submit="refund" class="space-y-4">
            <div class="flex gap-3 rounded-xl border border-warn-600/25 bg-warn-50 p-3.5 text-sm text-warn-700">
                <x-icon name="lock" class="size-5" />
                <p>Refunds return the full amount and need a manager's approval. The refund is recorded in the audit log under both names.</p>
            </div>
            <x-select label="Approving manager" name="refundManagerId" wire:model="refundManagerId" :options="$managers" required />
            <x-input label="Manager PIN" name="refundPin" type="password" inputmode="numeric" maxlength="6" autocomplete="off" wire:model="refundPin" required />
            <x-textarea label="Reason for refund" name="refundReason" rows="2" wire:model="refundReason" placeholder="e.g. wrong drink served" required />
        </form>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="show = false">Keep order</button>
            <x-submit form="refund-form" target="refund" variant="danger">Approve refund</x-submit>
        </x-slot:footer>
    </x-modal>
</div>
