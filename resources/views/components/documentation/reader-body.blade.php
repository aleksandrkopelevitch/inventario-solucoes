@props([
    'title',
    'showTitle' => true,
    'renderedHtml' => '',
    'markdown' => '',
    'secretRevealUrl' => null,
    'secretScope' => '',
    'childPages' => [],
])

{{-- The reading column, shared BYTE FOR BYTE by the two read-only surfaces:
     the magic link (`public.docs`) and the internal knowledge base (`/docs`).

     One component rather than two views that resemble each other, for the same
     reason `App\Services\Documentation\DocumentationReader` is one service:
     "o MESMO layout" stops being true on the day somebody fixes a bug on one
     copy. What differs between the surfaces is only ever the URLs in the
     payload, and those are built before anything gets here. --}}

{{-- The actions row. It carries the shell's own title ONLY when the page's
     text doesn't already open with it — see
     DocumentationReader::titleIsInContent(). Nearly every imported page does,
     and printing both said the page's name twice. --}}
<div class="flex items-start justify-between gap-3">
    <div class="min-w-0">
        {{-- No eyebrow over the title. There were two — "DOCUMENTAÇÃO" over
             the caderno's name in the topbar and "CADERNO" here — and both
             labelled something the screen already says: the topbar names
             the caderno, this names the page. A word in accent-coloured
             small caps over every title is chrome, not information. --}}
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
    {{-- Raw Markdown (media rewritten for this surface's routes) — source for
         docs-copy.js, and MASKED: the rendered HTML above painted locks, and
         this textarea would otherwise hand the same reader every plaintext
         value beside it, in the page source, for a button labelled "copy". --}}
    <textarea data-ak-docs-markdown hidden>{{ $markdown }}</textarea>

    {{-- When the content supplies the title, its own H1 already carries
         `mt-8` — adding a second top margin would push the page's name a
         paragraph below the toolbar it belongs beside. --}}
    {{-- See the authenticated reader partial: these two are what let
         docs-secret.js unlock a protected value. Neither surface GRANTS one —
         the caderno's secret code does, and an admin needs none. --}}
    <div @class(['html-content', 'mt-6' => $showTitle]) data-ak-docs-content
        @isset($secretRevealUrl) data-ak-secret-url="{{ $secretRevealUrl }}" @endisset
        data-ak-secret-scope="{{ $secretScope }}">
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
