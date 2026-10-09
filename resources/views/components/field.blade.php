@props(['label' => null, 'for' => null, 'error' => null, 'help' => null, 'required' => false, 'optional' => false])
{{--
    Label + control + help text + inline error. Required fields are marked with an asterisk
    and announced to screen readers; errors appear next to the field that caused them.
    Usage: <x-field label="Product name" for="form.name" required> <input … > </x-field>
--}}
@php $errorKey = $error ?? $for; @endphp
<div {{ $attributes->merge(['class' => '']) }}>
    @if ($label)
        <label for="{{ $for }}" class="field-label">
            {{ $label }}
            @if ($required)<span class="text-danger-600" aria-hidden="true">*</span><span class="sr-only">(required)</span>@endif
            @if ($optional)<span class="font-normal text-sage-400">(optional)</span>@endif
        </label>
    @endif
    {{ $slot }}
    @if ($errorKey)
        @error($errorKey)
            <p class="field-message" role="alert"><x-icon name="alert" class="mt-px size-3.5" />{{ $message }}</p>
        @else
            @if ($help)<p class="field-help">{{ $help }}</p>@endif
        @enderror
    @elseif ($help)
        <p class="field-help">{{ $help }}</p>
    @endif
</div>
