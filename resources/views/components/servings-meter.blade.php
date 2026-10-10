@props(['usage'])
{{--
    "Drinks served" estimate for an open unit (Sales ↔ Inventory link).
    Always labelled as an estimate: the actual container must be checked.
--}}
@php
    $used = $usage->servings_used;
    $expected = $usage->expected_servings;
    $pct = $usage->percentUsed();
    $stage = $usage->servingStage();
    $bar = match ($stage) { 'flagged', 'reached' => 'bg-danger-600', 'alert' => 'bg-warn-600', default => 'bg-leaf-600' };
@endphp
@if ($expected || $used > 0)
    <div {{ $attributes->merge(['class' => 'mt-1.5 max-w-52']) }}>
        <p class="text-xs text-sage-600">
            <span class="font-semibold text-ink">{{ \App\Models\ItemUsage::servingsLabel($used) }}</span> drink{{ $used == 1 ? '' : 's' }} served
            @if ($expected)<span class="text-sage-500">· usually ~{{ (int) round($expected) }}</span>@endif
        </p>
        @if ($expected)
            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-sage-100" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min(100, $pct) }}"
                 aria-label="Estimated share of a unit used: {{ min(100, $pct) }} percent">
                <div class="h-full rounded-full {{ $bar }}" style="width: {{ min(100, max(4, $pct)) }}%"></div>
            </div>
            @if ($stage === 'flagged')
                <p class="mt-1 text-xs font-semibold text-danger-700">{{ $usage->resolved_at ? 'Reviewed by '.$usage->resolver?->name : 'Far past usual — manager review' }}</p>
            @elseif ($stage === 'reached')
                <p class="mt-1 text-xs font-semibold text-danger-700">Usual count reached — check it</p>
            @elseif ($stage === 'alert')
                <p class="mt-1 text-xs font-semibold text-warn-700">{{ $usage->acknowledged_at ? 'Checked by '.$usage->acknowledger?->name : 'Running low? Check the container' }}</p>
            @endif
        @endif
    </div>
@endif
