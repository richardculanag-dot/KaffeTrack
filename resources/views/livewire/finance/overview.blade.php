<div class="space-y-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div class="tabs self-start" role="tablist">
            @foreach (['overview' => 'Overview', 'expenses' => 'Expenses', 'income' => 'Other income'] as $key => $label)
                <button type="button" role="tab" class="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')">{{ $label }}</button>
            @endforeach
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="month" class="field w-auto py-2 pr-8" aria-label="Month">
                @foreach ($months as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            @if ($tab === 'expenses')
                <button type="button" class="btn-secondary" wire:click="$set('showCategories', true)"><x-icon name="layers" class="size-4" /> Categories</button>
                <button type="button" class="btn-primary" wire:click="newExpense"><x-icon name="plus" class="size-4" /> Record expense</button>
            @elseif ($tab === 'income')
                <button type="button" class="btn-primary" wire:click="newIncome"><x-icon name="plus" class="size-4" /> Record income</button>
            @else
                <a href="{{ route('reports.index', ['type' => 'financial', 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}" wire:navigate class="btn-secondary"><x-icon name="download" class="size-4" /> Export PDF</a>
            @endif
        </div>
    </div>

    {{-- Summary --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" wire:loading.class="opacity-60" wire:target="month">
        <div class="panel p-5">
            <p class="text-sm text-sage-600">Sales income</p>
            <p class="mt-1 font-display text-[1.7rem] font-semibold tabular">{{ peso($summary['sales']) }}</p>
            <p class="text-[13px] text-sage-500">{{ number_format($summary['orders']) }} paid orders · {{ peso($summary['refunds']) }} refunded</p>
        </div>
        <div class="panel p-5">
            <p class="text-sm text-sage-600">Other income</p>
            <p class="mt-1 font-display text-[1.7rem] font-semibold tabular">{{ peso($summary['other_income']) }}</p>
            <p class="text-[13px] text-sage-500">Catering, events, misc.</p>
        </div>
        <div class="panel p-5">
            <p class="text-sm text-sage-600">Expenses</p>
            <p class="mt-1 font-display text-[1.7rem] font-semibold tabular text-crema-700">{{ peso($summary['expenses']) }}</p>
            <p class="text-[13px] text-sage-500">Incl. supplier purchases</p>
        </div>
        <div class="rounded-[var(--radius-panel)] p-5 {{ $summary['net_income'] >= 0 ? 'bg-pine-900 text-white' : 'bg-danger-700 text-white' }}">
            <p class="text-sm opacity-80">Net income</p>
            <p class="mt-1 font-display text-[1.7rem] font-semibold tabular">{{ peso($summary['net_income']) }}</p>
            <p class="text-[13px] opacity-75">{{ $from->format('M j') }} – {{ $to->format('M j, Y') }}</p>
        </div>
    </div>

    @if ($tab === 'overview')
        <div class="grid gap-6 xl:grid-cols-5">
            <section class="panel xl:col-span-3">
                <div class="panel-header"><div><h2 class="panel-title">Last 6 months</h2><p class="text-[13px] text-sage-500">Income vs expenses, with net income as the line.</p></div></div>
                <div class="h-80 p-4" wire:key="monthly-{{ $month }}" x-data="chart(@js($charts['monthly']))"><canvas x-ref="canvas" role="img" aria-label="Monthly income and expenses"></canvas></div>
            </section>
            <section class="panel xl:col-span-2">
                <div class="panel-header"><h2 class="panel-title">Where the money went</h2></div>
                @if ($byCategory->isEmpty())
                    <x-empty-state icon="receipt" title="No expenses this month" />
                @else
                    <div class="h-56 p-4" wire:key="exp-{{ $month }}" x-data="chart(@js($charts['expenses']))"><canvas x-ref="canvas" role="img" aria-label="Expenses by category"></canvas></div>
                    <table class="table">
                        <tbody>
                            @foreach ($byCategory as $row)
                                <tr><td>{{ $row['name'] }}</td><td class="text-right text-sage-500">{{ round($row['total'] / max(1, $summary['expenses']) * 100) }}%</td><td class="text-right font-semibold tabular">{{ peso($row['total']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        </div>
        <section class="panel">
            <div class="panel-header"><h2 class="panel-title">Daily cash flow</h2><span class="text-[13px] text-sage-500">{{ $from->format('F Y') }}</span></div>
            <div class="h-72 p-4" wire:key="flow-{{ $month }}" x-data="chart(@js($charts['flow']))"><canvas x-ref="canvas" role="img" aria-label="Daily income and expenses"></canvas></div>
        </section>
    @endif

    @if ($tab === 'expenses')
        <section class="panel overflow-hidden">
            <div class="panel-header flex-wrap">
                <div class="flex flex-wrap gap-2">
                    <x-search-input wire:model.live.debounce.300ms="search" placeholder="Search descriptions" />
                    <select wire:model.live="expenseCategory" class="field w-auto py-2 pr-8" aria-label="Category">
                        <option value="">All categories</option>
                        @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                    </select>
                </div>
            </div>
            <div class="relative overflow-x-auto">
                <div wire:loading.flex class="absolute inset-0 z-10 items-center justify-center bg-white/60"><x-spinner class="size-6 text-leaf-600" /></div>
                <table class="table">
                    <thead><tr><th>Date</th><th>Description</th><th>Category</th><th>Paid by</th><th>Recorded by</th><th class="text-right">Amount</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($expenses as $e)
                            <tr wire:key="e-{{ $e->id }}">
                                <td class="whitespace-nowrap">{{ $e->expense_date->format('M j') }}</td>
                                <td>
                                    <p class="font-semibold">{{ $e->description }}</p>
                                    <p class="text-xs text-sage-500">
                                        @if ($e->stockIn)<x-icon name="lock" class="inline size-3" /> From delivery {{ $e->stockIn->reference_no }}@endif
                                        {{ $e->supplier && ! $e->stockIn ? $e->supplier->name : '' }}{{ $e->reference_number ? ' · Ref '.$e->reference_number : '' }}
                                    </p>
                                </td>
                                <td><span class="badge-gray">{{ $e->category->name }}</span></td>
                                <td>{{ \App\Models\Expense::PAYMENT_METHODS[$e->payment_method] ?? '—' }}</td>
                                <td class="text-sage-600">{{ $e->recorder->name }}</td>
                                <td class="text-right font-semibold tabular">{{ peso($e->amount) }}</td>
                                <td>
                                    @unless ($e->stock_in_id)
                                        <div class="flex justify-end gap-1">
                                            <button type="button" class="btn-icon" wire:click="editExpense({{ $e->id }})" aria-label="Edit expense"><x-icon name="pencil" class="size-4" /></button>
                                            <button type="button" class="btn-icon hover:text-danger-700" aria-label="Archive expense"
                                                    @click="$confirm({ title: 'Archive this expense?', message: @js($e->description.' — '.peso($e->amount).'. It will no longer count in totals. You can restore it from Archive.'), confirmText: 'Archive', tone: 'danger' }, () => $wire.archiveExpense({{ $e->id }}))"><x-icon name="archive" class="size-4" /></button>
                                        </div>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><x-empty-state icon="receipt" title="No expenses this month" description="Record rent, utilities, payroll and other costs to see your true net income.">
                                <button type="button" class="btn-primary" wire:click="newExpense"><x-icon name="plus" class="size-4" /> Record expense</button>
                            </x-empty-state></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($expenses->hasPages())<div class="border-t border-sage-200 px-5 py-3">{{ $expenses->links() }}</div>@endif
        </section>
    @endif

    @if ($tab === 'income')
        <section class="panel overflow-hidden">
            <div class="panel-header flex-wrap">
                <x-search-input wire:model.live.debounce.300ms="search" placeholder="Search sources" />
                <p class="text-sm text-sage-500">Sales from the POS are counted automatically — record only other income here.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Date</th><th>Source</th><th>Received by</th><th>Recorded by</th><th class="text-right">Amount</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($incomes as $i)
                            <tr wire:key="i-{{ $i->id }}">
                                <td class="whitespace-nowrap">{{ $i->income_date->format('M j') }}</td>
                                <td><p class="font-semibold">{{ $i->source }}</p>@if ($i->notes)<p class="text-xs text-sage-500">{{ $i->notes }}</p>@endif</td>
                                <td>{{ \App\Models\Expense::PAYMENT_METHODS[$i->payment_method] ?? '—' }}</td>
                                <td class="text-sage-600">{{ $i->recorder->name }}</td>
                                <td class="text-right font-semibold tabular text-leaf-700">{{ peso($i->amount) }}</td>
                                <td>
                                    <div class="flex justify-end gap-1">
                                        <button type="button" class="btn-icon" wire:click="editIncome({{ $i->id }})" aria-label="Edit income"><x-icon name="pencil" class="size-4" /></button>
                                        <button type="button" class="btn-icon hover:text-danger-700" aria-label="Archive income"
                                                @click="$confirm({ title: 'Archive this income?', message: @js($i->source.' — '.peso($i->amount)), confirmText: 'Archive', tone: 'danger' }, () => $wire.archiveIncome({{ $i->id }}))"><x-icon name="archive" class="size-4" /></button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-empty-state icon="banknote" title="No other income this month" description="Catering, event booths or bulk corporate orders paid outside the POS." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($incomes->hasPages())<div class="border-t border-sage-200 px-5 py-3">{{ $incomes->links() }}</div>@endif
        </section>
    @endif

    {{-- Expense form --}}
    <x-modal wire:model="showExpense" :title="$expenseId ? 'Edit expense' : 'Record expense'" size="lg" icon="receipt">
        <form id="expense-form" wire:submit="saveExpense" class="grid gap-4 sm:grid-cols-2">
            <x-input label="Description" name="expense.description" wire:model.blur="expense.description" required class="sm:col-span-2" placeholder="e.g. Davao Light electricity — October" />
            <x-select label="Category" name="expense.expense_category_id" wire:model="expense.expense_category_id" :options="$categories->pluck('name', 'id')" placeholder="Choose a category" required />
            <x-input label="Amount" name="expense.amount" type="number" step="0.01" min="0" prefix="₱" wire:model.blur="expense.amount" required />
            <x-input label="Date" name="expense.expense_date" type="date" max="{{ today()->toDateString() }}" wire:model.blur="expense.expense_date" required />
            <x-select label="Paid by" name="expense.payment_method" wire:model="expense.payment_method" :options="\App\Models\Expense::PAYMENT_METHODS" required />
            <x-select label="Supplier / payee" name="expense.supplier_id" wire:model="expense.supplier_id" :options="$suppliers" placeholder="None" optional />
            <x-input label="Reference / OR no." name="expense.reference_number" wire:model.blur="expense.reference_number" optional />
            <x-textarea label="Notes" name="expense.notes" rows="2" wire:model.blur="expense.notes" optional class="sm:col-span-2" />
        </form>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="show = false">Cancel</button>
            <x-submit form="expense-form" target="saveExpense">{{ $expenseId ? 'Save changes' : 'Record expense' }}</x-submit>
        </x-slot:footer>
    </x-modal>

    {{-- Income form --}}
    <x-modal wire:model="showIncome" :title="$incomeId ? 'Edit income' : 'Record other income'" icon="banknote">
        <form id="income-form" wire:submit="saveIncome" class="grid gap-4 sm:grid-cols-2">
            <x-input label="Source" name="income.source" wire:model.blur="income.source" required class="sm:col-span-2" placeholder="e.g. Catering — UM seminar coffee bar" />
            <x-input label="Amount" name="income.amount" type="number" step="0.01" min="0" prefix="₱" wire:model.blur="income.amount" required />
            <x-input label="Date" name="income.income_date" type="date" max="{{ today()->toDateString() }}" wire:model.blur="income.income_date" required />
            <x-select label="Received by" name="income.payment_method" wire:model="income.payment_method" :options="\App\Models\Expense::PAYMENT_METHODS" required />
            <x-input label="Reference no." name="income.reference_number" wire:model.blur="income.reference_number" optional />
            <x-textarea label="Notes" name="income.notes" rows="2" wire:model.blur="income.notes" optional class="sm:col-span-2" />
        </form>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="show = false">Cancel</button>
            <x-submit form="income-form" target="saveIncome">{{ $incomeId ? 'Save changes' : 'Record income' }}</x-submit>
        </x-slot:footer>
    </x-modal>

    {{-- Expense categories --}}
    <x-modal wire:model="showCategories" title="Expense categories" icon="layers">
        <form wire:submit="addCategory" class="flex items-start gap-2">
            <x-input name="newCategory" wire:model="newCategory" placeholder="New category name" class="flex-1" aria-label="New category name" />
            <x-submit target="addCategory">Add</x-submit>
        </form>
        <ul class="mt-4 divide-y divide-sage-100 rounded-xl border border-sage-200">
            @foreach ($categories as $c)
                <li class="flex items-center gap-3 px-4 py-2.5" wire:key="ec-{{ $c->id }}">
                    <span class="flex-1 text-sm font-semibold">{{ $c->name }} @if ($c->is_system)<span class="badge-gray ml-1">System</span>@endif</span>
                    <span class="text-xs text-sage-500">{{ $c->expenses_count }} expense(s)</span>
                    @unless ($c->is_system)
                        <button type="button" class="btn-icon hover:text-danger-700" aria-label="Archive {{ $c->name }}"
                                @click="$confirm({ title: @js('Archive “'.$c->name.'”?'), message: 'Only unused categories can be archived.', confirmText: 'Archive', tone: 'danger' }, () => $wire.archiveCategory({{ $c->id }}))"><x-icon name="archive" class="size-4" /></button>
                    @endunless
                </li>
            @endforeach
        </ul>
    </x-modal>
</div>
