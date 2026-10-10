<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\WithToasts;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Income;
use App\Models\Supplier;
use App\Rules\DisplayName;
use App\Rules\UniqueName;
use App\Services\Analytics;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Finance: income (sales + other income) vs expenses.
 * Sales income comes straight from paid orders, so it can never be double-entered.
 */
#[Title('Finance')]
class Overview extends Component
{
    use WithPagination, WithToasts;

    #[Url]
    public string $tab = 'overview'; // overview | expenses | income

    #[Url]
    public string $month = '';

    #[Url(as: 'cat')]
    public string $expenseCategory = '';

    #[Url(as: 'q')]
    public string $search = '';

    public bool $showExpense = false;
    #[Locked] // set only by the server (open/edit methods), never by the browser
    public ?int $expenseId = null;
    public array $expense = [];

    public bool $showIncome = false;
    #[Locked] // set only by the server (open/edit methods), never by the browser
    public ?int $incomeId = null;
    public array $income = [];

    public bool $showCategories = false;
    public string $newCategory = '';

    public function mount(): void
    {
        $this->month = $this->month ?: today()->format('Y-m');
    }

    public function updating($name): void
    {
        if (in_array($name, ['tab', 'month', 'expenseCategory', 'search'])) {
            $this->resetPage();
        }
    }

    private function range(): array
    {
        $this->month = clean_month($this->month) ?? today()->format('Y-m');
        $this->expenseCategory = (string) (clean_id($this->expenseCategory) ?? '');
        $start = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return [$start, $end->gt(today()) ? today() : $end];
    }

    /* ── Expenses ─────────────────────────────────────── */

    public function newExpense(): void
    {
        $this->resetValidation();
        $this->expenseId = null;
        $this->expense = ['expense_category_id' => '', 'description' => '', 'amount' => '', 'expense_date' => today()->toDateString(), 'payment_method' => 'cash', 'supplier_id' => '', 'reference_number' => '', 'notes' => ''];
        $this->showExpense = true;
    }

    public function editExpense(int $id): void
    {
        $e = Expense::findOrFail($id);
        if ($e->stock_in_id) {
            $this->toast('This expense was created from a stock delivery and is kept in sync with it, so it cannot be edited here.', 'info');

            return;
        }
        $this->resetValidation();
        $this->expenseId = $id;
        $this->expense = [
            'expense_category_id' => $e->expense_category_id, 'description' => $e->description, 'amount' => (float) $e->amount,
            'expense_date' => $e->expense_date->toDateString(), 'payment_method' => $e->payment_method ?? 'cash',
            'supplier_id' => $e->supplier_id ?? '', 'reference_number' => $e->reference_number ?? '', 'notes' => $e->notes ?? '',
        ];
        $this->showExpense = true;
    }

    public function saveExpense(): void
    {
        $data = $this->validate([
            'expense.expense_category_id' => ['required', Rule::exists('expense_categories', 'id')->whereNull('deleted_at')],
            'expense.description' => ['required', 'string', 'max:255'],
            'expense.amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'expense.expense_date' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:2020-01-01'],
            'expense.payment_method' => ['required', Rule::in(array_keys(Expense::PAYMENT_METHODS))],
            'expense.supplier_id' => ['nullable', Rule::exists('suppliers', 'id')],
            'expense.reference_number' => ['nullable', 'string', 'max:100'],
            'expense.notes' => ['nullable', 'string', 'max:1000'],
        ], ['expense.expense_date.before_or_equal' => 'Future expenses can\'t be recorded yet.'], [
            'expense.expense_category_id' => 'category', 'expense.description' => 'description', 'expense.amount' => 'amount',
            'expense.expense_date' => 'date', 'expense.payment_method' => 'payment method',
        ])['expense'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        if ($this->expenseId) {
            Expense::findOrFail($this->expenseId)->update($data);
            $this->toast('Expense updated.');
        } else {
            Expense::create($data + ['recorded_by' => auth()->id()]);
            $this->toast('Expense of '.peso($data['amount']).' recorded.');
        }
        $this->showExpense = false;
    }

