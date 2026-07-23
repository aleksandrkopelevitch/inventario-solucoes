<x-layouts.public-docs :title="$title" :heading="$solution->name" :nav="$nav">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-accent">{{ $eyebrow }}</p>
            <h1 class="mt-1 font-display text-3xl font-semibold text-ink">{{ $title }}</h1>
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

        <div class="html-content mt-6" data-ak-docs-content>
            {!! $renderedHtml !!}
        </div>
    @else
        <p class="mt-6 rounded-field border border-dashed border-line px-4 py-10 text-center text-sm text-muted">
            Nenhuma documentação cadastrada ainda.
        </p>
    @endif
</x-layouts.public-docs>
