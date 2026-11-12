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

    // A path on the public disk is drawn from its thumbnail (PublicImage):
    // this tile is at most 56 px, and the upload can be anything up to 2 MB.
    $url = \App\Support\Images\PublicImage::url($src);
@endphp

@if ($url)
    <img src="{{ $url }}" alt="{{ $name }}"
        {{ $attributes->class([$sizeClass, 'shrink-0 rounded-full object-cover ring-1 ring-line']) }}>
@else
    <span {{ $attributes->class([$sizeClass, 'inline-flex shrink-0 items-center justify-center rounded-full bg-accent-soft font-semibold uppercase text-ink ring-1 ring-accent-line']) }}>
        {{ $initials ?: '—' }}
    </span>
@endif
