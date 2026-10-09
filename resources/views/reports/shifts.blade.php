<table class="kpis">
    <tr>
        <td><div class="label">Shifts</div><div class="value">{{ $shifts->count() }}</div></td>
        <td><div class="label">Balanced</div><div class="value pos">{{ $shifts->count() - $short_count - $over_count }}</div></td>
        <td><div class="label">Short / over</div><div class="value">{{ $short_count }} / {{ $over_count }}</div></td>
        <td><div class="label">Net variance</div><div class="value {{ $total_variance < 0 ? 'neg' : '' }}">{{ peso($total_variance) }}</div></td>
    </tr>
</table>
<div class="note">Expected cash = starting cash + cash sales − cash refunds + cash in − cash out. Card and online payments are not kept in the drawer.</div>

<h2>Shifts</h2>
<table>
    <thead><tr><th>Date</th><th>Opened / closed by</th><th class="r">Start</th><th class="r">Cash sales</th><th class="r">Card + online</th><th class="r">Refunds</th><th class="r">In / out</th><th class="r">Expected</th><th class="r">Counted</th><th class="r">Variance</th></tr></thead>
    <tbody>
        @forelse ($shifts as $row)
            @php $s = $row['shift']; $m = $row['summary']; @endphp
            <tr>
                <td>{{ $s->opened_at->format('D, M j') }}<br><span class="muted small">{{ $s->opened_at->format('g:i A') }}–{{ $s->closed_at?->format('g:i A') ?? 'open' }}</span></td>
                <td>{{ $s->opener->name }}<br><span class="muted small">{{ $s->closer?->name ?? '—' }}</span></td>
                <td class="r">{{ peso($m['opening_cash']) }}</td>
                <td class="r">{{ peso($m['cash_sales']) }}</td>
                <td class="r">{{ peso($m['card_sales'] + $m['online_sales']) }}</td>
                <td class="r">{{ peso($m['cash_refunds']) }}</td>
                <td class="r">{{ peso($m['cash_in']) }} / {{ peso($m['cash_out']) }}</td>
                <td class="r">{{ $s->status === 'closed' ? peso($s->expected_cash) : peso($m['expected_cash']) }}</td>
                <td class="r">{{ $s->counted_cash !== null ? peso($s->counted_cash) : '—' }}</td>
                <td class="r {{ (float) $s->variance < 0 ? 'neg' : ((float) $s->variance > 0 ? 'warn' : 'pos') }}">{{ $s->variance !== null ? ((float) $s->variance == 0 ? 'Balanced' : peso($s->variance)) : 'Open' }}</td>
            </tr>
            @if ($s->notes)<tr><td></td><td colspan="9" class="muted small">Note: {{ $s->notes }}</td></tr>@endif
        @empty
            <tr><td colspan="10" class="c muted">No shifts in this period.</td></tr>
        @endforelse
    </tbody>
</table>
