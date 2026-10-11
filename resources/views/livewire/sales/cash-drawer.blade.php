<div class="space-y-6">
    @if (! $shift)
        <div class="grid gap-6 lg:grid-cols-5">
            <form wire:submit="open" class="panel p-6 lg:col-span-2">
                <span class="grid size-12 place-items-center rounded-2xl bg-leaf-100 text-leaf-700"><x-icon name="drawer" class="size-6" /></span>
                <h2 class="mt-4 font-display text-xl font-semibold">Start a new shift</h2>
                <p class="mt-1 text-sm text-sage-600">Count the change fund in the drawer. Sales, refunds and petty cash are tracked against this amount until the end-of-day count.</p>
                <div class="mt-5 space-y-4">
                    <x-input label="Starting cash" name="openingCash" type="number" step="0.01" min="0" prefix="₱" wire:model="openingCash" required />
                    <x-textarea label="Notes" name="openingNotes" rows="2" wire:model="openingNotes" optional placeholder="e.g. ₱500 in coins" />
                </div>
                <x-submit target="open" class="mt-5 w-full">Open cash drawer</x-submit>
            </form>
            <div class="panel flex items-center p-6 lg:col-span-3">
                <div>
                    <h3 class="font-display text-lg font-semibold">How the drawer works</h3>
                    <ol class="mt-3 space-y-3 text-sm text-sage-700">
                        <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-sage-100 text-xs font-bold">1</span> Open the drawer with the starting cash before the first order.</li>
                        <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-sage-100 text-xs font-bold">2</span> Cash sales and cash refunds update the expected amount automatically. Card and online payments never touch the drawer.</li>
                        <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-sage-100 text-xs font-bold">3</span> Record petty cash (e.g. buying ice) as <em>cash out</em> so the count still balances.</li>
                        <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-sage-100 text-xs font-bold">4</span> At closing, count each denomination. KaffeTrack adds it up and shows whether the drawer is over or short.</li>
                    </ol>
                </div>
            </div>
        </div>
    @else
        <div class="grid gap-6 xl:grid-cols-3">
            <section class="panel xl:col-span-2">
                <div class="panel-header">
                    <div>
                        <h2 class="panel-title">Current shift</h2>
                        <p class="text-[13px] text-sage-500">Opened by {{ $shift->opener->name }} · {{ $shift->opened_at->format('M j, g:i A') }} ({{ $shift->opened_at->diffForHumans(null, true) }} ago)</p>
                    </div>
                    <x-status-badge status="open" />
                </div>
                <div class="grid gap-px overflow-hidden bg-sage-200 sm:grid-cols-3">
                    @foreach ([
                        ['Starting cash', $summary['opening_cash'], null],
                        ['Cash sales', $summary['cash_sales'], '+'],
                        ['Cash refunds', $summary['cash_refunds'], '−'],
                        ['Cash in', $summary['cash_in'], '+'],
                        ['Cash out', $summary['cash_out'], '−'],
                        ['Card & online sales', $summary['card_sales'] + $summary['online_sales'], 'not in drawer'],
                    ] as [$label, $value, $sign])
                        <div class="bg-white px-5 py-4">
                            <p class="text-[13px] text-sage-500">{{ $label }} @if ($sign === 'not in drawer')<span class="text-sage-400">(not in drawer)</span>@endif</p>
                            <p class="mt-0.5 font-display text-xl font-semibold tabular">{{ in_array($sign, ['+', '−']) && $value > 0 ? $sign.' ' : '' }}{{ peso($value) }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm text-sage-600">Expected cash in drawer</p>
                        <p class="font-display text-[2rem] font-bold leading-tight text-leaf-800 tabular">{{ peso($summary['expected_cash']) }}</p>
                        <p class="text-[13px] text-sage-500">{{ $summary['transactions'] }} paid transaction(s) this shift</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="btn-secondary" wire:click="startMovement('cash_in')"><x-icon name="plus" class="size-4" /> Cash in</button>
                        <button type="button" class="btn-secondary" wire:click="startMovement('cash_out')"><x-icon name="minus" class="size-4" /> Cash out</button>
                        <button type="button" class="btn-primary" wire:click="startClose"><x-icon name="lock" class="size-4" /> End-of-day count</button>
                    </div>
                </div>
                @if ($activeOrders)
                    <div class="mx-5 mb-5 flex items-center gap-2 rounded-xl bg-warn-50 px-4 py-3 text-sm text-warn-700">
                        <x-icon name="alert" class="size-4" /> {{ $activeOrders }} order(s) are still pending or ready. They must be completed or cancelled before closing.
                        <a href="{{ route('orders.index') }}" wire:navigate class="ml-auto font-semibold underline">Order queue</a>
                    </div>
                @endif
            </section>

            <section class="panel">
                <div class="panel-header"><h2 class="panel-title">Petty cash this shift</h2></div>
                <ul class="divide-y divide-sage-100">
                    @forelse ($shift->cashMovements->sortByDesc('created_at') as $m)
                        <li class="flex items-start justify-between gap-3 px-5 py-3 text-sm">
                            <div>
                                <p class="font-semibold">{{ $m->reason }}</p>
                                <p class="text-xs text-sage-500">{{ $m->user->name }} · {{ $m->created_at->format('g:i A') }}</p>
                            </div>
                            <span class="font-semibold tabular {{ $m->type === 'cash_in' ? 'text-leaf-700' : 'text-danger-700' }}">{{ $m->type === 'cash_in' ? '+' : '−' }}{{ peso($m->amount) }}</span>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-sage-500">No cash in or cash out yet.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    @endif

    {{-- History --}}
    <section class="panel overflow-hidden">
        <div class="panel-header"><h2 class="panel-title">Closed shifts</h2></div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Date</th><th>Opened</th><th>Closed</th><th class="text-right">Expected</th><th class="text-right">Counted</th><th class="text-right">Over / short</th><th></th></tr></thead>
                <tbody>
                    @forelse ($history as $s)
                        <tr wire:key="s-{{ $s->id }}">
                            <td class="font-semibold">{{ $s->opened_at->format('D, M j') }}</td>
                            <td>{{ $s->opener->name }} · {{ $s->opened_at->format('g:i A') }}</td>
                            <td>{{ $s->closer?->name }} · {{ $s->closed_at?->format('g:i A') }}</td>
                            <td class="text-right tabular">{{ peso($s->expected_cash) }}</td>
                            <td class="text-right tabular">{{ peso($s->counted_cash) }}</td>
                            <td class="text-right">
                                @if ((float) $s->variance == 0)
                                    <span class="badge-green">Balanced</span>
                                @elseif ($s->variance > 0)
                                    <span class="badge-blue">Over {{ peso($s->variance) }}</span>
                                @else
                                    <span class="badge-red">Short {{ peso(abs($s->variance)) }}</span>
                                @endif
                            </td>
                            <td class="text-right"><button type="button" class="btn-ghost btn-sm" wire:click="$set('viewingId', {{ $s->id }})">Details</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-empty-state icon="drawer" title="No closed shifts yet" description="Shifts appear here after the end-of-day count." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($history->hasPages())<div class="border-t border-sage-200 px-5 py-3">{{ $history->links() }}</div>@endif
    </section>

    {{-- Cash in / out --}}
    <x-modal wire:model="showMovement" :title="$movementType === 'cash_in' ? 'Cash in' : 'Cash out'" :description="$movementType === 'cash_in' ? 'Money added to the drawer (e.g. change fund top-up).' : 'Money taken from the drawer (e.g. buying ice, paying a delivery).'" :icon="$movementType === 'cash_in' ? 'plus' : 'minus'">
        <form id="movement-form" wire:submit="saveMovement" class="space-y-4">
            <x-input label="Amount" name="movementAmount" type="number" step="0.01" min="1" prefix="₱" wire:model="movementAmount" required />
            <x-input label="Reason" name="movementReason" wire:model="movementReason" required placeholder="What was the cash for?" />
        </form>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="show = false">Cancel</button>
            <x-submit form="movement-form" target="saveMovement">Record {{ $movementType === 'cash_in' ? 'cash in' : 'cash out' }}</x-submit>
        </x-slot:footer>
    </x-modal>

    {{-- End-of-day count --}}
    @php $stockStep = $shift && $checkItems->isNotEmpty(); @endphp
    <x-modal wire:model="showClose" :title="$stockStep ? 'Step 1 of 2 — Stock check' : ($shift?->stockChecks()->exists() ? 'Step 2 of 2 — Cash count' : 'End-of-day count')"
             :description="$stockStep ? 'This shift\'s sales used these items. Count the sealed ones left in storage.' : 'Count each bill and coin in the drawer. The total is calculated for you.'"
             size="lg" :icon="$stockStep ? 'package' : 'lock'">
        @if ($stockStep && ! $checkReview)
            {{-- Sales ↔ Inventory: blind count of sealed units for every item this shift's sales used. --}}
            <form id="check-form" wire:submit="reviewStockCheck" class="space-y-3">
                <p class="rounded-xl bg-sage-100 px-3.5 py-2.5 text-sm text-sage-700">
                    Count only <strong>sealed</strong> units on the shelf or in the fridge — not the one open at the bar.
                    Anything you didn't stock out during the shift is recorded from this count.
                </p>
                @foreach ($checkItems as $item)
                    <div wire:key="chk-{{ $item->id }}" x-data="{ more: {{ filled($check[$item->id]['wasted'] ?? null) || filled($check[$item->id]['note'] ?? null) ? 'true' : 'false' }} }"
                         class="rounded-xl border border-sage-200 p-3.5">
                        <div class="grid items-start gap-3 sm:grid-cols-[1fr_9rem_15rem]">
                            <div>
                                <p class="font-semibold">{{ $item->name }}@if (in_array($item->id, $extraCheckIds)) <span class="badge-gray ml-1">Added</span>@endif</p>
                                <p class="text-xs text-sage-500">per {{ $item->unit }}@if ($item->currentUsage) · open since {{ $item->currentUsage->opened_at->format('M j, g:i A') }}@endif</p>
                                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                                    <button type="button" class="text-xs font-semibold text-leaf-700 hover:underline" @click="more = !more" x-text="more ? 'Hide' : 'Some were thrown away?'"></button>
                                    @if (in_array($item->id, $extraCheckIds))
                                        <button type="button" class="text-xs font-semibold text-sage-500 hover:text-danger-700 hover:underline" wire:click="removeCheckItem({{ $item->id }})">Remove</button>
                                    @endif
                                </div>
                            </div>
                            <x-input :label="$loop->first ? 'Sealed left' : null" name="check.{{ $item->id }}.counted" type="number" min="0" step="1" inputmode="numeric"
                                     wire:model="check.{{ $item->id }}.counted" aria-label="Sealed {{ $item->name }} left" placeholder="Count" />
                            <div>
                                @if ($loop->first)<span class="field-label">Open one at the bar</span>@endif
                                <div class="grid grid-cols-2 gap-1 rounded-xl bg-sage-100 p-1" role="radiogroup" aria-label="Open {{ $item->name }} at the bar">
                                    @foreach (['yes' => 'Still has some', 'no' => 'Empty / none'] as $val => $text)
                                        <label class="cursor-pointer">
                                            <input type="radio" class="peer sr-only" value="{{ $val }}" wire:model="check.{{ $item->id }}.open_left">
                                            <span class="block rounded-lg px-2 py-1.5 text-center text-sm font-semibold text-sage-600 transition peer-checked:bg-white peer-checked:text-ink peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-leaf-500">{{ $text }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('check.'.$item->id.'.open_left')<p class="field-message">{{ $message }}</p>@enderror
                            </div>
                        </div>
                        <div x-show="more" x-cloak class="mt-3 grid gap-3 border-t border-dashed border-sage-200 pt-3 sm:grid-cols-[9rem_1fr]">
                            <x-input label="Thrown away" name="check.{{ $item->id }}.wasted" type="number" min="0" step="1" inputmode="numeric" wire:model="check.{{ $item->id }}.wasted" placeholder="0" optional />
                            <x-input label="Note" name="check.{{ $item->id }}.note" wire:model="check.{{ $item->id }}.note" maxlength="200" placeholder="e.g. carton was spoiled" optional />
                        </div>
                    </div>
                @endforeach
                <div class="flex flex-wrap items-end gap-2 rounded-xl border border-dashed border-sage-300 p-3">
                    <x-select label="Used something without an order?" name="addItemId" wire:model="addItemId" :options="$addable" placeholder="Choose an item to count" class="min-w-56 flex-1" optional />
                    <button type="button" class="btn-secondary" wire:click="addCheckItem"><x-icon name="plus" class="size-4" /> Add to the check</button>
                </div>
            </form>
        @elseif ($stockStep && $checkReview)
            {{-- Review before anything is recorded. --}}
            <div class="space-y-3">
                <p class="text-sm text-sage-600">Check what will be recorded. Nothing has been saved yet.</p>
                <div class="overflow-x-auto rounded-xl border border-sage-200">
                    <table class="table">
                        <thead><tr><th>Item</th><th class="text-right">System had</th><th class="text-right">You counted</th><th>Will be recorded</th></tr></thead>
                        <tbody>
                            @foreach ($checkItems as $item)
                                @php
                                    $counted = (int) ($check[$item->id]['counted'] ?? 0);
                                    $wasted = (int) ($check[$item->id]['wasted'] ?? 0);
                                    $missing = max(0, $item->quantity_on_hand - $counted);
                                    $opened = $missing - $wasted;
                                    $empty = ($check[$item->id]['open_left'] ?? '') === 'no';
                                    $parts = [];
                                    if ($opened > 0) { $parts[] = $opened.' stocked out'.($opened > 1 ? ' ('.($opened - 1).' finished)' : ''); }
                                    if ($wasted > 0) { $parts[] = $wasted.' thrown away'; }
                                    if ($counted > $item->quantity_on_hand) { $parts[] = 'more than recorded — check for a delivery not entered; stock unchanged'; }
                                    if ($empty && ($item->currentUsage || $opened > 0)) { $parts[] = 'open '.$item->unit.' marked finished'; }
                                    elseif (! $empty && ($item->currentUsage || $opened > 0)) { $parts[] = 'open '.$item->unit.' stays open'; }
                                    // Staff say one is open, but none was ever taken out of storage and none is missing now.
                                    $contradiction = ! $empty && ! $item->currentUsage && $opened === 0 && $counted <= $item->quantity_on_hand;
                                @endphp
                                <tr wire:key="rev-{{ $item->id }}">
                                    <td class="font-semibold">{{ $item->name }}</td>
                                    <td class="text-right tabular">{{ $item->quantity_on_hand }}</td>
                                    <td class="text-right tabular {{ $counted !== $item->quantity_on_hand ? 'font-semibold text-warn-700' : '' }}">{{ $counted }}</td>
                                    <td class="text-sm {{ $parts ? '' : 'text-sage-500' }}">{{ $parts ? ucfirst(implode(' · ', $parts)) : 'Matches — nothing to record' }}
                                        @if ($contradiction)
                                            <span class="mt-1 block text-xs font-semibold text-warn-700">No open {{ $item->unit }} is recorded. If one is open at the bar, recount the sealed ones — one should be missing. If you keep this answer, the manager gets a note.</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-sage-500">Stock-outs recorded here are marked "recorded at the end-of-shift check" with your name.</p>
            </div>
        @elseif ($shift)
            @php $expected = $summary['expected_cash']; $variance = round($this->counted - $expected, 2); @endphp
            <form id="close-form" wire:submit="close" class="space-y-5">
                <div class="grid grid-cols-3 gap-3 sm:grid-cols-5">
                    @foreach (\App\Services\CashShiftService::DENOMINATIONS as $d)
                        <div>
                            <label for="count_{{ $d }}" class="mb-1 block text-[13px] font-semibold text-sage-600">₱{{ number_format($d) }}</label>
                            <input id="count_{{ $d }}" type="number" min="0" step="1" inputmode="numeric" placeholder="0"
                                   wire:model.live.debounce.250ms="counts.{{ $d }}" class="field px-3 py-2 text-center tabular @error('counts.'.$d) field-error @enderror">
                        </div>
                    @endforeach
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl bg-sage-100 px-4 py-3"><p class="text-[13px] text-sage-600">Expected</p><p class="font-display text-xl font-semibold tabular">{{ peso($expected) }}</p></div>
                    <div class="rounded-xl bg-sage-100 px-4 py-3"><p class="text-[13px] text-sage-600">Counted</p><p class="font-display text-xl font-semibold tabular">{{ peso($this->counted) }}</p></div>
                    <div class="rounded-xl px-4 py-3 {{ $variance == 0 ? 'bg-leaf-50 text-leaf-800' : ($variance > 0 ? 'bg-info-50 text-info-600' : 'bg-danger-50 text-danger-700') }}" aria-live="polite">
                        <p class="text-[13px]">{{ $variance == 0 ? 'Balanced' : ($variance > 0 ? 'Over' : 'Short') }}</p>
                        <p class="font-display text-xl font-semibold tabular">{{ peso(abs($variance)) }}</p>
                    </div>
                </div>

                @if ($addable->isNotEmpty())
                    <div class="flex flex-wrap items-end gap-2 rounded-xl border border-dashed border-sage-300 p-3">
                        <x-select label="Stock used without an order this shift?" name="addItemId" wire:model="addItemId" :options="$addable" placeholder="Choose an item to count" class="min-w-56 flex-1" optional />
                        <button type="button" class="btn-secondary" wire:click="addCheckItem"><x-icon name="package" class="size-4" /> Count it first</button>
                    </div>
                @endif

                <x-textarea label="Notes" name="closeNotes" rows="2" wire:model="closeNotes" :optional="$variance == 0" :required="$variance != 0"
                            :help="$variance != 0 ? 'Required: explain why the drawer is over or short.' : null" placeholder="Anything the manager should know" />
            </form>
        @endif
        <x-slot:footer>
            @if ($stockStep && ! $checkReview)
                <button type="button" class="btn-secondary" @click="show = false">Keep shift open</button>
                <x-submit form="check-form" target="reviewStockCheck">Review counts</x-submit>
            @elseif ($stockStep)
                <button type="button" class="btn-secondary" wire:click="editStockCheck">Back to counts</button>
                <button type="button" class="btn-primary" wire:click="saveStockCheck" wire:loading.attr="disabled" wire:target="saveStockCheck">
                    <x-icon name="check" class="size-4" /> Record stock check
                </button>
            @else
                <button type="button" class="btn-secondary" @click="show = false">Keep shift open</button>
                <button type="button" class="btn-primary" wire:loading.attr="disabled" wire:target="close"
                        @click="$confirm({ title: 'Close the cash drawer?', message: 'After closing, no more orders can be taken until a new shift is opened.', confirmText: 'Close shift' }, () => $wire.close())">
                    Close shift
                </button>
            @endif
        </x-slot:footer>
    </x-modal>

    {{-- Shift details --}}
    <x-modal wire:model="viewingId" :title="$viewing ? 'Shift on '.$viewing->opened_at->format('l, M j') : 'Shift'" size="lg" icon="drawer">
        @if ($viewing)
            @php $sum = $viewing->summary(); @endphp
            <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                <div class="flex justify-between"><dt class="text-sage-600">Opened</dt><dd>{{ $viewing->opener->name }}, {{ $viewing->opened_at->format('g:i A') }}</dd></div>
                <div class="flex justify-between"><dt class="text-sage-600">Closed</dt><dd>{{ $viewing->closer?->name }}, {{ $viewing->closed_at?->format('g:i A') }}</dd></div>
                <div class="flex justify-between"><dt class="text-sage-600">Starting cash</dt><dd class="tabular">{{ peso($sum['opening_cash']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-sage-600">Cash sales</dt><dd class="tabular">{{ peso($sum['cash_sales']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-sage-600">Card sales</dt><dd class="tabular">{{ peso($sum['card_sales']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-sage-600">Online sales</dt><dd class="tabular">{{ peso($sum['online_sales']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-sage-600">Cash refunds</dt><dd class="tabular">{{ peso($sum['cash_refunds']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-sage-600">Cash in / out</dt><dd class="tabular">{{ peso($sum['cash_in']) }} / {{ peso($sum['cash_out']) }}</dd></div>
                <div class="flex justify-between font-semibold"><dt>Expected</dt><dd class="tabular">{{ peso($viewing->expected_cash) }}</dd></div>
                <div class="flex justify-between font-semibold"><dt>Counted</dt><dd class="tabular">{{ peso($viewing->counted_cash) }}</dd></div>
            </dl>
            @if ($viewing->denominations)
                <h3 class="mb-2 mt-5 text-sm font-semibold text-sage-600">Denomination count</h3>
                <div class="flex flex-wrap gap-2">
                    @foreach ($viewing->denominations as $d => $n)
                        <span class="badge-gray tabular">₱{{ number_format($d) }} × {{ $n }}</span>
                    @endforeach
                </div>
            @endif
            @if ($viewing->stockChecks->isNotEmpty())
                <h3 class="mb-2 mt-5 text-sm font-semibold text-sage-600">End-of-shift stock check · {{ $viewing->stockChecks->first()->checker?->name }}</h3>
                <div class="overflow-x-auto rounded-xl border border-sage-200">
                    <table class="table">
                        <thead><tr><th>Item</th><th class="text-right">System had</th><th class="text-right">Counted</th><th>Recorded</th></tr></thead>
                        <tbody>
                            @foreach ($viewing->stockChecks->sortBy(fn ($c) => $c->item?->name) as $c)
                                <tr>
                                    <td class="font-semibold">{{ $c->item?->name }}</td>
                                    <td class="text-right tabular">{{ $c->expected_sealed }}</td>
                                    <td class="text-right tabular {{ $c->difference() !== 0 ? 'font-semibold text-warn-700' : '' }}">{{ $c->counted_sealed }}</td>
                                    <td class="text-sm">{{ $c->outcome() }}{{ $c->open_unit_finished === true ? ' · open unit finished' : '' }}@if ($c->note)<span class="block text-xs text-sage-500">{{ $c->note }}</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if ($viewing->notes)
                <h3 class="mb-1 mt-5 text-sm font-semibold text-sage-600">Notes</h3>
                <p class="whitespace-pre-line text-sm">{{ $viewing->notes }}</p>
            @endif
        @endif
    </x-modal>
</div>
