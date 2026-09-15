{{-- The knowledge base's landing: every published caderno, as a card.

     A screen rather than a redirect into the first caderno, because the
     question somebody arriving at `/docs` actually has is "o que tem aqui" —
     and because there is one state a redirect cannot express, which is that
     nothing has been published yet.

     The same shell as a reading page (top bar + brand), with no rail and no
     search: there is no corpus to search until a caderno is chosen, and a
     palette that answered for one arbitrary caderno would be worse than none. --}}
<x-layouts.public-docs title="Base de conhecimento" heading="Base de conhecimento">

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
                <a href="{{ $notebook['url'] }}"
                   class="group flex min-w-0 flex-col rounded-card border border-line bg-surface p-5 no-underline shadow-card transition-[border-color,box-shadow,transform] hover:-translate-y-0.5 hover:border-accent-line hover:shadow-card-hover">
                    <div class="flex items-start gap-2.5">
                        <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-md bg-accent text-white">
                            <x-heroicon-o-book-open class="size-4" />
                        </span>
                        <h2 class="min-w-0 flex-1 font-display text-[17px] font-semibold text-ink group-hover:text-accent">
                            {{ $notebook['name'] }}
                        </h2>
                    </div>

                    <p class="mt-3 text-xs text-muted">
                        {{ $notebook['documented'] }}
                        {{ $notebook['documented'] === 1 ? 'página escrita' : 'páginas escritas' }}
                    </p>

                    {{-- The systems it documents. `mt-auto` pins the row to the
                         bottom so cards in a row line their chips up with each
                         other — the same shape the cadernos catalog uses. --}}
                    @if ($notebook['solutions'] !== [])
                        <div class="mt-auto flex flex-wrap gap-1 pt-3">
                            @foreach ($notebook['solutions'] as $solution)
                                <span class="rounded-full bg-raised px-2 py-0.5 text-[11px] text-muted">{{ $solution }}</span>
                            @endforeach
                        </div>
                    @endif
                </a>
            @endforeach
        </div>
    @endif

</x-layouts.public-docs>
