<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CashShift;
use App\Models\Expense;
use App\Models\Income;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Refund;
use App\Models\StockIn;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Builds the data for every report. The same arrays feed the on-screen preview and the PDF,
 * so what the manager sees is exactly what gets exported.
 */
class ReportBuilder
{
    public const TYPES = [
        'sales' => ['Sales summary', 'Daily sales, payment methods, discounts, refunds and cashier performance.', 'pos'],
        'products' => ['Product sales', 'What sold, how many and how much each product and category earned.', 'coffee'],
        'inventory' => ['Inventory', 'Stock on hand with value, movements in the period, deliveries and write-offs.', 'package'],
        'financial' => ['Income statement', 'Sales and other income against expenses by category — the café\'s net income.', 'finance'],
        'shifts' => ['Cash drawer', 'Every shift with expected vs counted cash and the over/short.', 'drawer'],
        'audit' => ['Audit trail', 'Who did what and when, for the selected period and module.', 'shield'],
    ];

    public function __construct(public string $type, public Carbon $from, public Carbon $to, public array $options = [])
    {
        if (! array_key_exists($type, self::TYPES)) {
            throw new InvalidArgumentException('Unknown report type.');
        }
        $this->from = $from->copy()->startOfDay();
        $this->to = $to->copy()->endOfDay();
    }

    public function title(): string
    {
        return self::TYPES[$this->type][0];
    }

    public function build(): array
    {
        return $this->{$this->type}();
    }

    private function dates(): array
    {
        return [$this->from->toDateString(), $this->to->toDateString()];
    }

    private function sales(): array
    {
        $a = new Analytics($this->from, $this->to);

        $daily = Order::where('payment_status', 'paid')->whereBetween('created_at', [$this->from, $this->to])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as orders, SUM(subtotal) as gross, SUM(discount_amount) as discounts, SUM(total_amount) as net, SUM(vat_amount) as vat, SUM(vat_exempt_sales) as vat_exempt')
            ->groupBy('d')->orderBy('d')->get()
            ->map(fn ($r) => ['date' => Carbon::parse($r->d), 'orders' => (int) $r->orders, 'gross' => (float) $r->gross, 'discounts' => (float) $r->discounts, 'net' => (float) $r->net, 'vat' => (float) $r->vat, 'vat_exempt' => (float) $r->vat_exempt]);

        $discounts = Order::where('payment_status', 'paid')->whereNotNull('discount_id')->whereBetween('created_at', [$this->from, $this->to])
            ->selectRaw('discount_name, COUNT(*) as n, SUM(discount_amount) as total')->groupBy('discount_name')->orderByDesc('total')->get();

        return [
            'summary' => $a->summary(),
            'daily' => $daily,
            'payments' => $a->paymentMix(),
            'types' => $a->orderTypeMix(),
            'cashiers' => $a->cashierPerformance(),
            'discounts' => $discounts,
            'refunds' => Refund::with(['order', 'approver', 'processor'])->whereBetween('created_at', [$this->from, $this->to])->latest()->get(),
            'peak' => $a->peakHours(),
        ];
    }

    private function products(): array
    {
        $a = new Analytics($this->from, $this->to);
        $products = $a->topProducts(500);
        $categories = $a->salesByCategory();

        return [
            'summary' => $a->summary(),
            'products' => $products,
            'categories' => $categories,
            'total_revenue' => max(0.01, $products->sum('revenue')),
            'total_qty' => $products->sum('qty'),
        ];
    }

    private function inventory(): array
    {
        $items = InventoryItem::with(['category', 'availableBatches'])->orderBy('item_category_id')->orderBy('name')->get();

        $moves = StockMovement::whereBetween('created_at', [$this->from, $this->to])
            ->selectRaw('inventory_item_id, type, SUM(quantity) as qty, SUM(ABS(quantity) * COALESCE(unit_cost,0)) as cost')
            ->groupBy('inventory_item_id', 'type')->get()->groupBy('inventory_item_id');

        $rows = $items->map(function ($item) use ($moves) {
            $m = ($moves[$item->id] ?? collect())->keyBy('type');
            $value = $item->availableBatches->sum(fn ($b) => $b->quantity_remaining * (float) $b->unit_cost);

            return [
                'item' => $item,
                'value' => $value,
                'in' => (int) ($m['stock_in']->qty ?? 0),
                'out' => abs((int) ($m['stock_out']->qty ?? 0)),
                'adjust' => (int) (($m['adjustment_in']->qty ?? 0) + ($m['adjustment_out']->qty ?? 0)),
                'loss' => abs((int) (($m['waste']->qty ?? 0) + ($m['expired']->qty ?? 0))),
                'loss_cost' => (float) (($m['waste']->cost ?? 0) + ($m['expired']->cost ?? 0)),
                'next_expiry' => $item->availableBatches->firstWhere('expiry_date', '!=', null)?->expiry_date,
            ];
        });

        return [
            'rows' => $rows,
            'stock_value' => $rows->sum('value'),
            'low' => $rows->filter(fn ($r) => $r['item']->isLowStock())->count(),
            'loss_cost' => $rows->sum('loss_cost'),
            'deliveries' => StockIn::with('supplier')->whereBetween('received_date', $this->dates())->orderBy('received_date')->get(),
            'writeoffs' => StockMovement::with(['item', 'user'])->whereIn('type', ['waste', 'expired', 'adjustment_out', 'adjustment_in'])
                ->whereBetween('created_at', [$this->from, $this->to])->latest('created_at')->get(),
            'servings' => $this->servingsSummary(),
        ];
    }

