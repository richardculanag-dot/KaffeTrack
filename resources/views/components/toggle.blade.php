@props(['label', 'description' => null])
{{-- Accessible switch bound to a boolean: <x-toggle label="Available for sale" wire:model="form.is_available" /> --}}
@php $id = 'toggle_'.md5($attributes->wire('model')->value() ?? $label); @endphp
<label for="{{ $id }}" class="flex cursor-pointer items-start justify-between gap-4 rounded-xl border border-sage-200 p-3.5 hover:bg-sage-50">
    <span>
        <span class="block text-sm font-semibold">{{ $label }}</span>
        @if ($description)<span class="block text-[13px] text-sage-500">{{ $description }}</span>@endif
    </span>
    <span class="relative mt-0.5 inline-flex shrink-0">
        <input id="{{ $id }}" type="checkbox" role="switch" class="peer sr-only" {{ $attributes }}>
        <span class="h-6 w-11 rounded-full bg-sage-300 transition peer-checked:bg-leaf-600 peer-focus-visible:ring-4 peer-focus-visible:ring-leaf-100"></span>
        <span class="absolute left-0.5 top-0.5 size-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
    </span>
</label>
