<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Business indicators shared by the Dashboard, Finance and Reports modules.
 *
 * Definitions (used consistently everywhere):
 *  • Net sales     = Σ total_amount of PAID orders placed in the period (after discounts, VAT-inclusive).
 *                    Refunded orders are excluded because their payment_status becomes "refunded".
 *  • Transactions  = number of paid orders.
 *  • Avg. ticket   = net sales ÷ transactions.
 *  • Other income  = non-sales income recorded in Finance.
 *  • Net income    = net sales + other income − expenses.
 */
class Analytics
{
    public function __construct(public Carbon $from, public Carbon $to)
    {
        $this->from = $from->copy()->startOfDay();
        $this->to = $to->copy()->endOfDay();
    }

    public static function period(string $key): self
    {
        return match ($key) {
            'today' => new self(today(), today()),
            '7d' => new self(today()->subDays(6), today()),
            '30d' => new self(today()->subDays(29), today()),
            'month' => new self(today()->startOfMonth(), today()),
            'last_month' => new self(today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()),
            default => new self(today(), today()),
        };
    }

    /** The equally long period immediately before this one (for "vs previous" deltas). */
    public function previous(): self
    {
        $days = (int) $this->from->diffInDays($this->to) + 1;

        return new self($this->from->copy()->subDays($days), $this->from->copy()->subDay());
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    private function paidOrders()
    {
        return Order::query()->where('orders.payment_status', 'paid')->whereBetween('orders.created_at', [$this->from, $this->to]);
    }

    public function summary(): array
    {
        $row = $this->paidOrders()->selectRaw('COUNT(*) as orders, COALESCE(SUM(total_amount),0) as sales, COALESCE(SUM(discount_amount),0) as discounts, COALESCE(SUM(vat_amount),0) as vat')->first();
        $items = (int) OrderItem::whereHas('order', fn ($q) => $q->where('payment_status', 'paid')->whereBetween('created_at', [$this->from, $this->to]))->sum('quantity');
        $refunds = Refund::whereBetween('created_at', [$this->from, $this->to])->selectRaw('COUNT(*) as n, COALESCE(SUM(amount),0) as total')->first();
        $cancelled = Order::where('status', 'cancelled')->whereBetween('created_at', [$this->from, $this->to])->count();
        $expenses = (float) Expense::whereBetween('expense_date', [$this->from->toDateString(), $this->to->toDateString()])->sum('amount');
        $otherIncome = (float) Income::whereBetween('income_date', [$this->from->toDateString(), $this->to->toDateString()])->sum('amount');

        $sales = (float) $row->sales;
        $orders = (int) $row->orders;

        return [
            'sales' => $sales,
            'orders' => $orders,
            'items' => $items,
            'avg_ticket' => $orders ? round($sales / $orders, 2) : 0,
            'discounts' => (float) $row->discounts,
            'vat' => (float) $row->vat,
            'refunds' => (float) $refunds->total,
            'refund_count' => (int) $refunds->n,
            'cancelled' => $cancelled,
            'expenses' => $expenses,
            'other_income' => $otherIncome,
            'net_income' => round($sales + $otherIncome - $expenses, 2),
        ];
    }

    /** Daily (or hourly for a single day) sales series with zero-filled gaps. */
    public function salesSeries(): array
    {
        if ($this->days() === 1) {
            $rows = $this->paidOrders()
                ->selectRaw('EXTRACT(HOUR FROM created_at)::int as bucket, SUM(total_amount) as sales, COUNT(*) as orders')
                ->groupBy('bucket')->get()->keyBy('bucket');
            $labels = $sales = $orders = [];
            // Opening hours (6 AM–10 PM), widened to include any hour that actually had orders,
            // so the bars always add up to the day's net sales.
            [$first, $last] = self::hourRange($rows->keys());
            for ($h = $first; $h <= $last; $h++) {
                $labels[] = Carbon::createFromTime($h)->format('g A');
                $sales[] = round((float) ($rows[$h]->sales ?? 0), 2);
                $orders[] = (int) ($rows[$h]->orders ?? 0);
            }

            return compact('labels', 'sales', 'orders');
        }

        $rows = $this->paidOrders()
            ->selectRaw('DATE(created_at) as bucket, SUM(total_amount) as sales, COUNT(*) as orders')
            ->groupBy('bucket')->get()->keyBy(fn ($r) => Carbon::parse($r->bucket)->toDateString());

        $labels = $sales = $orders = [];
        for ($d = $this->from->copy(); $d->lte($this->to); $d->addDay()) {
            $key = $d->toDateString();
            $labels[] = $d->format('M j');
            $sales[] = round((float) ($rows[$key]->sales ?? 0), 2);
            $orders[] = (int) ($rows[$key]->orders ?? 0);
        }

        return compact('labels', 'sales', 'orders');
    }

    /** Average orders per hour of day — tells the manager when to schedule more baristas. */
    public function peakHours(): array
    {
        $rows = $this->paidOrders()
            ->selectRaw('EXTRACT(HOUR FROM created_at)::int as hour, COUNT(*) as orders')
            ->groupBy('hour')->pluck('orders', 'hour');
        $labels = $values = [];
        [$first, $last] = self::hourRange($rows->keys());
        for ($h = $first; $h <= $last; $h++) {
            $labels[] = Carbon::createFromTime($h)->format('gA');
            $values[] = round(($rows[$h] ?? 0) / $this->days(), 1);
        }

        return compact('labels', 'values');
    }

    /** 6 AM–10 PM, extended to cover any hour that has data. @return array{0:int,1:int} */
    private static function hourRange(Collection $hours): array
    {
        $hours = $hours->map(fn ($h) => (int) $h);

        return [min(6, $hours->min() ?? 6), max(22, $hours->max() ?? 22)];
    }

    public function topProducts(int $limit = 8): Collection
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.payment_status', 'paid')
            ->whereNull('orders.deleted_at')
            ->whereBetween('orders.created_at', [$this->from, $this->to])
            ->selectRaw('order_items.product_name as name, SUM(order_items.quantity) as qty, SUM(order_items.line_total) as revenue')
            ->groupBy('order_items.product_name')
            ->orderByDesc('qty')
            ->limit($limit)->get()
            ->map(fn ($r) => ['name' => $r->name, 'qty' => (int) $r->qty, 'revenue' => round((float) $r->revenue, 2)]);
    }

