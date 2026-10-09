@props(['placeholder' => 'Search…'])
{{-- Debounced live search; press "/" anywhere to focus it. --}}
<div class="relative w-full sm:w-72">
    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-sage-400" />
    <input type="search" data-search placeholder="{{ $placeholder }}" aria-label="{{ $placeholder }}"
           {{ $attributes->merge(['class' => 'field py-2 pl-9 pr-9']) }}>
    <kbd class="pointer-events-none absolute right-2.5 top-1/2 hidden -translate-y-1/2 rounded border border-sage-200 px-1.5 text-[11px] text-sage-400 sm:block">/</kbd>
</div>
