@props(['label' => null, 'name', 'type' => 'text', 'help' => null, 'required' => false, 'optional' => false, 'prefix' => null, 'suffix' => null])
{{--
    Text-like input wired to Livewire. Pass the wire:model yourself so you control live/blur:
    <x-input label="Unit cost" name="form.unit_cost" type="number" step="0.01" prefix="₱" wire:model.blur="form.unit_cost" required />
--}}
@php $id = $attributes->get('id', str_replace('.', '_', $name)); @endphp
<x-field :label="$label" :for="$id" :error="$name" :help="$help" :required="$required" :optional="$optional" class="{{ $attributes->get('class') }}">
    <div class="relative">
        @if ($prefix)
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-sage-500">{{ $prefix }}</span>
        @endif
        <input id="{{ $id }}" type="{{ $type }}"
               {{ $attributes->except('class')->class(['field', 'pl-8' => $prefix, 'pr-12' => $suffix, 'field-error' => $errors->has($name)]) }}
               @if ($required) aria-required="true" @endif
               @error($name) aria-invalid="true" @enderror>
        @if ($suffix)
            <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3.5 text-sm text-sage-500">{{ $suffix }}</span>
        @endif
    </div>
</x-field>
