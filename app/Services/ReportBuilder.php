<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CashShift;
use App\Models\Expense;
use App\Models\Income;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Refund;
use App\Models\ShiftStockCheck;
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
            'stock_checks' => ShiftStockCheck::with(['item', 'checker', 'shift'])
                ->whereBetween('checked_at', [$this->from, $this->to])->orderBy('checked_at')->get(),
        ];
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
            'stock_used' => $this->stockUsedVsSales($summary['sales']),
            'margin' => $summary['sales'] + $summary['other_income'] > 0
                ? round($summary['net_income'] / ($summary['sales'] + $summary['other_income']) * 100, 1) : null,
        ];
    }

    /**
     * Sales ↔ Inventory → Finance: net sales next to the purchase cost of the stock that left storage
     * in the same period. Facts only: every stock-out and write-off carries the unit cost of the
     * delivery batch it came from. Not the exact cost of the drinks sold (some opened units are
     * still in use), so it is shown as a ratio to watch over time.
     */
    private function stockUsedVsSales(float $sales): array
    {
        $rows = StockMovement::with('item:id,name,unit')
            ->whereIn('type', ['stock_out', 'waste', 'expired'])
            ->whereBetween('created_at', [$this->from, $this->to])
            ->selectRaw('inventory_item_id, type, SUM(ABS(quantity)) as units, SUM(ABS(quantity) * COALESCE(unit_cost,0)) as cost')
            ->groupBy('inventory_item_id', 'type')->get()
            ->groupBy('inventory_item_id')
            ->map(function ($g) {
                $out = $g->firstWhere('type', 'stock_out');
                $lost = $g->whereIn('type', ['waste', 'expired']);

                return [
                    'item' => $g->first()->item,
                    'opened' => (int) ($out->units ?? 0),
                    'opened_cost' => round((float) ($out->cost ?? 0), 2),
                    'lost' => (int) $lost->sum('units'),
                    'lost_cost' => round((float) $lost->sum('cost'), 2),
                ];
            })
            ->sortByDesc(fn ($r) => $r['opened_cost'] + $r['lost_cost'])->values();

        $opened = (float) $rows->sum('opened_cost');
        $lost = (float) $rows->sum('lost_cost');

        return [
            'rows' => $rows,
            'sales' => $sales,
            'opened_cost' => round($opened, 2),
            'lost_cost' => round($lost, 2),
            'ratio' => $sales > 0 ? round(($opened + $lost) / $sales * 100, 1) : null,
            'end_of_shift_units' => (int) ShiftStockCheck::whereBetween('checked_at', [$this->from, $this->to])->sum('stocked_out'),
        ];
    }

    private function shifts(): array
    {
        $shifts = CashShift::with(['opener', 'closer', 'stockChecks.item'])->whereBetween('opened_at', [$this->from, $this->to])->orderBy('opened_at')->get();

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
