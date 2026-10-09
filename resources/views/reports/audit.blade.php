<table class="kpis">
    <tr>
        <td><div class="label">Logged actions</div><div class="value">{{ number_format($total) }}</div></td>
        <td colspan="3">
            <div class="label">By module</div>
            <div style="margin-top:3px">
                @foreach ($by_module as $m => $n)
                    <span class="pill pill-green">{{ \App\Models\AuditLog::MODULES[$m] ?? $m }}: {{ number_format($n) }}</span>
                @endforeach
            </div>
        </td>
    </tr>
</table>
@if ($total > $logs->count())
    <div class="note">Showing the latest {{ number_format($logs->count()) }} of {{ number_format($total) }} entries. Narrow the date range or choose a module to export the rest.</div>
@endif

<h2>Audit trail{{ $module ? ' — '.(\App\Models\AuditLog::MODULES[$module] ?? $module) : '' }}</h2>
<table>
    <thead><tr><th style="width:15%">When</th><th style="width:15%">User</th><th style="width:10%">Module</th><th style="width:11%">Action</th><th>Description</th><th style="width:10%">IP</th></tr></thead>
    <tbody>
        @forelse ($logs as $log)
            <tr>
                <td class="small">{{ $log->created_at->format('M j, Y g:i:s A') }}</td>
                <td>{{ $log->user_name ?? 'System' }}</td>
                <td class="small">{{ \App\Models\AuditLog::MODULES[$log->module] ?? $log->module }}</td>
                <td class="small"><span class="pill {{ $log->tone() === 'red' ? 'pill-red' : ($log->tone() === 'amber' ? 'pill-amber' : 'pill-green') }}">{{ str_replace('_', ' ', $log->event) }}</span></td>
                <td>{{ $log->description }}</td>
                <td class="small muted">{{ $log->ip_address ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="c muted">No activity in this period.</td></tr>
        @endforelse
    </tbody>
</table>
