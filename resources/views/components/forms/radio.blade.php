@props(['id' => null, 'name', 'value'])

<div class="flex items-center gap-x-3">
    <input
        type="radio"
        id="{{ $id ?? $name.'-'.$value }}"
        name="{{ $name }}"
        value="{{ $value }}"
        {{ $attributes->class([
            'size-4 cursor-pointer accent-accent',
            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
            'disabled:cursor-not-allowed',
        ]) }}
    />
    <label for="{{ $id ?? $name.'-'.$value }}"
           class="block text-sm font-medium leading-6 text-body cursor-pointer">{{ $slot }}</label>
</div>
