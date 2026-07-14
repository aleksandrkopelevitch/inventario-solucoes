@props([
    'name' => '',
    'src' => null,
    'size' => 'md',
])

@php
    $sizes = [
        'sm' => 'size-8 text-xs',
        'md' => 'size-10 text-sm',
        'lg' => 'size-14 text-lg',
    ];
    $sizeClass = $sizes[$size] ?? $sizes['md'];

    $initial = mb_strtoupper(mb_substr(trim($name), 0, 1));

    $url = $src
        ? (\Illuminate\Support\Str::startsWith($src, ['http://', 'https://', '/'])
            ? $src
            : \Illuminate\Support\Facades\Storage::disk('public')->url($src))
        : null;
@endphp

@if ($url)
    <img src="{{ $url }}" alt="{{ $name }}"
        {{ $attributes->class([$sizeClass, 'shrink-0 rounded-field border border-line bg-surface object-contain p-1']) }}>
@else
    <span {{ $attributes->class([$sizeClass, 'inline-flex shrink-0 items-center justify-center rounded-field bg-accent font-display font-bold text-white']) }}>
        {{ $initial ?: '?' }}
    </span>
@endif
