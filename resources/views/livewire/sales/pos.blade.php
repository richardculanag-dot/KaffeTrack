<div x-data="pos(@js($catalog), @js($discounts), @js($vat), @js($providers))" @keydown.window="shortcuts($event)"
     class="relative -mx-4 -my-6 flex h-[calc(100dvh-4rem)] sm:-mx-6 lg:-mx-8">

    {{-- ─────────────── No open shift: open the drawer right here ─────────────── --}}
    @unless ($shift)
        <div class="absolute inset-0 z-20 grid place-items-center bg-sage-50/85 p-4 backdrop-blur-sm">
            <form x-data="{ amount: '3000' }" @submit.prevent="$wire.openShift(amount)" class="panel w-full max-w-md p-7 shadow-xl">
                <span class="grid size-12 place-items-center rounded-2xl bg-leaf-100 text-leaf-700"><x-icon name="drawer" class="size-6" /></span>
                <h2 class="mt-4 font-display text-2xl font-semibold">Open the cash drawer</h2>
                <p class="mt-1 text-sage-600">Count the starting cash (change fund) before taking the first order. Every sale today is recorded under this shift.</p>
                <div class="mt-5">
                    <label for="opening_cash" class="field-label">Starting cash in drawer <span class="text-danger-600">*</span></label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-sage-500">₱</span>
                        <input id="opening_cash" type="number" min="0" step="0.01" x-model="amount" class="field pl-8 text-lg font-semibold" required autofocus>
                    </div>
                </div>
                <x-submit target="openShift" class="mt-5 w-full btn-lg">Open drawer and start selling</x-submit>
            </form>
        </div>
    @endunless

    {{-- ─────────────── Catalogue ─────────────── --}}
    <section class="flex min-w-0 flex-1 flex-col">
        <div class="space-y-3 border-b border-sage-200 bg-white px-4 py-3 sm:px-6">
            <div class="relative">
                <x-icon name="search" class="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-sage-400" />
                <input type="search" x-model="search" data-search placeholder="Search the menu…  (press / )" aria-label="Search the menu"
                       class="field py-3 pl-11 text-base">
            </div>
            <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1" role="tablist" aria-label="Menu categories" x-show="!search">
                <template x-for="cat in catalog" :key="cat.id">
                    <button type="button" role="tab" @click="activeCategory = cat.id" :aria-selected="activeCategory === cat.id"
                            class="flex shrink-0 items-center gap-2 rounded-full border px-4 py-2 text-sm font-semibold transition"
                            :class="activeCategory === cat.id ? 'border-leaf-600 bg-leaf-600 text-white' : 'border-sage-200 bg-white text-sage-700 hover:border-sage-300 hover:bg-sage-50'">
                        <span x-text="cat.name"></span>
                        <span class="rounded-full px-1.5 text-xs" :class="activeCategory === cat.id ? 'bg-white/20' : 'bg-sage-100'" x-text="cat.products.length"></span>
                    </button>
                </template>
            </div>
            <p x-show="search" x-cloak class="text-sm text-sage-600">
                <span x-text="visibleProducts.length"></span> result(s) for “<span class="font-semibold" x-text="search"></span>”
                <button type="button" class="ml-1 font-semibold text-leaf-700 hover:underline" @click="search = ''">Clear search</button>
            </p>
        </div>

        <div class="flex-1 overflow-y-auto p-4 pb-28 sm:p-6 lg:pb-6">
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 2xl:grid-cols-4">
                <template x-for="product in visibleProducts" :key="product.id">
                    <button type="button" @click="choose(product)"
                            class="group relative flex min-h-[132px] flex-col rounded-[var(--radius-panel)] border border-sage-200 bg-white p-4 text-left transition hover:-translate-y-0.5 hover:border-leaf-300 hover:shadow-[0_8px_20px_-12px_rgba(23,58,44,.45)] active:translate-y-0 active:scale-[.99]">
                        <template x-if="product.image">
                            <img :src="product.image" alt="" class="mb-3 h-24 w-full rounded-lg object-cover">
                        </template>
                        <span class="font-display text-[1.05rem] font-semibold leading-snug" x-text="product.name"></span>
                        <span class="mt-1 text-[13px] text-sage-500" x-text="product.variants.map(v => v.name).join(' · ')"></span>
                        <span class="mt-auto pt-3 text-[15px] font-semibold text-leaf-700 tabular" x-text="priceLabel(product)"></span>
                        <span x-show="inCartCount(product.id)" x-cloak
                              class="absolute right-3 top-3 grid size-7 place-items-center rounded-full bg-crema-500 text-[13px] font-bold text-white"
                              x-text="inCartCount(product.id)" :aria-label="inCartCount(product.id) + ' in cart'"></span>
                    </button>
                </template>
            </div>
            <div x-show="!visibleProducts.length" x-cloak>
                <x-empty-state icon="search" title="No matching products" description="Check the spelling, or clear the search to browse by category." />
            </div>
        </div>
    </section>

    {{-- Small screens: floating cart bar --}}
    <button type="button" x-show="!cartOpen" @click="cartOpen = true"
            class="fixed inset-x-4 bottom-4 z-20 flex items-center justify-between rounded-2xl bg-pine-900 px-5 py-4 text-white shadow-2xl lg:hidden">
        <span class="font-semibold">View order · <span x-text="itemCount"></span> item(s)</span>
        <span class="font-display text-lg font-semibold tabular" x-text="peso(totals.total)"></span>
    </button>
    <div x-show="cartOpen" x-cloak x-transition.opacity class="fixed inset-0 z-30 bg-pine-950/45 lg:hidden" @click="cartOpen = false"></div>

    {{-- ─────────────── Order ticket ─────────────── --}}
    <aside class="fixed inset-y-0 right-0 z-40 flex w-full max-w-[420px] flex-col bg-sage-100 transition-transform duration-200 lg:static lg:z-auto lg:translate-x-0 lg:border-l lg:border-sage-200"
           :class="cartOpen ? 'translate-x-0' : 'translate-x-full'" aria-label="Current order">
        <div class="flex items-center justify-between px-5 pb-3 pt-4">
            <div>
                <p class="text-[13px] font-medium text-sage-600">New order</p>
                <p class="font-display text-[1.7rem] font-bold leading-none text-crema-700">#{{ $nextQueue }}</p>
            </div>
            <div class="flex items-center gap-1">
                <button type="button" class="btn-ghost btn-sm" @click="clearCart()" :disabled="!cart.length" title="Clear order (F4)">
                    <x-icon name="trash" class="size-4" /> Clear
                </button>
                <button type="button" class="btn-icon lg:hidden" @click="cartOpen = false" aria-label="Close order"><x-icon name="x" /></button>
            </div>
        </div>

        <div class="ticket mx-3 mt-2 flex min-h-0 flex-1 flex-col rounded-b-2xl shadow-[0_10px_30px_-18px_rgba(23,58,44,.5)]">
            {{-- Order details --}}
            <div class="space-y-3 border-b border-dashed border-sage-300 px-4 pb-4 pt-5">
                <div class="grid grid-cols-2 gap-1 rounded-xl bg-sage-100 p-1" role="radiogroup" aria-label="Order type">
                    <button type="button" role="radio" :aria-checked="meta.order_type === 'takeout'" @click="meta.order_type = 'takeout'"
                            class="rounded-lg py-2 text-sm font-semibold transition" :class="meta.order_type === 'takeout' ? 'bg-white text-ink shadow-sm' : 'text-sage-600'">Takeout</button>
                    <button type="button" role="radio" :aria-checked="meta.order_type === 'dine_in'" @click="meta.order_type = 'dine_in'"
                            class="rounded-lg py-2 text-sm font-semibold transition" :class="meta.order_type === 'dine_in' ? 'bg-white text-ink shadow-sm' : 'text-sage-600'">Dine-in</button>
                </div>
                <div class="flex gap-2">
                    <input type="text" x-model="meta.customer_name" maxlength="100" placeholder="Customer name (optional)" aria-label="Customer name" class="field py-2 text-sm">
                    <input type="text" x-show="meta.order_type === 'dine_in'" x-cloak x-model="meta.table_number" maxlength="10" placeholder="Table" aria-label="Table number" class="field w-24 py-2 text-sm">
                </div>
            </div>

            {{-- Lines --}}
            <div class="min-h-0 flex-1 overflow-y-auto px-4 py-2">
                <template x-if="!cart.length">
                    <div class="flex h-full flex-col items-center justify-center py-10 text-center">
                        <span class="grid size-12 place-items-center rounded-2xl bg-sage-100 text-sage-400"><x-icon name="coffee" class="size-6" /></span>
                        <p class="mt-3 font-semibold">No items yet</p>
                        <p class="text-sm text-sage-500">Tap a product on the left to add it.</p>
                    </div>
                </template>
                <ul class="divide-y divide-sage-100">
                    <template x-for="(line, i) in cart" :key="line.key + '-' + line.flash">
                        <li class="animate-pop py-3">
                            <div class="flex items-start gap-3">
                                <button type="button" class="min-w-0 flex-1 text-left" @click="editLine(i)" title="Edit item">
                                    <p class="font-semibold leading-snug"><span x-text="line.name"></span></p>
                                    <p class="text-[13px] text-sage-500">
                                        <span x-text="line.variant"></span><template x-if="line.addon_names.length"><span> · <span x-text="line.addon_names.join(', ')"></span></span></template>
                                    </p>
                                    <p x-show="line.notes" class="text-[13px] italic text-crema-700" x-text="'“' + line.notes + '”'"></p>
                                </button>
                                <p class="pt-0.5 font-semibold tabular" x-text="peso(lineTotal(line))"></p>
                            </div>
                            <div class="mt-2 flex items-center justify-between">
                                <div class="flex items-center rounded-lg border border-sage-200">
                                    <button type="button" class="grid size-8 place-items-center text-sage-600 hover:bg-sage-100" @click="dec(i)" :aria-label="'Decrease ' + line.name">
                                        <x-icon name="minus" class="size-4" />
                                    </button>
                                    <span class="w-8 text-center text-sm font-semibold tabular" x-text="line.quantity" aria-live="polite"></span>
                                    <button type="button" class="grid size-8 place-items-center text-sage-600 hover:bg-sage-100" @click="inc(i)" :aria-label="'Increase ' + line.name">
                                        <x-icon name="plus" class="size-4" />
                                    </button>
                                </div>
                                <button type="button" class="text-[13px] font-semibold text-sage-500 hover:text-danger-700" @click="removeLine(i)">Remove</button>
                            </div>
                        </li>
                    </template>
                </ul>
                <div id="cart-end"></div>
            </div>

            {{-- Totals --}}
            <div class="border-t border-dashed border-sage-300 px-4 pb-4 pt-3">
                <div class="flex gap-2">
                    <select x-model="meta.discount_id" aria-label="Discount" class="field appearance-none py-2 text-sm">
                        <option value="">No discount</option>
                        <template x-for="d in discounts" :key="d.id">
                            <option :value="d.id" x-text="d.name + ' (' + d.label + ')'"></option>
                        </template>
                    </select>
                    <input x-show="selectedDiscount?.requires_id" x-cloak x-ref="discountId" type="text" x-model="meta.discount_id_number" maxlength="50"
                           placeholder="ID no." aria-label="Discount ID number" class="field w-32 py-2 text-sm">
                </div>

                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex justify-between text-sage-600"><dt>Subtotal</dt><dd class="tabular" x-text="peso(totals.subtotal)"></dd></div>
                    <div x-show="totals.lessVat > 0" x-cloak class="flex justify-between text-sage-600"><dt>Less: VAT (exempt)</dt><dd class="tabular" x-text="'−' + peso(totals.lessVat)"></dd></div>
                    <div x-show="totals.discount > 0" x-cloak class="flex justify-between text-leaf-700"><dt x-text="'Discount · ' + (selectedDiscount?.name ?? '')"></dt><dd class="tabular" x-text="'−' + peso(totals.discount)"></dd></div>
                    <div x-show="vat.registered && totals.vatAmount > 0" class="flex justify-between text-[12px] text-sage-500"><dt>VAT included ({{ rtrim(rtrim(number_format($vat['rate'], 2), '0'), '.') }}%)</dt><dd class="tabular" x-text="peso(totals.vatAmount)"></dd></div>
                    <div class="flex items-baseline justify-between pt-2">
                        <dt class="font-display text-lg font-semibold">Total</dt>
                        <dd class="font-display text-[1.75rem] font-bold tabular" x-text="peso(totals.total)"></dd>
                    </div>
                </dl>

                <button type="button" class="btn-primary btn-lg mt-3 w-full text-[17px]" @click="openPayment()" :disabled="!cart.length">
                    Charge <span x-text="peso(totals.total)"></span>
                    <kbd class="ml-1 rounded bg-white/15 px-1.5 text-xs font-medium">F9</kbd>
                </button>
                <button type="button" class="btn-ghost btn-sm mt-1.5 w-full" @click="saveUnpaid()" :disabled="!cart.length">Save as unpaid (pay later)</button>
            </div>
        </div>
        <div class="h-3"></div>
    </aside>

    {{-- ─────────────── Product customiser ─────────────── --}}
    <div x-show="picker.open" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="picker-title"
         @keydown.escape.window="picker.open = false">
        <div x-show="picker.open" x-transition.opacity class="absolute inset-0 bg-pine-950/45" @click="picker.open = false"></div>
        <form x-show="picker.open" x-trap.noscroll="picker.open" @submit.prevent="confirmPicker()"
              x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="opacity-0 translate-y-6 sm:scale-[.98]" x-transition:enter-end="opacity-100 translate-y-0 scale-100"
              class="relative flex max-h-[92vh] w-full max-w-lg flex-col rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl">
            <template x-if="picker.product">
                <div class="flex min-h-0 flex-1 flex-col">
                    <div class="flex items-start justify-between gap-3 border-b border-sage-200 px-6 py-4">
                        <div>
                            <h2 id="picker-title" class="font-display text-xl font-semibold" x-text="picker.product.name"></h2>
                            <p class="text-sm text-sage-500" x-text="picker.product.description"></p>
                        </div>
                        <button type="button" class="btn-icon -mr-2" @click="picker.open = false" aria-label="Close"><x-icon name="x" /></button>
                    </div>
                    <div class="flex-1 space-y-5 overflow-y-auto px-6 py-5">
                        <fieldset>
                            <legend class="field-label">Size</legend>
                            <div class="grid grid-cols-2 gap-2">
                                <template x-for="v in picker.product.variants" :key="v.id">
                                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border-2 px-3.5 py-3 transition has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-leaf-100"
                                           :class="picker.variantId === v.id ? 'border-leaf-600 bg-leaf-50' : 'border-sage-200 bg-white hover:border-sage-300'">
                                        <input type="radio" class="sr-only" name="variant" :value="v.id" x-model.number="picker.variantId">
                                        <span class="grid size-5 shrink-0 place-items-center rounded-full border-2 transition"
                                              :class="picker.variantId === v.id ? 'border-leaf-600 bg-leaf-600 text-white' : 'border-sage-300'">
                                            <x-icon name="check" class="size-3" stroke="3.5" x-show="picker.variantId === v.id" />
                                        </span>
                                        <span class="flex-1 font-semibold" x-text="v.name"></span>
                                        <span class="text-sm tabular text-sage-600" x-text="peso(v.price)"></span>
                                    </label>
                                </template>
                            </div>
                        </fieldset>

                        <fieldset x-show="picker.product.addons.length">
                            <legend class="field-label">Add-ons <span class="font-normal text-sage-400">(optional)</span></legend>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="a in picker.product.addons" :key="a.id">
                                    <button type="button" @click="toggleAddon(a.id)" :aria-pressed="picker.addonIds.includes(a.id)"
                                            class="flex items-center gap-1.5 rounded-full border px-3.5 py-2 text-sm font-medium transition"
                                            :class="picker.addonIds.includes(a.id) ? 'border-leaf-600 bg-leaf-600 text-white' : 'border-sage-300 hover:bg-sage-50'">
                                        <x-icon name="check" class="size-3.5" x-show="picker.addonIds.includes(a.id)" />
                                        <span x-text="a.name"></span><span class="opacity-75" x-text="'+' + peso(a.price)"></span>
                                    </button>
                                </template>
                            </div>
                        </fieldset>

                        <div class="flex flex-wrap items-end gap-4">
                            <div>
                                <span class="field-label">Quantity</span>
                                <div class="flex items-center rounded-xl border border-sage-300">
                                    <button type="button" class="grid size-11 place-items-center hover:bg-sage-100" @click="picker.quantity = Math.max(1, picker.quantity - 1)" aria-label="Decrease quantity"><x-icon name="minus" class="size-4" /></button>
                                    <input type="number" min="1" max="99" x-model.number="picker.quantity" class="w-12 border-0 text-center font-semibold tabular focus:outline-none" aria-label="Quantity">
                                    <button type="button" class="grid size-11 place-items-center hover:bg-sage-100" @click="picker.quantity = Math.min(99, picker.quantity + 1)" aria-label="Increase quantity"><x-icon name="plus" class="size-4" /></button>
                                </div>
                            </div>
                            <div class="min-w-48 flex-1">
                                <label for="picker-notes" class="field-label">Notes for the barista <span class="font-normal text-sage-400">(optional)</span></label>
                                <input id="picker-notes" type="text" x-model="picker.notes" maxlength="120" placeholder="e.g. less ice, extra hot" class="field">
                            </div>
                        </div>
                    </div>
                    <div class="border-t border-sage-200 bg-sage-50/60 px-6 py-4 sm:rounded-b-2xl">
                        <button type="submit" class="btn-primary btn-lg w-full">
                            <span x-text="picker.editIndex !== null ? 'Update item' : 'Add to order'"></span> · <span class="tabular" x-text="peso(pickerTotal)"></span>
                        </button>
                    </div>
                </div>
            </template>
        </form>
    </div>

    {{-- ─────────────── Payment ─────────────── --}}
    <div x-show="pay.open" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="pay-title"
         @keydown.escape.window="!pay.busy && (pay.open = false)">
        <div x-show="pay.open" x-transition.opacity class="absolute inset-0 bg-pine-950/45" @click="!pay.busy && (pay.open = false)"></div>
        <form x-show="pay.open" x-trap.noscroll="pay.open" @submit.prevent="submit(true)"
              x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="opacity-0 translate-y-6 sm:scale-[.98]" x-transition:enter-end="opacity-100 translate-y-0 scale-100"
              class="relative w-full max-w-md rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl">
            <div class="flex items-start justify-between border-b border-sage-200 px-6 py-4">
                <div>
                    <h2 id="pay-title" class="font-display text-xl font-semibold">Take payment</h2>
                    <p class="text-sm text-sage-500"><span x-text="itemCount"></span> item(s) · <span x-text="meta.order_type === 'dine_in' ? 'Dine-in' : 'Takeout'"></span></p>
                </div>
                <button type="button" class="btn-icon -mr-2" @click="pay.open = false" :disabled="pay.busy" aria-label="Close"><x-icon name="x" /></button>
            </div>
            <div class="space-y-5 px-6 py-5">
                <div class="rounded-xl bg-pine-900 px-5 py-4 text-white">
                    <p class="text-sm text-leaf-200/80">Amount due</p>
                    <p class="font-display text-[2.2rem] font-bold leading-tight tabular" x-text="peso(totals.total)"></p>
                </div>

                <div class="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Payment method">
                    @foreach (['cash' => ['Cash', 'banknote'], 'card' => ['Card', 'card'], 'online' => ['Online', 'phone']] as $m => [$label, $icon])
                        <button type="button" role="radio" :aria-checked="pay.method === '{{ $m }}'" @click="pay.method = '{{ $m }}'; pay.error = ''; pay.provider = '{{ $m === 'online' ? 'GCash' : ($m === 'card' ? 'Visa' : '') }}'; $nextTick(() => ($refs.tendered && pay.method === 'cash') ? $refs.tendered.focus() : $refs.reference?.focus())"
                                class="flex flex-col items-center gap-1.5 rounded-xl border-2 py-3 text-sm font-semibold transition"
                                :class="pay.method === '{{ $m }}' ? 'border-leaf-600 bg-leaf-50 text-leaf-800' : 'border-sage-200 text-sage-600 hover:border-sage-300'">
                            <x-icon :name="$icon" /> {{ $label }}
                        </button>
                    @endforeach
                </div>

                <div x-show="pay.method === 'cash'">
                    <label for="tendered" class="field-label">Cash received</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-lg text-sage-500">₱</span>
                        <input id="tendered" x-ref="tendered" type="number" min="0" step="0.01" inputmode="decimal" x-model="pay.tendered" class="field py-3 pl-9 text-xl font-semibold tabular">
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <template x-for="amt in quickCash" :key="amt">
                            <button type="button" class="btn-secondary btn-sm tabular" @click="pay.tendered = amt" x-text="amt === totals.total ? 'Exact' : peso(amt)"></button>
                        </template>
                    </div>
                    <div class="mt-4 flex items-baseline justify-between rounded-xl px-4 py-3" :class="change >= 0 && pay.tendered !== '' ? 'bg-leaf-50' : 'bg-sage-100'">
                        <span class="font-semibold" x-text="change >= 0 || pay.tendered === '' ? 'Change' : 'Still due'"></span>
                        <span class="font-display text-2xl font-bold tabular" :class="change < 0 && pay.tendered !== '' ? 'text-danger-700' : 'text-leaf-800'"
                              x-text="pay.tendered === '' ? '—' : peso(Math.abs(change))"></span>
                    </div>
                </div>

                <div x-show="pay.method !== 'cash'" x-cloak class="grid grid-cols-5 gap-3">
                    <div class="col-span-2">
                        <label for="provider" class="field-label">Provider</label>
                        <select id="provider" x-model="pay.provider" class="field appearance-none">
                            <template x-for="p in (providers[pay.method] ?? [])" :key="p"><option :value="p" x-text="p"></option></template>
                        </select>
                    </div>
                    <div class="col-span-3">
                        <label for="reference" class="field-label">Reference no. <span class="text-danger-600">*</span></label>
                        <input id="reference" x-ref="reference" type="text" x-model="pay.reference" maxlength="100" placeholder="From the slip / app" class="field" autocomplete="off">
                    </div>
                    <p class="col-span-5 -mt-1 text-xs text-sage-500">Process the payment on the terminal or e-wallet first, then record its reference number here.</p>
                </div>

                <p x-show="pay.error" x-cloak class="flex items-start gap-2 rounded-lg bg-danger-50 px-3 py-2.5 text-sm text-danger-700" role="alert">
                    <x-icon name="alert" class="mt-0.5 size-4" /><span x-text="pay.error"></span>
                </p>
            </div>
            <div class="border-t border-sage-200 bg-sage-50/60 px-6 py-4 sm:rounded-b-2xl">
                <button type="submit" class="btn-primary btn-lg w-full" :disabled="!canPay">
                    <svg x-show="pay.busy" class="size-5 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                    <span x-text="pay.busy ? 'Recording payment…' : 'Complete payment'"></span>
                </button>
            </div>
        </form>
    </div>

    {{-- ─────────────── Success ─────────────── --}}
    <div x-show="result" x-cloak class="fixed inset-0 z-[75] grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby="done-title"
         @keydown.enter.window="result && newOrder()" @keydown.escape.window="result && newOrder()">
        <div class="absolute inset-0 bg-pine-950/55"></div>
        <div x-show="result" x-trap.noscroll="result" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="opacity-0 scale-90" x-transition:enter-end="opacity-100 scale-100"
             class="relative w-full max-w-sm overflow-hidden rounded-3xl bg-white text-center shadow-2xl">
            <div class="bg-pine-900 px-6 pb-7 pt-8 text-white">
                <span class="mx-auto grid size-14 place-items-center rounded-full bg-leaf-400 text-pine-900"><x-icon name="check" class="size-8" stroke="2.75" /></span>
                <p id="done-title" class="mt-4 text-sm text-leaf-200/80" x-text="result?.paid ? 'Order placed and paid' : 'Order saved — not yet paid'"></p>
                <p class="font-display text-[3.5rem] font-bold leading-none text-crema-100">#<span x-text="result?.queue_number"></span></p>
                <p class="mt-1 text-sm text-leaf-200/70">Call this number when the order is ready</p>
            </div>
            <div class="space-y-1 px-6 py-5">
                <template x-if="result?.paid && result?.change > 0">
                    <div class="rounded-xl bg-crema-100 px-4 py-3">
                        <p class="text-sm font-semibold text-crema-700">Give change</p>
                        <p class="font-display text-3xl font-bold text-crema-700 tabular" x-text="peso(result?.change)"></p>
                    </div>
                </template>
                <p class="pt-2 text-sm text-sage-600"><span x-text="result?.order_number"></span> · <span class="font-semibold tabular" x-text="peso(result?.total)"></span> <span x-show="result?.method" x-text="'· ' + result?.method"></span></p>
            </div>
            <div class="flex gap-2 border-t border-sage-200 p-4">
                <a :href="result?.receipt_url" target="_blank" class="btn-secondary flex-1"><x-icon name="printer" class="size-4" /> Receipt</a>
                <button type="button" class="btn-primary flex-1" @click="newOrder()">New order</button>
            </div>
        </div>
    </div>
</div>
