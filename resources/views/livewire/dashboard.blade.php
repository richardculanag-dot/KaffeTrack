<div class="space-y-6">
    {{-- Greeting + period filter --}}
    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <p class="font-display text-2xl font-semibold">
                Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 18 ? 'afternoon' : 'evening') }}, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}.
            </p>
            <p class="mt-1 text-sage-600">Showing <span class="font-semibold text-ink">{{ $rangeLabel }}</span>, compared with {{ $prevLabel }}.</p>
        </div>
        <div class="tabs self-start" role="tablist" aria-label="Reporting period">
            @foreach (\App\Livewire\Dashboard::PERIODS as $key => $label)
                <button type="button" role="tab" class="tab" aria-selected="{{ $period === $key ? 'true' : 'false' }}" wire:click="$set('period', '{{ $key }}')">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- KPI row --}}
    <div class="relative grid gap-4 sm:grid-cols-2 xl:grid-cols-4" wire:loading.class="opacity-60" wire:target="period">
        <div class="rounded-[var(--radius-panel)] bg-pine-900 p-5 text-white sm:col-span-2 xl:col-span-1">
            <p class="text-sm font-medium text-leaf-200/80">Net sales</p>
            <p class="mt-2 font-display text-[2.1rem] font-semibold leading-none tabular">{{ peso($now['sales']) }}</p>
            <x-delta class="mt-3" :current="$now['sales']" :previous="$before['sales']" label="previous period" dark />
        </div>

        <div class="panel p-5">
            <p class="text-sm font-medium text-sage-600">Transactions</p>
            <p class="mt-2 font-display text-[1.8rem] font-semibold leading-none tabular">{{ number_format($now['orders']) }}</p>
            <p class="mt-1 text-[13px] text-sage-500">{{ number_format($now['items']) }} items sold</p>
            <x-delta class="mt-2" :current="$now['orders']" :previous="$before['orders']" />
        </div>

        <div class="panel p-5">
            <p class="text-sm font-medium text-sage-600">Average ticket</p>
            <p class="mt-2 font-display text-[1.8rem] font-semibold leading-none tabular">{{ peso($now['avg_ticket']) }}</p>
            <p class="mt-1 text-[13px] text-sage-500">Per paid order</p>
            <x-delta class="mt-2" :current="$now['avg_ticket']" :previous="$before['avg_ticket']" />
        </div>

        <div class="panel p-5">
            <p class="text-sm font-medium text-sage-600">Net income</p>
            <p class="mt-2 font-display text-[1.8rem] font-semibold leading-none tabular {{ $now['net_income'] < 0 ? 'text-danger-700' : '' }}">{{ peso($now['net_income']) }}</p>
            <p class="mt-1 text-[13px] text-sage-500">Expenses {{ peso($now['expenses']) }}</p>
            <x-delta class="mt-2" :current="$now['net_income']" :previous="$before['net_income']" />
        </div>
    </div>

    {{-- Trend + right now --}}
    <div class="grid gap-6 xl:grid-cols-3">
        <section class="panel xl:col-span-2">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title">{{ $period === 'today' ? 'Sales by hour' : 'Daily sales' }}</h2>
                    <p class="text-[13px] text-sage-500">Bars show net sales, the line shows number of orders.</p>
                </div>
            </div>
            <div class="h-72 p-4" wire:key="sales-{{ $period }}" x-data="chart(@js($charts['sales']))">
                <canvas x-ref="canvas" role="img" aria-label="Sales trend chart"></canvas>
            </div>
        </section>

        <section class="panel flex flex-col">
            <div class="panel-header">
                <h2 class="panel-title">Right now</h2>
                <span class="text-[13px] text-sage-500">{{ now()->format('g:i A') }}</span>
            </div>
            <div class="grid grid-cols-2 gap-3 p-5 pb-3">
                <a href="{{ route('orders.index') }}" wire:navigate class="rounded-xl bg-warn-50 p-3.5 transition hover:ring-2 hover:ring-warn-600/20">
                    <p class="text-[13px] font-medium text-warn-700">Being prepared</p>
                    <p class="font-display text-2xl font-semibold text-warn-700">{{ $live['pending'] }}</p>
                </a>
                <a href="{{ route('orders.index') }}" wire:navigate class="rounded-xl bg-info-50 p-3.5 transition hover:ring-2 hover:ring-info-600/20">
                    <p class="text-[13px] font-medium text-info-600">Ready for pickup</p>
                    <p class="font-display text-2xl font-semibold text-info-600">{{ $live['ready'] }}</p>
                </a>
            </div>
            <div class="mx-5 rounded-xl border border-sage-200 p-3.5">
                @if ($live['shift'])
                    <p class="flex items-center gap-2 text-sm font-semibold text-leaf-800"><x-icon name="drawer" class="size-4" /> Drawer open</p>
                    <p class="mt-0.5 text-[13px] text-sage-600">By {{ $live['shift']->opener->name }} at {{ $live['shift']->opened_at->format('g:i A') }} · expected cash {{ peso($live['shift']->computeExpectedCash()) }}</p>
                @else
                    <p class="flex items-center gap-2 text-sm font-semibold text-sage-700"><x-icon name="drawer" class="size-4" /> Drawer closed</p>
                    <p class="mt-0.5 text-[13px] text-sage-600">Open a shift from the cash drawer page to start selling.</p>
                @endif
            </div>
            <div class="mt-4 flex-1 border-t border-sage-200 px-5 py-4">
                <div class="mb-2 flex items-center justify-between">
                    <p class="text-sm font-semibold">Running low</p>
                    <a href="{{ route('inventory.items') }}" wire:navigate class="text-[13px] font-semibold text-leaf-700 hover:underline">Stock on hand</a>
                </div>
                <ul class="space-y-2">
                    @forelse ($live['low'] as $item)
                        <li class="flex items-center justify-between gap-3 text-sm">
                            <span class="truncate">{{ $item->name }}</span>
                            <x-status-badge :status="$item->stockStatus()" />
                        </li>
                    @empty
                        <li class="text-sm text-sage-500">Nothing is below its reorder level.</li>
                    @endforelse
                </ul>
            </div>
        </section>
    </div>

    {{-- Product & time insights --}}
    <div class="grid gap-6 lg:grid-cols-2 xl:grid-cols-3">
        <section class="panel">
            <div class="panel-header"><h2 class="panel-title">Best sellers</h2><span class="text-[13px] text-sage-500">by quantity</span></div>
            <div class="h-72 p-4" wire:key="top-{{ $period }}" x-data="chart(@js($charts['top']))">
                <canvas x-ref="canvas" role="img" aria-label="Best selling products chart"></canvas>
            </div>
        </section>
        <section class="panel">
            <div class="panel-header">
                <h2 class="panel-title">Busiest hours</h2>
                <span class="text-[13px] text-sage-500">avg. orders / day</span>
            </div>
            <div class="h-72 p-4" wire:key="peak-{{ $period }}" x-data="chart(@js($charts['peak']))">
                <canvas x-ref="canvas" role="img" aria-label="Orders by hour of day chart"></canvas>
            </div>
        </section>
        <section class="panel lg:col-span-2 xl:col-span-1">
            <div class="panel-header"><h2 class="panel-title">How customers pay</h2></div>
            @if ($mix->isEmpty())
                <x-empty-state icon="card" title="No payments yet" description="Payment methods appear here once orders are paid." />
            @else
                <div class="h-72 p-4" wire:key="mix-{{ $period }}" x-data="chart(@js($charts['mix']))">
                    <canvas x-ref="canvas" role="img" aria-label="Payment method mix chart"></canvas>
                </div>
            @endif
        </section>
    </div>

    <div class="grid gap-6 xl:grid-cols-5">
        <section class="panel xl:col-span-2">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title">Gross sales by category</h2>
                    <p class="text-[13px] text-sage-500">Menu prices before discounts and VAT removal, so it is a little higher than net sales.</p>
                </div>
            </div>
            @php $catTotal = max(1, $categories->sum('revenue')); @endphp
            <ul class="space-y-4 p-5">
                @forelse ($categories as $cat)
                    <li>
                        <div class="flex items-baseline justify-between text-sm">
                            <span class="font-semibold">{{ $cat['name'] }}</span>
                            <span class="tabular text-sage-600">{{ peso($cat['revenue']) }} · {{ number_format($cat['qty']) }} sold</span>
                        </div>
                        <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-sage-100">
                            <div class="h-full rounded-full bg-leaf-500" style="width: {{ round($cat['revenue'] / $catTotal * 100, 1) }}%"></div>
                        </div>
                    </li>
                @empty
                    <li class="text-sm text-sage-500">No sales in this period.</li>
                @endforelse
            </ul>
        </section>

        <section class="panel overflow-hidden xl:col-span-3">
            <div class="panel-header">
                <h2 class="panel-title">Latest orders</h2>
                <a href="{{ route('orders.index') }}" wire:navigate class="text-sm font-semibold text-leaf-700 hover:underline">Order queue</a>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Order</th><th>Cashier</th><th>Payment</th><th>Status</th><th class="text-right">Total</th></tr></thead>
                    <tbody>
                        @forelse ($recent as $order)
                            <tr>
                                <td><p class="font-semibold">#{{ $order->queue_number }}</p><p class="text-xs text-sage-500">{{ $order->created_at->format('M j, g:i A') }}</p></td>
                                <td>{{ $order->cashier->name }}</td>
                                <td>{{ $order->payment?->methodLabel() ?? '—' }}</td>
                                <td><x-status-badge :status="$order->status" /></td>
                                <td class="text-right font-semibold tabular">{{ peso($order->total_amount) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-empty-state icon="orders" title="No orders yet" description="Orders taken at the POS will show up here." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
