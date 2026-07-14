@props(['for' => null])

<label
    @if ($for) for="{{ $for }}" @endif
    {{ $attributes->class(['block text-sm font-medium leading-6 text-body']) }}
>{{ $slot }}</label>
