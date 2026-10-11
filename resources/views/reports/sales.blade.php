<table class="kpis">
    <tr>
        <td><div class="label">Net sales</div><div class="value">{{ peso($summary['sales']) }}</div></td>
        <td><div class="label">Paid orders</div><div class="value">{{ number_format($summary['orders']) }}</div></td>
        <td><div class="label">Average ticket</div><div class="value">{{ peso($summary['avg_ticket']) }}</div></td>
        <td><div class="label">Items sold</div><div class="value">{{ number_format($summary['items']) }}</div></td>
    </tr>
    <tr>
        <td><div class="label">Discounts given</div><div class="value">{{ peso($summary['discounts']) }}</div></td>
        <td><div class="label">VAT collected</div><div class="value">{{ peso($summary['vat']) }}</div></td>
        <td><div class="label">Refunds ({{ $summary['refund_count'] }})</div><div class="value neg">{{ peso($summary['refunds']) }}</div></td>
        <td><div class="label">Cancelled orders</div><div class="value">{{ number_format($summary['cancelled']) }}</div></td>
    </tr>
</table>
<p class="muted small">Net sales = paid orders after discounts (VAT-inclusive). Refunded orders are excluded from net sales and listed separately.</p>

<h2>Daily sales</h2>
<table>
    <thead><tr><th>Date</th><th class="r">Orders</th><th class="r">Gross</th><th class="r">Discounts</th><th class="r">VAT removed</th><th class="r">VAT-exempt sales</th><th class="r">VAT</th><th class="r">Net sales</th></tr></thead>
    <tbody>
        @forelse ($daily as $d)
            <tr><td>{{ $d['date']->format('D, M j') }}</td><td class="r">{{ $d['orders'] }}</td><td class="r">{{ peso($d['gross']) }}</td><td class="r">{{ peso($d['discounts']) }}</td><td class="r">{{ peso($d['vat_removed']) }}</td><td class="r">{{ peso($d['vat_exempt']) }}</td><td class="r">{{ peso($d['vat']) }}</td><td class="r">{{ peso($d['net']) }}</td></tr>
        @empty
            <tr><td colspan="8" class="c muted">No paid orders in this period.</td></tr>
        @endforelse
        @if ($daily->count() > 1)
            <tr class="total"><td>Total</td><td class="r">{{ $daily->sum('orders') }}</td><td class="r">{{ peso($daily->sum('gross')) }}</td><td class="r">{{ peso($daily->sum('discounts')) }}</td><td class="r">{{ peso($daily->sum('vat_removed')) }}</td><td class="r">{{ peso($daily->sum('vat_exempt')) }}</td><td class="r">{{ peso($daily->sum('vat')) }}</td><td class="r">{{ peso($daily->sum('net')) }}</td></tr>
        @endif
    </tbody>
</table>
<p class="muted small">Gross − Discounts − VAT removed = Net sales. VAT is removed from Senior Citizen/PWD sales before their discount (they are VAT-exempt).</p>

<table style="margin-top:6px"><tr>
    <td style="width:50%; padding:0 8px 0 0; border:0">
        <h2>Payment methods</h2>
        <table>
            <thead><tr><th>Method</th><th class="r">Transactions</th><th class="r">Amount</th><th class="r">Share</th></tr></thead>
            <tbody>
                @php $payTotal = max(0.01, $payments->sum('total')); @endphp
                @foreach ($payments as $p)
                    <tr><td>{{ $p['method'] }}</td><td class="r">{{ $p['count'] }}</td><td class="r">{{ peso($p['total']) }}</td><td class="r">{{ round($p['total'] / $payTotal * 100, 1) }}%</td></tr>
                @endforeach
            </tbody>
        </table>
    </td>
    <td style="width:50%; padding:0 0 0 8px; border:0">
        <h2>Order types</h2>
        <table>
            <thead><tr><th>Type</th><th class="r">Orders</th><th class="r">Amount</th></tr></thead>
            <tbody>
                @foreach ($types as $t)
                    <tr><td>{{ $t['type'] }}</td><td class="r">{{ $t['count'] }}</td><td class="r">{{ peso($t['total']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </td>
</tr></table>

<h2>Discounts applied</h2>
<table>
    <thead><tr><th>Discount</th><th class="r">Orders</th><th class="r">Amount given</th></tr></thead>
    <tbody>
        @forelse ($discounts as $d)
            <tr><td>{{ $d->discount_name }}</td><td class="r">{{ $d->n }}</td><td class="r">{{ peso($d->total) }}</td></tr>
        @empty
            <tr><td colspan="3" class="c muted">No discounts in this period.</td></tr>
        @endforelse
    </tbody>
</table>

<h2>Cashier performance</h2>
<table>
    <thead><tr><th>Cashier</th><th class="r">Orders</th><th class="r">Sales</th><th class="r">Avg. ticket</th></tr></thead>
    <tbody>
        @foreach ($cashiers as $c)
            <tr><td>{{ $c['name'] }}</td><td class="r">{{ $c['orders'] }}</td><td class="r">{{ peso($c['sales']) }}</td><td class="r">{{ peso($c['orders'] ? $c['sales'] / $c['orders'] : 0) }}</td></tr>
        @endforeach
    </tbody>
</table>

<h2>Refunds</h2>
<table>
    <thead><tr><th>Date</th><th>Order</th><th>Method</th><th>Reason</th><th>Processed / approved</th><th class="r">Amount</th></tr></thead>
    <tbody>
        @forelse ($refunds as $r)
            <tr><td>{{ $r->created_at->format('M j, g:i A') }}</td><td>{{ $r->order->order_number }}</td><td>{{ ucfirst($r->method) }}</td><td>{{ $r->reason }}</td><td>{{ $r->processor->name }} / {{ $r->approver->name }}</td><td class="r neg">{{ peso($r->amount) }}</td></tr>
        @empty
            <tr><td colspan="6" class="c muted">No refunds in this period.</td></tr>
        @endforelse
    </tbody>
</table>
