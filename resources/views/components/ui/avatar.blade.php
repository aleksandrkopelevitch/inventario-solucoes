@props([
    'name' => '',
    'src' => null,
    'size' => 'md',
])

@php
    $sizes = [
        'sm' => 'size-7 text-[10px]',
        'md' => 'size-9 text-xs',
        'lg' => 'size-14 text-base',
    ];
    $sizeClass = $sizes[$size] ?? $sizes['md'];

    $initials = collect(explode(' ', trim($name)))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_substr($part, 0, 1))
        ->implode('');

    $url = $src
        ? (\Illuminate\Support\Str::startsWith($src, ['http://', 'https://', '/'])
            ? $src
            : \Illuminate\Support\Facades\Storage::disk('public')->url($src))
        : null;
@endphp

@if ($url)
    <img src="{{ $url }}" alt="{{ $name }}"
        {{ $attributes->class([$sizeClass, 'shrink-0 rounded-full object-cover ring-1 ring-line']) }}>
@else
    <span {{ $attributes->class([$sizeClass, 'inline-flex shrink-0 items-center justify-center rounded-full bg-accent-soft font-semibold uppercase text-ink ring-1 ring-accent-line']) }}>
        {{ $initials ?: '—' }}
    </span>
@endif