    public function archiveExpense(int $id): void
    {
        $e = Expense::findOrFail($id);
        if ($e->stock_in_id) {
            $this->toast('Expenses created from a stock delivery cannot be archived.', 'error');

            return;
        }
        $e->delete();
        $this->toast('Expense archived. Restore it from Archive if this was a mistake.');
    }

    public function addCategory(): void
    {
        $this->validate(['newCategory' => ['required', 'string', 'max:100', new DisplayName('“Utilities”'), new UniqueName('expense_categories')]], [], ['newCategory' => 'category name']);
        ExpenseCategory::create(['name' => trim($this->newCategory), 'is_system' => false]);
        $this->toast("Expense category “{$this->newCategory}” added.");
        $this->newCategory = '';
    }

    public function archiveCategory(int $id): void
    {
        $c = ExpenseCategory::withCount('expenses')->findOrFail($id);
        if ($c->is_system) {
            $this->toast('This category is used by the system and cannot be archived.', 'error');

            return;
        }
        if ($c->expenses_count) {
            $this->toast("“{$c->name}” has {$c->expenses_count} expense(s) and cannot be archived.", 'error');

            return;
        }
        $c->delete();
        $this->toast("Category “{$c->name}” archived.");
    }

    /* ── Other income ─────────────────────────────────── */

    public function newIncome(): void
    {
        $this->resetValidation();
        $this->incomeId = null;
        $this->income = ['source' => '', 'amount' => '', 'income_date' => today()->toDateString(), 'payment_method' => 'cash', 'reference_number' => '', 'notes' => ''];
        $this->showIncome = true;
    }

    public function editIncome(int $id): void
    {
        $this->resetValidation();
        $i = Income::findOrFail($id);
        $this->incomeId = $id;
        $this->income = ['source' => $i->source, 'amount' => (float) $i->amount, 'income_date' => $i->income_date->toDateString(), 'payment_method' => $i->payment_method ?? 'cash', 'reference_number' => $i->reference_number ?? '', 'notes' => $i->notes ?? ''];
        $this->showIncome = true;
    }

    public function saveIncome(): void
    {
        $data = $this->validate([
            'income.source' => ['required', 'string', 'max:150'],
            'income.amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'income.income_date' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:2020-01-01'],
            'income.payment_method' => ['required', Rule::in(array_keys(Expense::PAYMENT_METHODS))],
            'income.reference_number' => ['nullable', 'string', 'max:100'],
            'income.notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['income.source' => 'source', 'income.amount' => 'amount', 'income.income_date' => 'date'])['income'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        if ($this->incomeId) {
            Income::findOrFail($this->incomeId)->update($data);
            $this->toast('Income updated.');
        } else {
            Income::create($data + ['recorded_by' => auth()->id()]);
            $this->toast('Income of '.peso($data['amount']).' recorded.');
        }
        $this->showIncome = false;
    }

    public function archiveIncome(int $id): void
    {
        $i = Income::findOrFail($id);
        $i->delete();
        $this->toast('Income entry archived.');
    }

