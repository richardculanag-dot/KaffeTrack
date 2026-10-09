@props(['label' => null, 'name', 'help' => null, 'required' => false, 'optional' => false, 'placeholder' => null, 'options' => []])
{{-- <x-select label="Category" name="form.category_id" :options="$categories" placeholder="Choose a category" wire:model="form.category_id" required /> --}}
@php $id = $attributes->get('id', str_replace('.', '_', $name)); @endphp
<x-field :label="$label" :for="$id" :error="$name" :help="$help" :required="$required" :optional="$optional" class="{{ $attributes->get('class') }}">
    <div class="relative">
        <select id="{{ $id }}" {{ $attributes->except('class')->class(['field appearance-none pr-10', 'field-error' => $errors->has($name)]) }}
                @error($name) aria-invalid="true" @enderror>
            @if ($placeholder !== null)
                <option value="">{{ $placeholder }}</option>
            @endif
            @foreach ($options as $value => $text)
                <option value="{{ $value }}">{{ $text }}</option>
            @endforeach
            {{ $slot }}
        </select>
        <x-icon name="chevron-down" class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-sage-500" />
    </div>
</x-field>
