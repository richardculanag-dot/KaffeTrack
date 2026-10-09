<div class="space-y-6">
    <x-page-header description="Archived records are hidden everywhere in KaffeTrack but are never deleted from the database. Restore anything that was archived by mistake." />

    <div class="grid gap-6 lg:grid-cols-4">
        <nav class="panel h-fit p-2" aria-label="Archive sections">
            @foreach (\App\Livewire\Admin\Archive::SOURCES as $key => [$label])
                <button type="button" wire:click="$set('source', '{{ $key }}')" aria-current="{{ $source === $key ? 'true' : 'false' }}"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition {{ $source === $key ? 'bg-leaf-50 text-leaf-800' : 'text-sage-700 hover:bg-sage-50' }}">
                    {{ $label }}
                    <span class="rounded-full px-2 text-xs {{ $counts[$key] ? 'bg-crema-100 text-crema-700' : 'text-sage-400' }}">{{ $counts[$key] }}</span>
                </button>
            @endforeach
        </nav>

        <section class="panel overflow-hidden lg:col-span-3">
            <div class="panel-header"><h2 class="panel-title">{{ \App\Livewire\Admin\Archive::SOURCES[$source][0] }}</h2></div>
            <div class="relative">
                <div wire:loading.flex wire:target="source" class="absolute inset-0 z-10 items-center justify-center bg-white/60"><x-spinner class="size-6 text-leaf-600" /></div>
                <ul class="divide-y divide-sage-100">
                    @forelse ($records as $r)
                        <li wire:key="ar-{{ $source }}-{{ $r->id }}" class="flex items-center gap-4 px-5 py-3.5">
                            <span class="grid size-9 place-items-center rounded-lg bg-sage-100 text-sage-500"><x-icon name="archive" class="size-4" /></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold">
                                    {{ $r->name ?? $r->description ?? $r->source }}
                                    @if (isset($r->amount))<span class="font-normal text-sage-600">· {{ peso($r->amount) }}</span>@endif
                                </p>
                                <p class="text-xs text-sage-500">Archived {{ $r->deleted_at->format('M j, Y g:i A') }} ({{ $r->deleted_at->diffForHumans() }})</p>
                            </div>
                            <button type="button" class="btn-secondary btn-sm"
                                    @click="$confirm({ title: @js('Restore '.($r->name ?? $r->description ?? $r->source).'?'), message: 'It will reappear in KaffeTrack exactly as it was when archived.', confirmText: 'Restore' }, () => $wire.restore('{{ $source }}', {{ $r->id }}))">
                                <x-icon name="restore" class="size-4" /> Restore
                            </button>
                        </li>
                    @empty
                        <li><x-empty-state icon="archive" title="Nothing archived here" description="Archived {{ strtolower(\App\Livewire\Admin\Archive::SOURCES[$source][0]) }} will appear in this list." /></li>
                    @endforelse
                </ul>
            </div>
        </section>
    </div>
</div>
