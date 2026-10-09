@props(['status'])
@php
    [$class, $label] = match ($status) {
        'pending' => ['badge-amber', 'Pending'],
        'ready' => ['badge-blue', 'Ready'],
        'completed' => ['badge-green', 'Completed'],
        'cancelled' => ['badge-gray', 'Cancelled'],
        'refunded' => ['badge-red', 'Refunded'],
        'paid' => ['badge-green', 'Paid'],
        'unpaid' => ['badge-amber', 'Unpaid'],
        'open' => ['badge-green', 'Open'],
        'closed' => ['badge-gray', 'Closed'],
        'in_use' => ['badge-blue', 'In use'],
        'depleted' => ['badge-gray', 'Depleted'],
        'discarded' => ['badge-red', 'Discarded'],
        'ok' => ['badge-green', 'In stock'],
        'low' => ['badge-amber', 'Low stock'],
        'out' => ['badge-red', 'Out of stock'],
        'active' => ['badge-green', 'Active'],
        'inactive' => ['badge-gray', 'Inactive'],
        default => ['badge-gray', ucfirst(str_replace('_', ' ', $status))],
    };
@endphp
{{-- Colour is never the only signal: every badge carries a text label (WCAG 1.4.1). --}}
<span {{ $attributes->merge(['class' => $class]) }}><span class="size-1.5 rounded-full bg-current opacity-70"></span>{{ $label }}</span>
