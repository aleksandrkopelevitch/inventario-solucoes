@props([
    'domId',
    'results' => [],
    'facets' => ['sections' => [], 'tags' => []],
    'filters' => ['section' => null, 'tag' => null],
    'total' => 0,
    'shown' => 0,
    'overview' => ['pages' => 0, 'sections' => 0, 'words' => 0],
    'narrowed' => false,
    'tagLabels' => [],
    'tagBadges' => [],
])

@php
    // Chip styling is shared by both facet rows; "active" is a filled green
    // token rather than a border change, so which axis is narrowing the list
    // is readable at a glance (see the reference dictionary's chip rows).
    //
    // Every colour here is `!`-prefixed, and the chips ask for `variant="ghost"`,
    // for one reason: x-forms.button ships a variant's own `bg-*`/`text-*` in
    // the same class attribute, and which of two single-class utilities wins is
    // decided by their order in the compiled stylesheet, not by their order in
    // the attribute. Without the override the default `primary` variant's
    // `bg-btn text-white` won and every inactive chip rendered white-on-white
    // — a row of empty pills, with the labels still in the DOM.
    $chipBase = 'inline-flex shrink-0 items-center gap-1.5 !rounded-full border !px-2.5 !py-1 font-mono !text-[11px] !font-normal !shadow-none transition-colors';
    $chipOff = '!border-line !bg-surface !text-muted hover:!border-accent-line hover:!bg-surface hover:!text-ink';
    $chipOn = '!border-accent !bg-accent !text-white hover:!bg-accent-press hover:!text-white';
    // A chip with nothing behind it stays in the row (the row must not move
    // under the caret) but stops competing for attention.
    $chipEmpty = 'opacity-45';
@endphp

{{-- `data-ak-docs-search-active` is what tells docs-search.js to hide the
     documentation shell below: the server already decided whether the corpus
     is being narrowed, and deciding it twice is how the two drift apart. --}}
