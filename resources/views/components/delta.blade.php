@props(['current', 'previous', 'invert' => false, 'label' => null, 'dark' => false])
{{-- Change vs the previous period. Arrow + sign + colour, so it reads without colour too. --}}
@php
    $pct = pct_change((float) $current, (float) $previous);
    $good = $pct === null ? null : ($invert ? $pct <= 0 : $pct >= 0);
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 text-[13px] font-semibold']) }}
      title="{{ $label ? 'Compared with '.$label : '' }}">
    @if ($pct === null)
        <span class="{{ $dark ? 'text-leaf-200/70' : 'text-sage-500' }}">No earlier data</span>
    @else
        <span @class([
            'inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5',
            'bg-leaf-100 text-leaf-800' => $good && ! $dark,
            'bg-danger-50 text-danger-700' => ! $good && ! $dark,
            'bg-white/15 text-leaf-100' => $dark,
        ])>
            <x-icon :name="$pct >= 0 ? 'trend-up' : 'trend-down'" class="size-3.5" stroke="2.25" />
            {{ $pct >= 0 ? '+' : '' }}{{ number_format($pct, 1) }}%
        </span>
        @if ($label)<span class="font-normal {{ $dark ? 'text-leaf-200/70' : 'text-sage-500' }}">vs {{ $label }}</span>@endif
    @endif
</span>
