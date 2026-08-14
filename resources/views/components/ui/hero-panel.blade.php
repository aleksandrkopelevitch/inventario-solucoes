@props([
    // Smaller padding/radius for the solution-detail identity header, vs.
    // the full page-header treatment on Soluções/Mapa do ecossistema.
    'compact' => false,
])

{{--
    "Lavado suave" — the full-strength 3-stop "glow" gradient
    (see [[radiant-protocol-redesign]]) read as louder the more it repeated:
    every list page AND every detail header used it, so the eye never rested
    before the real content. Diluted into a near-white wash (`color-mix`
    toward white) and moved the saturated gradient into a thin top seam
    instead of covering the whole card. The seam itself dropped
    `--color-glow-b/c` (pink/violet) — the ambient corner glow behind the
    page (see `layouts/layout.blade.php`) already carries that exact
    pink→violet, so repeating it here on every card read as doubled-up; the
    seam now resolves into our own lime/brand green instead
    (`--gradient-hero-seam`, defined once in `app.css` and shared with the
    three detail-headers — see the comment there).
    Content still uses `--color-glow-ink`-based text colors — still a dark
    near-black, reads fine on the lighter wash too.
--}}
<div {{ $attributes->class([
        'relative overflow-hidden rounded-bento border border-line shadow-card',
        'p-8' => $compact,
        'p-11' => ! $compact,
    ]) }}
    style="background: linear-gradient(135deg, color-mix(in srgb, var(--color-glow-a) 32%, white) 0%, color-mix(in srgb, var(--color-lime-soft) 75%, white) 100%)">
    <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px]"
         style="background: var(--gradient-hero-seam)"></div>
    <div class="relative">
        {{ $slot }}
    </div>
</div>