    /**
     * Sales ↔ Inventory: drinks served per opened unit for tracked items in the period.
     * Estimates only — counted when orders are marked ready, never a measurement.
     */
    private function servingsSummary(): \Illuminate\Support\Collection
    {
        return InventoryItem::where('tracks_servings', true)->orderBy('name')->get()->map(function ($item) {
            $closed = $item->usages()->where('status', 'depleted')->whereBetween('closed_at', [$this->from, $this->to])
                ->where('exclude_from_history', false)->where('servings_used', '>', 0)->get();
            $opened = StockMovement::where('inventory_item_id', $item->id)->where('type', 'stock_out')
                ->whereBetween('created_at', [$this->from, $this->to]);
            $drinks = (float) \App\Models\OrderItemIngredient::where('inventory_item_id', $item->id)
                ->whereBetween('counted_at', [$this->from, $this->to])->sum('servings');
            $unrecorded = (float) \App\Models\OrderItemIngredient::where('inventory_item_id', $item->id)->whereNull('item_usage_id')
                ->whereBetween('counted_at', [$this->from, $this->to])->sum('servings');
            $avgDrinks = $closed->count() ? $closed->avg('servings_used') : null;
            $avgCost = (float) (clone $opened)->avg('unit_cost');

            return [
                'item' => $item,
                'opened' => (clone $opened)->count(),
                'drinks' => $drinks,
                'unrecorded' => $unrecorded,
                'used_up' => $closed->count(),
                'avg_drinks' => $avgDrinks,
                'cost_per_drink' => $avgDrinks && $avgCost ? round($avgCost / $avgDrinks, 2) : null,
                'alerts' => $item->usages()->whereBetween('alerted_at', [$this->from, $this->to])->count(),
                'flagged' => $item->usages()->whereBetween('flagged_at', [$this->from, $this->to])->count(),
            ];
        })->filter(fn ($r) => $r['opened'] || $r['drinks'] || $r['used_up'])->values();
    }

    private function financial(): array
    {
        $a = new Analytics($this->from, $this->to);
        $summary = $a->summary();

        return [
            'summary' => $summary,
            'expenses_by_category' => $a->expensesByCategory(),
            'expenses' => Expense::with(['category', 'supplier'])->whereBetween('expense_date', $this->dates())->orderBy('expense_date')->get(),
            'incomes' => Income::whereBetween('income_date', $this->dates())->orderBy('income_date')->get(),
            'gross_sales' => (float) Order::where('payment_status', 'paid')->whereBetween('created_at', [$this->from, $this->to])->sum('subtotal'),
            'margin' => $summary['sales'] + $summary['other_income'] > 0
                ? round($summary['net_income'] / ($summary['sales'] + $summary['other_income']) * 100, 1) : null,
        ];
    }

    private function shifts(): array
    {
        $shifts = CashShift::with(['opener', 'closer'])->whereBetween('opened_at', [$this->from, $this->to])->orderBy('opened_at')->get();

        return [
            'shifts' => $shifts->map(fn ($s) => ['shift' => $s, 'summary' => $s->summary()]),
            'total_variance' => (float) $shifts->sum('variance'),
            'short_count' => $shifts->where('variance', '<', 0)->count(),
            'over_count' => $shifts->where('variance', '>', 0)->count(),
        ];
    }

    private function audit(): array
    {
        $module = $this->options['module'] ?? null;
        $query = AuditLog::whereBetween('created_at', [$this->from, $this->to])->when($module, fn ($q) => $q->where('module', $module));

        return [
            'total' => (clone $query)->count(),
            'by_module' => (clone $query)->selectRaw('module, COUNT(*) as n')->groupBy('module')->orderByDesc('n')->pluck('n', 'module'),
            'logs' => $query->latest('created_at')->limit(1500)->get(),
            'module' => $module,
        ];
    }
}