    public function salesByCategory(): Collection
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('orders.payment_status', 'paid')
            ->whereNull('orders.deleted_at')
            ->whereBetween('orders.created_at', [$this->from, $this->to])
            ->selectRaw("COALESCE(categories.name, 'Uncategorised') as name, SUM(order_items.quantity) as qty, SUM(order_items.line_total) as revenue")
            ->groupBy('categories.name')->orderByDesc('revenue')->get()
            ->map(fn ($r) => ['name' => $r->name, 'qty' => (int) $r->qty, 'revenue' => round((float) $r->revenue, 2)]);
    }

    public function paymentMix(): Collection
    {
        return Payment::query()
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.payment_status', 'paid')
            ->whereNull('orders.deleted_at')
            ->whereBetween('orders.created_at', [$this->from, $this->to])
            ->selectRaw('payments.method, COUNT(*) as count, SUM(payments.amount) as total')
            ->groupBy('payments.method')->orderByDesc('total')->get()
            ->map(fn ($r) => ['method' => Payment::METHODS[$r->method] ?? $r->method, 'count' => (int) $r->count, 'total' => round((float) $r->total, 2)]);
    }

    public function orderTypeMix(): Collection
    {
        return $this->paidOrders()->selectRaw('order_type, COUNT(*) as count, SUM(total_amount) as total')
            ->groupBy('order_type')->get()
            ->map(fn ($r) => ['type' => Order::TYPES[$r->order_type] ?? $r->order_type, 'count' => (int) $r->count, 'total' => round((float) $r->total, 2)]);
    }

    public function expensesByCategory(): Collection
    {
        return Expense::query()
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->whereNull('expenses.deleted_at')
            ->whereBetween('expense_date', [$this->from->toDateString(), $this->to->toDateString()])
            ->selectRaw('expense_categories.name as name, SUM(expenses.amount) as total, COUNT(*) as count')
            ->groupBy('expense_categories.name')->orderByDesc('total')->get()
            ->map(fn ($r) => ['name' => $r->name, 'total' => round((float) $r->total, 2), 'count' => (int) $r->count]);
    }

    /** Daily cash-flow series: income (sales + other) vs expenses. */
    public function cashflowSeries(): array
    {
        $sales = $this->paidOrders()->selectRaw('DATE(created_at) as d, SUM(total_amount) as t')->groupBy('d')->pluck('t', 'd')
            ->mapWithKeys(fn ($v, $k) => [Carbon::parse($k)->toDateString() => (float) $v]);
        $other = Income::whereBetween('income_date', [$this->from->toDateString(), $this->to->toDateString()])
            ->selectRaw('income_date as d, SUM(amount) as t')->groupBy('d')->pluck('t', 'd')
            ->mapWithKeys(fn ($v, $k) => [Carbon::parse($k)->toDateString() => (float) $v]);
        $exp = Expense::whereBetween('expense_date', [$this->from->toDateString(), $this->to->toDateString()])
            ->selectRaw('expense_date as d, SUM(amount) as t')->groupBy('d')->pluck('t', 'd')
            ->mapWithKeys(fn ($v, $k) => [Carbon::parse($k)->toDateString() => (float) $v]);

        $labels = $income = $expenses = [];
        for ($d = $this->from->copy(); $d->lte($this->to); $d->addDay()) {
            $k = $d->toDateString();
            $labels[] = $d->format('M j');
            $income[] = round(($sales[$k] ?? 0) + ($other[$k] ?? 0), 2);
            $expenses[] = round($exp[$k] ?? 0, 2);
        }

        return compact('labels', 'income', 'expenses');
    }

    /** Monthly summary for the last N months (sales, expenses, net). */
    public static function monthly(int $months = 6): array
    {
        $start = today()->startOfMonth()->subMonthsNoOverflow($months - 1);
        $sales = Order::where('payment_status', 'paid')->where('created_at', '>=', $start)
            ->selectRaw("to_char(created_at, 'YYYY-MM') as m, SUM(total_amount) as t")->groupBy('m')->pluck('t', 'm');
        $exp = Expense::where('expense_date', '>=', $start->toDateString())
            ->selectRaw("to_char(expense_date, 'YYYY-MM') as m, SUM(amount) as t")->groupBy('m')->pluck('t', 'm');
        $inc = Income::where('income_date', '>=', $start->toDateString())
            ->selectRaw("to_char(income_date, 'YYYY-MM') as m, SUM(amount) as t")->groupBy('m')->pluck('t', 'm');

        $out = [];
        for ($m = $start->copy(); $m->lte(today()); $m->addMonthNoOverflow()) {
            $k = $m->format('Y-m');
            $s = (float) ($sales[$k] ?? 0);
            $i = (float) ($inc[$k] ?? 0);
            $e = (float) ($exp[$k] ?? 0);
            $out[] = ['label' => $m->format('M Y'), 'short' => $m->format('M'), 'sales' => $s, 'other_income' => $i, 'expenses' => $e, 'net' => round($s + $i - $e, 2)];
        }

        return $out;
    }

    /** Staff leaderboard: orders handled and sales rung up. */
    public function cashierPerformance(): Collection
    {
        return $this->paidOrders()
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->selectRaw('users.name, COUNT(orders.id) as orders, SUM(orders.total_amount) as sales')
            ->groupBy('users.name')->orderByDesc('sales')->get()
            ->map(fn ($r) => ['name' => $r->name, 'orders' => (int) $r->orders, 'sales' => round((float) $r->sales, 2)]);
    }

    public static function delta(float $current, float $previous): ?float
    {
        return pct_change($current, $previous);
    }
}
