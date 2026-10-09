@props(['title' => null, 'description' => null, 'size' => 'md', 'icon' => null])
@php
    $width = ['sm' => 'max-w-md', 'md' => 'max-w-lg', 'lg' => 'max-w-2xl', 'xl' => 'max-w-4xl'][$size] ?? 'max-w-lg';
    $model = $attributes->wire('model');
@endphp
{{--
    Usage: <x-modal wire:model="showForm" title="Add product" size="lg"> … <x-slot:footer>…</x-slot:footer></x-modal>
    Focus is trapped inside while open and returns to the trigger on close; Esc / backdrop click closes.
--}}
<div x-data="{ show: @entangle($model) }" x-show="show" x-cloak
     class="fixed inset-0 z-[70] flex items-end justify-center sm:items-center sm:p-4"
     @keydown.escape.window="show && (show = false)" role="dialog" aria-modal="true">
    <div x-show="show" x-transition.opacity.duration.200ms class="absolute inset-0 bg-pine-950/45" @click="show = false"></div>

    <div x-show="show" x-trap.noscroll.inert="show"
         x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="opacity-0 translate-y-6 sm:translate-y-2 sm:scale-[.98]" x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-[.98]"
         {{ $attributes->except(['wire:model', 'wire:model.live'])->merge(['class' => "relative flex max-h-[92vh] w-full {$width} flex-col rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl"]) }}>
        @if ($title)
            <div class="flex items-start gap-3 border-b border-sage-200 px-6 py-4">
                @if ($icon)
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-leaf-100 text-leaf-700"><x-icon :name="$icon" /></span>
                @endif
                <div class="min-w-0 flex-1">
                    <h2 class="font-display text-lg font-semibold">{{ $title }}</h2>
                    @if ($description)
                        <p class="mt-0.5 text-sm text-sage-500">{{ $description }}</p>
                    @endif
                </div>
                <button type="button" class="btn-icon -mr-2 -mt-1" @click="show = false" aria-label="Close dialog">
                    <x-icon name="x" />
                </button>
            </div>
        @endif

        <div class="flex-1 overflow-y-auto px-6 py-5">
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="flex flex-col-reverse gap-2 border-t border-sage-200 bg-sage-50/60 px-6 py-4 sm:flex-row sm:justify-end sm:rounded-b-2xl">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
