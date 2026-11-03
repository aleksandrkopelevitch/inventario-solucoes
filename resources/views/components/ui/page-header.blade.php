@props([
    'eyebrow',            // PT-BR: the section above the title ("Diretório", "Governança")
    'title',
    'description' => null, // plain text; a description with markup goes in the `description` slot
    // The full page-header treatment (Soluções, Cadernos) vs. the compact one
    // every other index uses. Match the x-ui.hero-panel it sits in.
    'large' => false,
])

{{-- The eyebrow, title and subtitle of a page, inside an x-ui.hero-panel —
     ten index pages carried this same markup by hand. Slots: `count` (the live
     result counter, inline after the title), `description` (when the subtitle
     needs a link), `actions` (the page's buttons: to the right on a compact
     header, under the text on a large one). --}}
<div @class(['flex flex-wrap items-end justify-between gap-3' => ! $large])>
    <div>
        <span class="flex items-center gap-2 font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-[color:var(--color-glow-ink)]/70">
            <span class="size-2 rounded-full" style="background: linear-gradient(115deg, var(--color-glow-a), var(--color-lime))"></span>
            {{ $eyebrow }}
        </span>

        <h1 @class([
            'font-display font-bold tracking-tight text-[color:var(--color-glow-ink)]',
            'mt-3 text-[44px] leading-[0.98]' => $large,
            'mt-2 text-[34px] leading-tight' => ! $large,
        ])>
            {{ $title }}
            {{ $count ?? '' }}
        </h1>

        {{-- The prop or the slot of the same name: either way, a string. --}}
        @if (filled(trim((string) $description)))
            <p @class([
                'text-[color:var(--color-glow-ink)]/70',
                'mt-3 max-w-xl text-[15px] leading-relaxed' => $large,
                'mt-1 max-w-2xl text-sm' => ! $large,
            ])>{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div @class(['flex flex-wrap items-center', 'mt-5 gap-3' => $large, 'gap-2' => ! $large])>
            {{ $actions }}
        </div>
    @endisset
</div>
