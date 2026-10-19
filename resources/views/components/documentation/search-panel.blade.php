@props([
    'url',
    'results' => null,
    // `/docs` only: the published cadernos the palette can be pointed at, and
    // the one being read. Null on the magic link, where a token grants one
    // caderno and there is nothing to choose.
    'notebooks' => null,
    'current' => null,
])

@php
    // Which caderno the palette starts on: the one being read, or all of them
    // on the landing — and on a page of the home caderno, which is the landing's
    // text rather than one of the cadernos listed here.
    $selected = $notebooks && $current && $notebooks->contains('id', $current->id) ? $current->slug : '';

    // The pre-rendered slot answers for the caderno being read. When the
    // palette starts anywhere else it would be the wrong answer, so it ships
    // the placeholder and fetches instead.
    if ($notebooks && $selected === '') {
        $results = null;
    }
@endphp

{{--
    Search over the shared caderno's whole documentation — pages, the headings
    inside them, and the text under each — as a ⌘K command palette.

    It WAS a palette, then an inline panel above the fold, and now a palette
    again. The round trip is not indecision: what changed is what the filters
    mean. The inline version existed because its facets ("results that CONTAIN
    a table") were the feature, and a facet nobody can see is a facet nobody
    uses. The palette's controls answer a different question — "search INSIDE
    tables / code / prose" — and they live in the palette itself, beside the
    field, visible exactly when someone is searching. The old objection was
    about facets nobody could see; these are on screen the whole time the
    query is.

    Two halves, and the split is load-bearing:

    - the query field and the scope toggles live HERE, outside the updatable
      region, because that region is replaced on every keystroke and a replaced
      input loses the caret;
    - everything that reacts to the query — chips, count, hits, totals — is
      inside `docs-search-results-slot`, rendered by Blade on the server
      (x-documentation.search-results) so a page's own text is escaped on the
      way out and never becomes markup here.

    `$results` is the slot's HTML when the index was already warm at render
    time. When it's null the palette renders a pending placeholder and
    docs-search.js fetches it the first time the palette opens — which is also
    why a visitor who never searches never pays for the index
    (DocumentationSearchService::isWarm()).
--}}
<dialog data-ak-docs-search
        data-ak-docs-search-url="{{ $url }}"
        @if ($notebooks) data-ak-docs-search-notebook="{{ $selected }}" @endif
        aria-label="Buscar na documentação"
        {{-- `mx-auto` + a top margin instead of `m-0`: a <dialog> centres
             itself through AUTO margins, so zeroing them pins it to the corner.
             A palette wants to be centred horizontally and high on the screen,
             not vertically centred — the results grow downward and a centred
             box would drift as they do. --}}
        class="ak-docs-palette mx-auto mb-0 mt-[8vh] w-[92vw] max-w-2xl rounded-card border border-line bg-surface p-0 shadow-2xl backdrop:bg-ink/40 backdrop:backdrop-blur-[2px]">

    <div class="flex items-center gap-2 border-b border-line px-4 py-3">
        <x-heroicon-o-magnifying-glass class="size-[18px] shrink-0 text-faint" />

        <input type="search" data-ak-docs-search-input autocomplete="off" spellcheck="false"
               aria-label="Buscar na documentação"
               placeholder="Buscar em páginas, seções e conteúdo…"
               class="min-w-0 flex-1 border-0 bg-transparent p-0 text-[15px] text-ink outline-none placeholder:text-faint focus:ring-0">

        <x-heroicon-o-arrow-path data-ak-docs-search-spinner
            class="hidden size-4 shrink-0 animate-spin text-accent" />

        <button type="button" data-ak-docs-search-close
                class="shrink-0 cursor-pointer rounded border border-line bg-canvas px-1.5 py-0.5 font-mono text-[10px] text-faint transition-colors hover:text-ink"
                aria-label="Fechar busca">esc</button>
    </div>

    {{-- Where the query looks. Checkboxes rather than chips: these are not
         mutually exclusive and they are not a narrowing of results — they are
         the haystack itself, and a set of independent switches is the shape
         that says so. All three on is the default, and unticking the last one
         is treated as "all" rather than "nowhere" (see
         DocumentationSearchService::scopeSelection()). --}}
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-line px-4 py-2.5">
        {{-- WHICH caderno, before WHERE inside it. A select rather than a chip
             row: it is one choice out of a list that can run to dozens, and it
             has to read as the setting it is, on screen the whole time. The
             result chips below narrow the same way (a caderno chip picks it
             here too — docs-search.js keeps the two in step). --}}
        @if ($notebooks)
            <label class="inline-flex min-w-0 items-center gap-2">
                <span class="font-mono text-[10px] font-bold uppercase tracking-[0.12em] text-faint">Caderno</span>
                <x-forms.select auto data-ak-docs-search-notebook-select aria-label="Caderno em que buscar"
                    class="!py-1 !pl-2 !text-[13px]">
                    <option value="" @selected($selected === '')>Todos os cadernos</option>
                    @foreach ($notebooks as $notebook)
                        <option value="{{ $notebook->slug }}" @selected($selected === $notebook->slug)>{{ $notebook->name }}</option>
                    @endforeach
                </x-forms.select>
            </label>
        @endif

        {{-- One group, so it wraps as a unit: beside the caderno select the row
             runs out of width, and loose items left "Código" alone on a line of
             its own, read as a setting of nothing. --}}
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <span class="font-mono text-[10px] font-bold uppercase tracking-[0.12em] text-faint">Buscar em</span>

            @foreach ([
                'prose' => 'Texto e títulos',
                'table' => 'Tabelas',
                'code'  => 'Código',
            ] as $scope => $label)
                <label class="inline-flex cursor-pointer select-none items-center gap-1.5 text-[13px] text-body">
                    <input type="checkbox" data-ak-docs-search-scope="{{ $scope }}" checked
                           class="size-3.5 rounded border-line-2 text-accent focus:ring-accent">
                    {{ $label }}
                </label>
            @endforeach
        </div>
    </div>

    {{-- Replaced wholesale by ajax-slot.js — keep the id in step with
         App\View\Components\Documentation\SearchResults::DOM_ID. --}}
    <div class="ak-docs-scroll max-h-[60vh] overflow-y-auto px-4 pb-4">
        @if ($results)
            {!! $results !!}
        @else
            <div id="docs-search-results-slot" data-ak-docs-search-pending class="pt-3">
                <p class="font-mono text-[11px] text-faint">Preparando a busca…</p>
            </div>
        @endif
    </div>
</dialog>
