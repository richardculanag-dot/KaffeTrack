@props(['target' => null, 'variant' => 'primary'])
{{--
    Button that shows a spinner and disables itself while its Livewire action runs —
    immediate feedback (visibility of system status) and no accidental double submits.
    <x-submit target="save">Save product</x-submit>
--}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => "btn-{$variant}"]) }}
        wire:loading.attr="disabled" @if ($target) wire:target="{{ $target }}" @endif>
    <svg wire:loading @if ($target) wire:target="{{ $target }}" @endif class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/>
        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
    </svg>
    {{ $slot }}
</button>
