@props(['description' => null])
{{-- Page intro line + primary actions. The page title itself lives in the top bar. --}}
<div {{ $attributes->merge(['class' => 'mb-6 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between']) }}>
    <p class="max-w-2xl min-w-0 text-[15px] text-sage-600">{{ $description }}</p>
    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
