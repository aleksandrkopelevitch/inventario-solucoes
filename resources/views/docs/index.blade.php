{{-- The knowledge base's landing.

     The rail lists every published caderno (with a filter); the reading column
     is the HOME caderno's first page — a page like any other, edited in
     Cadernos, rendered by the same body as every page behind it. No search in
     the top bar: the palette answers for one caderno, and here it would have
     searched only the landing's own text.

     Without a published home the column falls back to the cadernos as cards,
     which is also what a reader on a narrow screen (no rail) needs most. --}}
<x-layouts.public-docs :title="$home['title'] ?? 'Base de conhecimento'" heading="Base de conhecimento"
    :notebooks="$notebooks" :home-url="route('docs.index')">

    <x-slot:rail>
        <x-docs.notebooks-rail :notebooks="$notebooks" :home-url="route('docs.index')" />
    </x-slot:rail>

    @if ($home)
        <x-documentation.reader-body
            :title="$home['title']"
            :show-title="$home['showTitle']"
            :rendered-html="$home['renderedHtml']"
            :markdown="$home['markdown']"
            :secret-reveal-url="$home['secretRevealUrl']"
            :secret-scope="$home['secretScope']"
            :child-pages="$home['childPages']"
            :copyable="false" />
    @else
        <div class="flex flex-col gap-1">
            <h1 class="font-display text-3xl font-semibold text-ink">Base de conhecimento</h1>
            <p class="text-sm text-muted">
                A documentação que o time de Arquitetura publicou para toda a Leo Madeiras.
            </p>
        </div>

        @if ($notebooks->isEmpty())
            <x-ui.empty-state class="mt-8" illustration="docs" illustration-class="max-w-[200px]"
                title="Nenhum caderno publicado ainda"
                description="Assim que um administrador publicar um caderno, ele aparece aqui." />
        @else
            <div class="mt-8 grid gap-3 sm:grid-cols-2">
                @foreach ($notebooks as $notebook)
                    <a href="{{ $notebook->knowledgeBaseUrl() }}"
                       class="group flex min-w-0 flex-col rounded-card border border-line bg-surface p-5 no-underline shadow-card transition-[border-color,box-shadow,transform] hover:-translate-y-0.5 hover:border-accent-line hover:shadow-card-hover">
                        <div class="flex items-start gap-2.5">
                            <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-md bg-accent text-white">
                                <x-heroicon-o-book-open class="size-4" />
                            </span>
                            <h2 class="min-w-0 flex-1 font-display text-[17px] font-semibold text-ink group-hover:text-accent">
                                {{ $notebook->name }}
                            </h2>
                        </div>

                        {{-- The systems it documents, pinned to the bottom so
                             cards in a row line their chips up. --}}
                        @if ($notebook->solutions->isNotEmpty())
                            <div class="mt-auto flex flex-wrap gap-1 pt-3">
                                @foreach ($notebook->solutions as $solution)
                                    <span class="rounded-full bg-raised px-2 py-0.5 text-[11px] text-muted">{{ $solution->name }}</span>
                                @endforeach
                            </div>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    @endif

</x-layouts.public-docs>
