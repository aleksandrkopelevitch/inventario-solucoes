<x-layouts.public-docs :title="$title" :heading="$notebook->name" :nav="$nav" :search-url="$searchUrl" :search-results="$searchResults">
    {{-- The actions row. It carries the shell's own title ONLY when the page's
         text doesn't already open with it — see
         PublicDocumentationController::titleIsInContent(). Nearly every
         imported page does, and printing both said the page's name twice. --}}
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            {{-- The label belongs to the TITLE, so it renders whether the title
                 comes from here or from the page's own opening H1 (the common
                 case — see titleIsInContent()). `mb-1` rather than a margin on
                 the h1: in the content case the heading below is `.html-content
                 h1`, which brings its own `mt-8`, and two stacked margins put
                 the label adrift from the thing it names. --}}
            <p class="mb-1 font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-accent">{{ $eyebrow }}</p>
            @if ($showTitle)
                <h1 class="font-display text-3xl font-semibold text-ink">{{ $title }}</h1>
            @endif
        </div>

        @if (trim($renderedHtml) !== '')
            <x-forms.button type="button" variant="ghost" data-ak-docs-copy
                class="!h-9 shrink-0 !gap-1.5 !px-3 !text-sm" aria-label="Copiar Markdown">
                <x-heroicon-o-clipboard-document class="size-4" />
                <span>Copiar Markdown</span>
            </x-forms.button>
        @endif
    </div>

    @if (trim($renderedHtml) !== '')
        {{-- Raw Markdown (media rewritten for public routes) — source for docs-copy.js. --}}
        <textarea data-ak-docs-markdown hidden>{{ $markdown }}</textarea>

        {{-- No top margin when the content supplies the title: its own H1
             carries `mt-8`, and stacking both leaves the CADERNO label floating
             a paragraph above the name it labels. --}}
        <div @class(['html-content', 'mt-6' => $showTitle, '-mt-6' => ! $showTitle]) data-ak-docs-content>
            {!! $renderedHtml !!}
        </div>
    @elseif (empty($childPages))
        {{-- See the reader partial: a page with no text but with sub-pages is a
             section, and the cards below are its content. --}}
        <p class="mt-6 rounded-field border border-dashed border-line px-4 py-10 text-center text-sm text-muted">
            Nenhuma documentação cadastrada ainda.
        </p>
    @endif

    <x-documentation.child-pages :pages="$childPages" />
</x-layouts.public-docs>
