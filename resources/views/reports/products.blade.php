<table class="kpis">
    <tr>
        <td><div class="label">Net sales</div><div class="value">{{ peso($summary['sales']) }}</div></td>
        <td><div class="label">Items sold</div><div class="value">{{ number_format($total_qty) }}</div></td>
        <td><div class="label">Products sold</div><div class="value">{{ $products->count() }}</div></td>
        <td><div class="label">Best seller</div><div class="value" style="font-size:10.5px">{{ $products->first()['name'] ?? '—' }}</div></td>
    </tr>
</table>
<p class="muted small">Revenue below is the line total before order-level discounts, so it can be slightly higher than net sales.</p>

<h2>By category</h2>
<table>
    <thead><tr><th>Category</th><th class="r">Qty</th><th class="r">Revenue</th><th style="width:34%">Share</th></tr></thead>
    <tbody>
        @php $catTotal = max(0.01, $categories->sum('revenue')); @endphp
        @foreach ($categories as $c)
            @php $pct = $c['revenue'] / $catTotal * 100; @endphp
            <tr><td>{{ $c['name'] }}</td><td class="r">{{ number_format($c['qty']) }}</td><td class="r">{{ peso($c['revenue']) }}</td>
                <td><div class="bar" style="width: {{ max(1, round($pct)) }}%"></div><span class="small muted">{{ round($pct, 1) }}%</span></td></tr>
        @endforeach
    </tbody>
</table>

<h2>By product (ranked by quantity)</h2>
<table>
    <thead><tr><th class="c" style="width:22px">#</th><th>Product</th><th class="r">Qty sold</th><th class="r">Revenue</th><th class="r">Avg. price</th><th class="r">Revenue share</th></tr></thead>
    <tbody>
        @forelse ($products as $i => $p)
            <tr><td class="c muted">{{ $i + 1 }}</td><td>{{ $p['name'] }}</td><td class="r">{{ number_format($p['qty']) }}</td><td class="r">{{ peso($p['revenue']) }}</td><td class="r">{{ peso($p['qty'] ? $p['revenue'] / $p['qty'] : 0) }}</td><td class="r">{{ round($p['revenue'] / $total_revenue * 100, 1) }}%</td></tr>
        @empty
            <tr><td colspan="6" class="c muted">No products sold in this period.</td></tr>
        @endforelse
        @if ($products->count())
            <tr class="total"><td></td><td>Total</td><td class="r">{{ number_format($total_qty) }}</td><td class="r">{{ peso($products->sum('revenue')) }}</td><td></td><td class="r">100%</td></tr>
        @endif
    </tbody>
</table>