    public function render()
    {
        [$from, $to] = $this->range();
        $a = new Analytics($from, $to);
        $summary = $a->summary();

        $data = ['summary' => $summary, 'from' => $from, 'to' => $to];

        if ($this->tab === 'overview') {
            $monthly = Analytics::monthly(6);
            $flow = $a->cashflowSeries();
            $byCat = $a->expensesByCategory();

            $data += [
                'byCategory' => $byCat,
                'paymentMix' => $a->paymentMix(),
                'charts' => [
                    'monthly' => [
                        'type' => 'bar', 'money' => 'y',
                        'data' => ['labels' => collect($monthly)->pluck('short'), 'datasets' => [
                            ['label' => 'Income', 'data' => collect($monthly)->map(fn ($m) => $m['sales'] + $m['other_income']), 'backgroundColor' => '#3f845c', 'borderRadius' => 5, 'maxBarThickness' => 28],
                            ['label' => 'Expenses', 'data' => collect($monthly)->pluck('expenses'), 'backgroundColor' => '#b57a3e', 'borderRadius' => 5, 'maxBarThickness' => 28],
                            ['type' => 'line', 'label' => 'Net income', 'data' => collect($monthly)->pluck('net'), 'borderColor' => '#173a2c', 'backgroundColor' => '#173a2c', 'tension' => .3, 'pointRadius' => 3],
                        ]],
                        'options' => ['plugins' => ['legend' => ['position' => 'top', 'align' => 'end']], 'scales' => ['x' => ['grid' => ['display' => false]], 'y' => ['grid' => ['color' => '#eef3ef']]]],
                    ],
                    'flow' => [
                        'type' => 'line', 'money' => 'y',
                        'data' => ['labels' => $flow['labels'], 'datasets' => [
                            ['label' => 'Income', 'data' => $flow['income'], 'borderColor' => '#2f6b4f', 'backgroundColor' => 'rgba(63,132,92,.12)', 'fill' => true, 'tension' => .3, 'pointRadius' => 0],
                            ['label' => 'Expenses', 'data' => $flow['expenses'], 'borderColor' => '#b57a3e', 'backgroundColor' => '#b57a3e', 'tension' => 0, 'pointRadius' => 2, 'stepped' => false],
                        ]],
                        'options' => ['interaction' => ['mode' => 'index', 'intersect' => false], 'plugins' => ['legend' => ['position' => 'top', 'align' => 'end']], 'scales' => ['x' => ['grid' => ['display' => false], 'ticks' => ['maxTicksLimit' => 8, 'maxRotation' => 0]], 'y' => ['beginAtZero' => true, 'grid' => ['color' => '#eef3ef']]]],
                    ],
                    'expenses' => [
                        'type' => 'doughnut', 'peso' => true,
                        'data' => ['labels' => $byCat->pluck('name'), 'datasets' => [['data' => $byCat->pluck('total'), 'backgroundColor' => ['#2f6b4f', '#b57a3e', '#63a079', '#2e6a8e', '#a3660f', '#98c1a3', '#87572a', '#6b7d71'], 'borderColor' => '#fff', 'borderWidth' => 2]]],
                        'options' => ['cutout' => '64%', 'plugins' => ['legend' => ['position' => 'right']]],
                    ],
                ],
            ];
        }

        if ($this->tab === 'expenses') {
            $data['expenses'] = Expense::with(['category', 'supplier', 'recorder', 'stockIn'])
                ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
                ->when($this->expenseCategory, fn ($q) => $q->where('expense_category_id', $this->expenseCategory))
                ->when($this->search, fn ($q) => $q->whereRaw('lower(description) like ?', ['%'.mb_strtolower($this->search).'%']))
                ->latest('expense_date')->latest('id')->paginate(15);
        }

        if ($this->tab === 'income') {
            $data['incomes'] = Income::with('recorder')
                ->whereBetween('income_date', [$from->toDateString(), $to->toDateString()])
                ->when($this->search, fn ($q) => $q->whereRaw('lower(source) like ?', ['%'.mb_strtolower($this->search).'%']))
                ->latest('income_date')->paginate(15);
        }

        return view('livewire.finance.overview', $data + [
            'categories' => ExpenseCategory::withCount('expenses')->orderBy('name')->get(),
            'suppliers' => Supplier::orderBy('name')->pluck('name', 'id'),
            'months' => collect(range(0, 11))->map(fn ($i) => today()->startOfMonth()->subMonthsNoOverflow($i))->mapWithKeys(fn ($m) => [$m->format('Y-m') => $m->format('F Y')]),
        ]);
    }
}
