<x-layouts.public-docs :title="$title" :heading="$notebook->name" :nav="$nav" :search-url="$searchUrl" :search-results="$searchResults">
    {{-- The actions row. It carries the shell's own title ONLY when the page's
         text doesn't already open with it — see
         PublicDocumentationController::titleIsInContent(). Nearly every
         imported page does, and printing both said the page's name twice. --}}
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-accent">{{ $eyebrow }}</p>
            @if ($showTitle)
                <h1 class="mt-1 font-display text-3xl font-semibold text-ink">{{ $title }}</h1>
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

        {{-- `mt-2` when the content supplies the title: its own H1 carries the
             top margin, and stacking both left a gap the size of a paragraph. --}}
        <div @class(['html-content', 'mt-6' => $showTitle, 'mt-2' => ! $showTitle]) data-ak-docs-content>
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
