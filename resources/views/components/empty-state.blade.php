@props(['icon' => 'box-open', 'title', 'description' => null])
{{-- An empty screen is an invitation to act: say what's missing and offer the next step. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-center px-6 py-14 text-center']) }}>
    <span class="grid size-14 place-items-center rounded-2xl bg-sage-100 text-sage-500">
        <x-icon :name="$icon" class="size-7" stroke="1.5" />
    </span>
    <p class="mt-4 font-display text-lg font-semibold">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-sage-500">{{ $description }}</p>
    @endif
    @if (trim($slot))
        <div class="mt-5 flex gap-2">{{ $slot }}</div>
    @endif
</div>
