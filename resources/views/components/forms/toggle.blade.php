@props([
    'name',
    'checked' => false,
    'value' => '1',
    'id' => null,
])

@php
    $id ??= 'toggle-' . $name;
@endphp

{{-- CSS-only boolean switch: clicking the label toggles the sr-only checkbox,
     peer utilities drive the visual state. No JS module required. --}}
<label for="{{ $id }}" {{ $attributes->class(['inline-flex cursor-pointer items-center gap-2']) }}>
    <input
        type="checkbox"
        id="{{ $id }}"
        name="{{ $name }}"
        value="{{ $value }}"
        @checked($checked)
        class="peer sr-only"
    />
    <span
        class="relative h-6 w-11 shrink-0 rounded-full bg-line-2 transition-colors
               peer-checked:bg-accent
               peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-accent
               after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-transform
               peer-checked:after:translate-x-5"
        aria-hidden="true"
    ></span>

    @if (trim($slot) !== '')
        <span class="text-sm text-body">{{ $slot }}</span>
    @endif
</label>
