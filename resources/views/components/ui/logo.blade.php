@props([
    'name' => '',
    'src' => null,
    'size' => 'md',
    // Background class for the letter-fallback tile. Defaults to the green
    // brand anchor; solution cards pass a category color via
    // `App\Support\CategoryPalette::tileClass()`. No effect when `src` is a
    // real image. Text stays white for every tone (all tiles are saturated).
    'tone' => 'bg-accent',
])

@php
    $sizes = [
        'sm' => 'size-8 text-xs',
        'md' => 'size-10 text-sm',
        'lg' => 'size-14 text-lg',
    ];
    $sizeClass = $sizes[$size] ?? $sizes['md'];

    $initial = mb_strtoupper(mb_substr(trim($name), 0, 1));

    // A path on the public disk is drawn from its thumbnail (PublicImage):
    // this tile is at most 56 px, and the upload can be anything up to 2 MB.
    $url = \App\Support\Images\PublicImage::url($src);
@endphp

@if ($url)
    <img src="{{ $url }}" alt="{{ $name }}"
        {{ $attributes->class([$sizeClass, 'shrink-0 rounded-field border border-line bg-surface object-contain p-1']) }}>
@else
    <span {{ $attributes->class([$sizeClass, $tone, 'inline-flex shrink-0 items-center justify-center rounded-field font-display font-bold text-white']) }}>
        {{ $initial ?: '?' }}
    </span>
@endif
