<div class="flex items-center gap-2" wire:poll.60s.visible>
    {{-- Cash drawer status --}}
    <a href="{{ route('shifts.index') }}" wire:navigate
       class="hidden items-center gap-2 rounded-full border px-3 py-1.5 text-[13px] font-semibold md:inline-flex {{ $shift ? 'border-leaf-200 bg-leaf-50 text-leaf-800' : 'border-sage-300 bg-white text-sage-600' }}"
       title="{{ $shift ? 'Opened by '.$shift->opener->name.' at '.$shift->opened_at->format('g:i A') : 'No shift is open' }}">
        <span class="relative flex size-2">
            @if ($shift)
                <span class="absolute inline-flex size-full animate-ping rounded-full bg-leaf-400 opacity-60"></span>
            @endif
            <span class="relative inline-flex size-2 rounded-full {{ $shift ? 'bg-leaf-500' : 'bg-sage-400' }}"></span>
        </span>
        {{ $shift ? 'Drawer open since '.$shift->opened_at->format('g:i A') : 'Drawer closed' }}
    </a>

    {{-- Stock alerts --}}
    <div x-data="{ open: false }" class="relative">
        <button type="button" class="btn-icon relative" @click="open = !open" :aria-expanded="open"
                aria-label="Stock alerts{{ $alerts->count() ? ': '.$alerts->count().' item(s) need attention' : '' }}">
            <x-icon name="bell" />
            @if ($alerts->count())
                <span class="absolute right-1 top-1 grid min-w-4 place-items-center rounded-full bg-danger-600 px-1 text-[10px] font-bold leading-4 text-white">{{ $alerts->count() }}</span>
            @endif
        </button>
        <div x-show="open" x-cloak x-transition.origin.top.right @click.outside="open = false"
             class="absolute right-0 mt-2 w-80 overflow-hidden rounded-xl border border-sage-200 bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-sage-100 px-4 py-3">
                <p class="font-display font-semibold">Stock alerts</p>
                <a href="{{ route('inventory.items') }}" wire:navigate class="text-sm font-semibold text-leaf-700 hover:underline">View stock</a>
            </div>
            <ul class="max-h-80 divide-y divide-sage-100 overflow-y-auto">
                @forelse ($alerts as $alert)
                    <li class="flex gap-3 px-4 py-3">
                        <span class="mt-1.5 size-2 shrink-0 rounded-full {{ $alert['tone'] === 'red' ? 'bg-danger-600' : 'bg-warn-600' }}"></span>
                        <div class="min-w-0 text-sm">
                            <p class="font-semibold">{{ $alert['title'] }}
                                @isset($alert['tag'])<span class="ml-1 rounded-full bg-sage-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sage-600">{{ $alert['tag'] }}</span>@endisset
                            </p>
                            <p class="text-sage-600">{{ $alert['text'] }}</p>
                        </div>
                    </li>
                @empty
                    <li class="flex items-center gap-2.5 px-4 py-6 text-sm text-sage-600">
                        <x-icon name="check-circle" class="text-leaf-600" /> All stock levels look healthy.
                    </li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
