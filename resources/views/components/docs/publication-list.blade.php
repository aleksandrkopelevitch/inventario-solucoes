{{-- Which cadernos `/docs` shows, as one updatable slot.

     A LIST rather than the card grid the catalog uses: the question here is
     binary and per row ("está publicado?"), so the rows have to be comparable
     at a glance, and a grid of cards is the layout that makes scanning one
     column hardest. --}}
<div id="{{ $domId }}">
    <div class="mb-3 flex items-center justify-between gap-3">
        <p class="text-sm text-muted">
            <span class="font-display font-semibold text-ink">{{ $published }}</span>
            {{ $published === 1 ? 'caderno publicado' : 'cadernos publicados' }}
            @if (! $hasFilters)
                de {{ $total }}
            @endif
        </p>
    </div>

    @if ($notebooks->isEmpty())
        <x-ui.empty-state illustration="docs" illustration-class="max-w-[180px]"
            :title="$hasFilters ? 'Nenhum caderno encontrado' : 'Nenhum caderno criado ainda'"
            :description="$hasFilters
                ? 'Ajuste a busca ou o filtro para encontrar o que procura.'
                : 'Crie um caderno primeiro — só depois ele pode ser publicado na base de conhecimento.'" />
    @else
        <div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
            @foreach ($notebooks as $notebook)
                {{-- The hidden form is what `data-ak-ajax` builds its FormData
                     from: the CSRF token, the PATCH spoof and the value being
                     written. There is no outer <form> on this page for it to
                     nest inside (a nested one is dropped by the parser). --}}
                <form id="docs-publication-{{ $loop->index }}" class="hidden">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="published" value="{{ $notebook['published'] ? '0' : '1' }}">
                </form>

                <div @class([
                    'flex items-center gap-3 px-4 py-3',
                    'border-t border-line' => ! $loop->first,
                ])>
                    <span @class([
                        'inline-flex size-8 shrink-0 items-center justify-center rounded-md',
                        'bg-accent text-white' => $notebook['published'],
                        'bg-raised text-faint' => ! $notebook['published'],
                    ])>
                        <x-heroicon-o-book-open class="size-4" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex min-w-0 items-center gap-2">
                            <a href="{{ $notebook['editUrl'] }}" class="min-w-0 truncate font-display text-[15px] font-semibold text-ink no-underline hover:text-accent">
                                {{ $notebook['name'] }}
                            </a>

                            @if ($notebook['hasLink'])
                                {{-- The magic link is a DIFFERENT audience, and
                                     the two are constantly confused. Saying so
                                     on the row is cheaper than an admin
                                     assuming this switch controls it. --}}
                                <span class="shrink-0 text-faint" title="Também tem link público (sem login)">
                                    <x-heroicon-o-globe-alt class="size-4" />
                                </span>
                            @endif

                            @if ($notebook['isEmpty'])
                                <span class="shrink-0 rounded-full bg-hot-soft px-2 py-0.5 text-[11px] font-medium text-hot">
                                    Sem conteúdo
                                </span>
                            @endif
                        </div>

                        <p class="mt-0.5 truncate text-xs text-muted">
                            {{ $notebook['documented'] }}
                            {{ $notebook['documented'] === 1 ? 'página escrita' : 'páginas escritas' }}
                            @if ($notebook['solutions'] !== [])
                                · {{ implode(' · ', $notebook['solutions']) }}
                            @endif
                        </p>
                    </div>

                    @if ($notebook['published'])
                        <a href="{{ $notebook['readUrl'] }}" target="_blank" rel="noopener"
                           class="hidden shrink-0 items-center gap-1 text-xs font-medium text-accent no-underline hover:underline sm:inline-flex">
                            Em /docs desde {{ $notebook['publishedAt'] }}
                            <x-heroicon-o-arrow-top-right-on-square class="size-3.5" />
                        </a>
                    @endif

                    {{-- The switch. An `x-forms.button` rather than
                         `x-forms.toggle`, because this one WRITES on click: the
                         toggle component is a CSS-only checkbox for a form that
                         gets submitted later, and there is no later here. It
                         still reads as a switch, and `aria-pressed` is what says
                         so to a screen reader. --}}
                    <x-forms.button type="button" variant="ghost"
                        data-ak-ajax="docs-publication-{{ $loop->index }}"
                        data-ak-action="{{ $notebook['toggleUrl'] }}"
                        data-ak-confirm="{{ $notebook['confirm'] }}"
                        aria-pressed="{{ $notebook['published'] ? 'true' : 'false' }}"
                        class="shrink-0 !h-6 !w-11 !rounded-full !p-0 {{ $notebook['published'] ? '!bg-accent' : '!bg-line-2' }}"
                        :aria-label="($notebook['published'] ? 'Tirar' : 'Publicar') . ' ' . $notebook['name'] . ' na base de conhecimento'">
                        <span @class([
                            'block size-5 rounded-full bg-white shadow transition-transform',
                            'translate-x-[10px]' => $notebook['published'],
                            '-translate-x-[10px]' => ! $notebook['published'],
                        ])></span>
                    </x-forms.button>
                </div>
            @endforeach
        </div>
    @endif
</div>
