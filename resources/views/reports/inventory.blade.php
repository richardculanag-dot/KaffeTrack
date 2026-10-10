<table class="kpis">
    <tr>
        <td><div class="label">Stock value (at cost)</div><div class="value">{{ peso($stock_value) }}</div></td>
        <td><div class="label">Items tracked</div><div class="value">{{ $rows->count() }}</div></td>
        <td><div class="label">At / below reorder level</div><div class="value warn">{{ $low }}</div></td>
        <td><div class="label">Waste & expired (cost)</div><div class="value neg">{{ peso($loss_cost) }}</div></td>
    </tr>
</table>
<div class="note">KaffeTrack uses an open-container model: a <b>stock out</b> moves one sealed unit from storage to the bar, where it stays open until it is marked depleted. Quantities are whole units.</div>

<h2>Stock on hand (as of {{ now()->format('M j, Y g:i A') }}) and movement in the period</h2>
<table>
    <thead><tr><th>Item</th><th>Unit</th><th class="r">On hand</th><th class="r">Reorder</th><th class="r">In</th><th class="r">Out</th><th class="r">Adj.</th><th class="r">Lost</th><th>Next expiry</th><th class="r">Value</th></tr></thead>
    <tbody>
        @php $currentCat = null; @endphp
        @foreach ($rows as $r)
            @if ($currentCat !== $r['item']->category->name)
                @php $currentCat = $r['item']->category->name; @endphp
                <tr class="sub"><td colspan="10">{{ $currentCat }}</td></tr>
            @endif
            <tr>
                <td>{{ $r['item']->name }}@if ($r['item']->trashed()) <span class="muted small">(archived)</span>@endif</td>
                <td class="muted">{{ $r['item']->unit }}</td>
                <td class="r">
                    {{ $r['item']->quantity_on_hand }}
                    @if ($r['item']->quantity_on_hand <= 0)<span class="pill pill-red">OUT</span>@elseif ($r['item']->isLowStock())<span class="pill pill-amber">LOW</span>@endif
                </td>
                <td class="r muted">{{ $r['item']->reorder_level }}</td>
                <td class="r pos">{{ $r['in'] ?: '' }}</td>
                <td class="r">{{ $r['out'] ?: '' }}</td>
                <td class="r">{{ $r['adjust'] ? ($r['adjust'] > 0 ? '+' : '').$r['adjust'] : '' }}</td>
                <td class="r neg">{{ $r['loss'] ?: '' }}</td>
                <td>@if ($r['next_expiry']){{ $r['next_expiry']->format('M j, Y') }}@if ($r['next_expiry']->lt(today())) <span class="pill pill-red">EXPIRED</span>@endif @else <span class="muted">—</span>@endif</td>
                <td class="r">{{ peso($r['value']) }}</td>
            </tr>
        @endforeach
        <tr class="total"><td colspan="9">Total stock value</td><td class="r">{{ peso($stock_value) }}</td></tr>
    </tbody>
</table>

<h2>Deliveries received</h2>
<table>
    <thead><tr><th>Date</th><th>Reference</th><th>Supplier</th><th>Invoice</th><th class="r">Total cost</th></tr></thead>
    <tbody>
        @forelse ($deliveries as $d)
            <tr><td>{{ $d->received_date->format('M j, Y') }}</td><td>{{ $d->reference_no }}</td><td>{{ $d->supplier->name }}</td><td>{{ $d->invoice_number ?: '—' }}</td><td class="r">{{ peso($d->total_cost) }}</td></tr>
        @empty
            <tr><td colspan="5" class="c muted">No deliveries in this period.</td></tr>
        @endforelse
        @if ($deliveries->count())<tr class="total"><td colspan="4">Total purchases</td><td class="r">{{ peso($deliveries->sum('total_cost')) }}</td></tr>@endif
    </tbody>
</table>

<h2>Adjustments & write-offs</h2>
<table>
    <thead><tr><th>Date</th><th>Item</th><th>Type</th><th class="r">Qty</th><th>Reason</th><th>By</th></tr></thead>
    <tbody>
        @forelse ($writeoffs as $w)
            <tr><td>{{ $w->created_at->format('M j, g:i A') }}</td><td>{{ $w->item->name }}</td><td>{{ $w->typeLabel() }}</td><td class="r {{ $w->quantity < 0 ? 'neg' : 'pos' }}">{{ $w->quantity > 0 ? '+' : '' }}{{ $w->quantity }}</td><td>{{ $w->reason }}</td><td>{{ $w->user->name }}</td></tr>
        @empty
            <tr><td colspan="6" class="c muted">No adjustments or write-offs in this period.</td></tr>
        @endforelse
    </tbody>
</table>

<h2>End-of-shift stock checks</h2>
@php
    $differences = $stock_checks->filter(fn ($c) => $c->outcome() !== 'Matched');
    $shiftCount = $stock_checks->pluck('cash_shift_id')->unique()->count();
@endphp
<p class="small">{{ $stock_checks->count() }} item count(s) across {{ $shiftCount }} shift(s): {{ $stock_checks->count() - $differences->count() }} matched the records, {{ $differences->count() }} did not. Only the differences are listed.</p>
<table>
    <thead><tr><th>Checked</th><th>Item</th><th class="r">System had</th><th class="r">Counted</th><th>Recorded</th><th>By</th></tr></thead>
    <tbody>
        @forelse ($differences as $c)
            <tr>
                <td>{{ $c->checked_at->format('M j, g:i A') }}<br><span class="muted small">Shift #{{ $c->cash_shift_id }}</span></td>
                <td>{{ $c->item?->name }} <span class="muted small">({{ $c->item?->unit }})</span></td>
                <td class="r">{{ $c->expected_sealed }}</td>
                <td class="r {{ $c->difference() < 0 ? 'neg' : ($c->difference() > 0 ? 'warn' : '') }}">{{ $c->counted_sealed }}</td>
                <td>{{ $c->outcome() }}{{ $c->open_unit_finished === true ? ' · open unit finished' : '' }}@if ($c->note)<br><span class="muted small">{{ $c->note }}</span>@endif</td>
                <td>{{ $c->checker?->name }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="c muted">{{ $stock_checks->isEmpty() ? 'No end-of-shift stock checks in this period.' : 'Every count matched the records.' }}</td></tr>
        @endforelse
    </tbody>
</table>
<p class="muted small">At each drawer close, staff count the sealed units left for every item that shift's sales used. Units missing from the shelf are recorded as stock-outs (or waste, if staff say so) under their name; a count higher than the system's changes nothing and is noted for the manager. "Matched" means the shelf agreed with the records.</p>

