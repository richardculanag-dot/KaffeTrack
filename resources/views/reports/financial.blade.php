<table class="kpis">
    <tr>
        <td><div class="label">Total income</div><div class="value">{{ peso($summary['sales'] + $summary['other_income']) }}</div></td>
        <td><div class="label">Total expenses</div><div class="value">{{ peso($summary['expenses']) }}</div></td>
        <td><div class="label">Net income</div><div class="value {{ $summary['net_income'] < 0 ? 'neg' : 'pos' }}">{{ peso($summary['net_income']) }}</div></td>
        <td><div class="label">Net margin</div><div class="value">{{ $margin === null ? '—' : $margin.'%' }}</div></td>
    </tr>
</table>

<h2>Statement of income</h2>
<table>
    <tbody>
        <tr class="sub"><td colspan="2">Revenue</td></tr>
        <tr><td style="padding-left:16px">Gross sales (before discounts)</td><td class="r">{{ peso($gross_sales) }}</td></tr>
        <tr><td style="padding-left:16px">Less: discounts and VAT removed for VAT-exempt sales</td><td class="r neg">({{ peso($gross_sales - $summary['sales']) }})</td></tr>
        <tr><td style="padding-left:16px"><b>Net sales</b> <span class="muted small">(VAT-inclusive; VAT collected {{ peso($summary['vat']) }})</span></td><td class="r"><b>{{ peso($summary['sales']) }}</b></td></tr>
        <tr><td style="padding-left:16px">Other income</td><td class="r">{{ peso($summary['other_income']) }}</td></tr>
        <tr class="total"><td>Total income</td><td class="r">{{ peso($summary['sales'] + $summary['other_income']) }}</td></tr>

        <tr class="sub"><td colspan="2">Expenses</td></tr>
        @forelse ($expenses_by_category as $e)
            <tr><td style="padding-left:16px">{{ $e['name'] }} <span class="muted small">({{ $e['count'] }})</span></td><td class="r">{{ peso($e['total']) }}</td></tr>
        @empty
            <tr><td style="padding-left:16px" class="muted">No expenses recorded</td><td class="r">{{ peso(0) }}</td></tr>
        @endforelse
        <tr class="total"><td>Total expenses</td><td class="r">({{ peso($summary['expenses']) }})</td></tr>

        <tr class="total"><td style="font-size:11px">NET INCOME</td><td class="r {{ $summary['net_income'] < 0 ? 'neg' : 'pos' }}" style="font-size:11px">{{ peso($summary['net_income']) }}</td></tr>
    </tbody>
</table>
<p class="muted small">Refunded orders ({{ peso($summary['refunds']) }}) are not included in net sales. Inventory purchases are expensed when the delivery is received (cash basis).</p>

<h2>Other income</h2>
<table>
    <thead><tr><th>Date</th><th>Source</th><th>Received by</th><th class="r">Amount</th></tr></thead>
    <tbody>
        @forelse ($incomes as $i)
            <tr><td>{{ $i->income_date->format('M j, Y') }}</td><td>{{ $i->source }}</td><td>{{ \App\Models\Expense::PAYMENT_METHODS[$i->payment_method] ?? '—' }}</td><td class="r">{{ peso($i->amount) }}</td></tr>
        @empty
            <tr><td colspan="4" class="c muted">None in this period.</td></tr>
        @endforelse
    </tbody>
</table>

<h2>Expense details</h2>
<table>
    <thead><tr><th>Date</th><th>Description</th><th>Category</th><th>Paid by</th><th>Reference</th><th class="r">Amount</th></tr></thead>
    <tbody>
        @forelse ($expenses as $e)
            <tr><td>{{ $e->expense_date->format('M j') }}</td><td>{{ $e->description }}</td><td>{{ $e->category->name }}</td><td>{{ \App\Models\Expense::PAYMENT_METHODS[$e->payment_method] ?? '—' }}</td><td class="muted">{{ $e->reference_number ?: '—' }}</td><td class="r">{{ peso($e->amount) }}</td></tr>
        @empty
            <tr><td colspan="6" class="c muted">No expenses in this period.</td></tr>
        @endforelse
        @if ($expenses->count())<tr class="total"><td colspan="5">Total</td><td class="r">{{ peso($expenses->sum('amount')) }}</td></tr>@endif
    </tbody>
</table>