<div id="{{ $domId }}" @if ($narrowed) data-ak-docs-search-active @endif>

    {{-- Facet rows. Both are hidden when the corpus has nothing to narrow by,
         so a one-page link doesn't grow two rows of dead chrome. --}}
    @if (filled($facets['tags']) || count($facets['sections']) > 1)
        <div class="space-y-1.5 pt-3">
            @if (filled($facets['tags']))
                <div class="flex items-center gap-2">
                    <span class="w-16 shrink-0 text-[10px] font-bold uppercase tracking-[0.12em] text-faint">Conteúdo</span>
                    <div class="ak-docs-scroll flex min-w-0 flex-1 gap-1.5 overflow-x-auto">
                        <x-forms.button type="button" variant="ghost" data-ak-docs-search-facet="tag" data-ak-docs-search-value=""
                            class="{{ $chipBase }} {{ $filters['tag'] === null ? $chipOn : $chipOff }}">Tudo</x-forms.button>
                        @foreach ($facets['tags'] as $facet)
                            <x-forms.button type="button" variant="ghost" data-ak-docs-search-facet="tag" data-ak-docs-search-value="{{ $facet['value'] }}"
                                class="{{ $chipBase }} {{ $filters['tag'] === $facet['value'] ? $chipOn : $chipOff }} {{ $facet['count'] === 0 ? $chipEmpty : '' }}">
                                {{ $tagLabels[$facet['value']] ?? $facet['value'] }}
                                <span class="opacity-60">{{ $facet['count'] }}</span>
                            </x-forms.button>
                        @endforeach
                    </div>
                </div>
            @endif

            @if (count($facets['sections']) > 1)
                <div class="flex items-center gap-2">
                    <span class="w-16 shrink-0 text-[10px] font-bold uppercase tracking-[0.12em] text-faint">Seção</span>
                    <div class="ak-docs-scroll flex min-w-0 flex-1 gap-1.5 overflow-x-auto">
                        <x-forms.button type="button" variant="ghost" data-ak-docs-search-facet="section" data-ak-docs-search-value=""
                            class="{{ $chipBase }} {{ $filters['section'] === null ? $chipOn : $chipOff }}">Todas</x-forms.button>
                        @foreach ($facets['sections'] as $facet)
                            {{-- The label is shortened in PHP, not with `truncate`:
                                 x-forms.button wraps the slot in its own flex
                                 `[data-label]` span, which has no `min-w-0`, so a
                                 truncating child never shrinks and a long page
                                 title overflows the pill onto its neighbour. --}}
                            <x-forms.button type="button" variant="ghost" data-ak-docs-search-facet="section" data-ak-docs-search-value="{{ $facet['value'] }}"
                                title="{{ $facet['label'] }}"
                                class="{{ $chipBase }} {{ $filters['section'] === $facet['value'] ? $chipOn : $chipOff }} {{ $facet['count'] === 0 ? $chipEmpty : '' }}">
                                {{ Str::limit($facet['label'], 26) }}
                                <span class="opacity-60">{{ $facet['count'] }}</span>
                            </x-forms.button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- Idle: the panel says what there is to search, and gets out of the way
         so the documentation itself stays above the fold. --}}
    @unless ($narrowed)
        <p class="pt-2 font-mono text-[10px] text-faint">
            {{ $overview['pages'] }} {{ $overview['pages'] === 1 ? 'página' : 'páginas' }} ·
            {{ $overview['sections'] }} {{ $overview['sections'] === 1 ? 'seção' : 'seções' }} ·
            {{ number_format($overview['words'], 0, ',', '.') }} palavras
        </p>
    @endunless

    {{-- The results take the reading shell's place, so they take its measure
         too: full-bleed hits stretch a snippet to the width of the window and
         stop being readable at all. The field above stays edge to edge — it
         reads as a toolbar, not as prose. --}}
    @if ($narrowed)
    <div class="max-w-5xl">
        <div class="mt-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-line pt-3">
            <p class="font-mono text-[11px] text-faint">
                {{ $total }} {{ $total === 1 ? 'resultado' : 'resultados' }}@if ($shown < $total) · mostrando os {{ $shown }} primeiros @endif
                <span class="ml-2 hidden sm:inline">
                    <kbd class="rounded border border-line bg-canvas px-1">↑</kbd><kbd class="ml-0.5 rounded border border-line bg-canvas px-1">↓</kbd> navegar
                    <kbd class="ml-1.5 rounded border border-line bg-canvas px-1">⏎</kbd> abrir
                </span>
            </p>

            <x-forms.button type="button" variant="ghost" data-ak-docs-search-clear
                class="!h-7 !gap-1 !px-2 !text-xs !font-normal !text-muted hover:!text-ink">
                <x-heroicon-o-x-mark class="size-3.5" />
                Limpar busca
            </x-forms.button>
        </div>

        <div data-ak-docs-search-list role="listbox" aria-label="Resultados da busca" class="mt-1 -mx-2">
            @forelse ($results as $index => $result)
                <a href="{{ $result['url'] }}" role="option" aria-selected="false"
                   data-ak-docs-search-item="{{ $index }}"
                   class="group block rounded-field px-2.5 py-2 no-underline transition-colors hover:bg-raised/60 aria-selected:bg-accent-soft">

                    <div class="flex items-baseline gap-2">
                        {{-- A page and a heading inside it are different things to
                             land on; the marker says which before the title does. --}}
                        <span class="shrink-0 font-mono text-[10px] uppercase tracking-wide {{ $result['kind'] === 'page' ? 'text-accent' : 'text-faint' }}">
                            {{ $result['kind'] === 'page' ? 'pág' : 'h' . $result['level'] }}
                        </span>

                        <span class="min-w-0 flex-1 truncate text-sm font-semibold text-ink">
                            <x-documentation.search-highlight :segments="$result['title']" />
                        </span>

                        @foreach ($result['tags'] as $tag)
                            <span class="hidden shrink-0 rounded-full border border-line px-1.5 py-px font-mono text-[10px] text-muted sm:inline">
                                {{ $tagBadges[$tag] ?? $tag }}
                            </span>
                        @endforeach
                    </div>

                    {{-- Breadcrumb: which page (and which pages above it) this hit
                         lives in — a heading title alone is rarely enough to tell
                         two similar sections apart. --}}
                    @if (filled($result['trail']))
                        <p class="mt-0.5 truncate font-mono text-[10px] text-faint">
                            {{ implode(' › ', $result['trail']) }}
                        </p>
                    @endif

                    @if (filled($result['snippet']))
                        <p class="mt-1 line-clamp-2 text-[13px] leading-relaxed text-muted">
                            <x-documentation.search-highlight :segments="$result['snippet']" />
                        </p>
                    @endif
                </a>
            @empty
                <div class="px-4 py-12 text-center">
                    @if ($overview['pages'] === 0)
                        <p class="text-sm font-medium text-ink">Nada para buscar ainda</p>
                        <p class="mt-1 text-[13px] text-muted">Esta documentação ainda não tem páginas publicadas.</p>
                    @else
                        <p class="text-sm font-medium text-ink">Nenhum resultado</p>
                        <p class="mt-1 text-[13px] text-muted">Tente outro termo ou remova os filtros de conteúdo e seção.</p>
                    @endif
                </div>
            @endforelse
        </div>
    </div>
    @endif
</div>
