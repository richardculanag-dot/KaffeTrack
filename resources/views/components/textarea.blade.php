@props(['label' => null, 'name', 'help' => null, 'required' => false, 'optional' => false, 'rows' => 3])
@php $id = $attributes->get('id', str_replace('.', '_', $name)); @endphp
<x-field :label="$label" :for="$id" :error="$name" :help="$help" :required="$required" :optional="$optional" class="{{ $attributes->get('class') }}">
    <textarea id="{{ $id }}" rows="{{ $rows }}" {{ $attributes->except('class')->class(['field resize-y', 'field-error' => $errors->has($name)]) }}
              @error($name) aria-invalid="true" @enderror></textarea>
</x-field>
