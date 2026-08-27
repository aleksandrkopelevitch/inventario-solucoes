@props(['url', 'results' => null])

{{--
    Search + filters over the shared solution's whole documentation (pages AND
    the headings inside them), sitting above the fold on every public page —
    NOT behind a shortcut. It was a ⌘K palette first, and the filters were the
    reason it stopped being one: a facet nobody can see is a facet nobody uses.

    Two halves, and the split is load-bearing:

    - the query field lives HERE, outside the updatable region, because that
      region is replaced on every keystroke and a replaced input loses the caret;
    - everything that reacts to the query — chips, count, hits, totals — is
      inside `docs-search-results-slot`, rendered by Blade on the server
      (x-documentation.search-results) so a page's own text is escaped on the
      way out and never becomes markup here.

    `$results` is the slot's HTML when the index was already warm at render
    time (the common case: chips are on screen with the page). When it's null
    the panel renders a pending placeholder instead and docs-search.js fetches
    it on load — see DocumentationSearchService::isWarm().
--}}
<section data-ak-docs-search
         data-ak-docs-search-url="{{ $url }}"
         class="border-b border-line bg-surface px-4 py-4 md:px-6 lg:px-8">

    <div class="relative">
        <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3.5 top-1/2 size-[18px] -translate-y-1/2 text-faint" />

        <x-forms.input type="search" data-ak-docs-search-input autocomplete="off" spellcheck="false"
            aria-label="Buscar na documentação"
            placeholder="Buscar em páginas, seções e conteúdo desta documentação…"
            class="!py-2.5 !pl-11 !pr-24 !text-[15px]" />

        <x-heroicon-o-arrow-path data-ak-docs-search-spinner
            class="pointer-events-none absolute right-14 top-1/2 hidden size-4 -translate-y-1/2 animate-spin text-accent" />

        <span class="pointer-events-none absolute right-3.5 top-1/2 hidden -translate-y-1/2 rounded border border-line bg-canvas px-1.5 py-0.5 font-mono text-[10px] text-faint sm:block">⌘K</span>
    </div>

    {{-- Replaced wholesale by ajax-slot.js — keep the id in step with
         App\View\Components\Documentation\SearchResults::DOM_ID. --}}
    @if ($results)
        {!! $results !!}
    @else
        <div id="docs-search-results-slot" data-ak-docs-search-pending class="pt-3">
            <p class="font-mono text-[11px] text-faint">Preparando os filtros…</p>
        </div>
    @endif
</section>
