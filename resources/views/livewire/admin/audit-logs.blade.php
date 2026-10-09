<div class="space-y-6">
    <x-page-header description="A permanent record of every action in KaffeTrack — sign-ins, sales, refunds, stock movements and every change to records. Entries cannot be edited or deleted.">
        <x-slot:actions>
            <a href="{{ route('reports.index', ['type' => 'audit', 'from' => $from, 'to' => $to, 'module' => $module ?: null]) }}" wire:navigate class="btn-secondary"><x-icon name="download" class="size-4" /> Export PDF</a>
        </x-slot:actions>
    </x-page-header>

    <section class="panel overflow-hidden">
        <div class="space-y-3 border-b border-sage-200 px-5 py-4">
            <div class="flex flex-wrap items-center gap-2">
                <x-search-input wire:model.live.debounce.350ms="search" placeholder="Search descriptions" />
                <select wire:model.live="module" class="field w-auto py-2 pr-8" aria-label="Module">
                    <option value="">All modules</option>
                    @foreach (\App\Models\AuditLog::MODULES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                </select>
                <select wire:model.live="user" class="field w-auto py-2 pr-8" aria-label="User">
                    <option value="">All users</option>
                    @foreach ($users as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <select wire:model.live="event" class="field w-auto py-2 pr-8" aria-label="Action">
                    <option value="">All actions</option>
                    @foreach ($events as $e)<option value="{{ $e }}">{{ ucfirst(str_replace('_', ' ', $e)) }}</option>@endforeach
                </select>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <input type="date" wire:model.live="from" max="{{ $to }}" class="field w-auto py-2" aria-label="From date">
                <span class="text-sage-400">to</span>
                <input type="date" wire:model.live="to" min="{{ $from }}" max="{{ today()->toDateString() }}" class="field w-auto py-2" aria-label="To date">
                <span class="ml-auto text-sm text-sage-500">{{ number_format($logs->total()) }} entr{{ $logs->total() === 1 ? 'y' : 'ies' }}</span>
                @if ($search || $module || $user || $event)<button type="button" class="btn-ghost btn-sm" wire:click="clearFilters">Clear filters</button>@endif
            </div>
        </div>

        <div class="relative overflow-x-auto">
            <div wire:loading.flex wire:target="search, module, user, event, from, to, gotoPage, nextPage, previousPage" class="absolute inset-0 z-10 items-center justify-center bg-white/60"><x-spinner class="size-6 text-leaf-600" /></div>
            <table class="table">
                <thead><tr><th>When</th><th>User</th><th>Module</th><th>Action</th><th>Description</th><th></th></tr></thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr wire:key="log-{{ $log->id }}">
                            <td class="whitespace-nowrap"><p>{{ $log->created_at->format('M j, g:i:s A') }}</p><p class="text-xs text-sage-500">{{ $log->created_at->diffForHumans() }}</p></td>
                            <td class="whitespace-nowrap">{{ $log->user_name ?? 'System' }}</td>
                            <td><span class="badge-gray">{{ \App\Models\AuditLog::MODULES[$log->module] ?? $log->module }}</span></td>
                            <td><span class="badge-{{ $log->tone() }}">{{ ucfirst(str_replace('_', ' ', $log->event)) }}</span></td>
                            <td class="max-w-md">{{ $log->description }}</td>
                            <td class="text-right">
                                @if ($log->old_values || $log->new_values)
                                    <button type="button" class="btn-ghost btn-sm" wire:click="$set('viewingId', {{ $log->id }})">Changes</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-empty-state icon="shield" title="No activity found" description="Try a wider date range or clear the filters." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($logs->hasPages())<div class="border-t border-sage-200 px-5 py-3">{{ $logs->links() }}</div>@endif
    </section>

    <x-modal wire:model="viewingId" title="What changed" :description="$viewing ? $viewing->description.' — '.$viewing->user_name.', '.$viewing->created_at->format('M j, Y g:i A') : null" size="lg" icon="shield">
        @if ($viewing)
            @php
                $old = $viewing->old_values ?? [];
                $new = $viewing->new_values ?? [];
                $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
                $fmt = fn ($v) => is_array($v) ? implode(', ', array_map(fn ($x) => is_array($x) ? json_encode($x) : $x, $v)) : (is_bool($v) ? ($v ? 'Yes' : 'No') : ($v === null || $v === '' ? '—' : $v));
            @endphp
            <div class="overflow-x-auto rounded-xl border border-sage-200">
                <table class="table">
                    <thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead>
                    <tbody>
                        @foreach ($keys as $key)
                            <tr>
                                <td class="font-semibold">{{ ucfirst(str_replace('_', ' ', $key)) }}</td>
                                <td class="text-danger-700">@if (array_key_exists($key, $old))<span class="line-through decoration-danger-600/40">{{ $fmt($old[$key]) }}</span>@else<span class="text-sage-400">—</span>@endif</td>
                                <td class="text-leaf-800">{{ array_key_exists($key, $new) ? $fmt($new[$key]) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-sage-500">IP {{ $viewing->ip_address ?? '—' }} · {{ \Illuminate\Support\Str::limit($viewing->user_agent, 90) }}</p>
        @endif
    </x-modal>
</div>
