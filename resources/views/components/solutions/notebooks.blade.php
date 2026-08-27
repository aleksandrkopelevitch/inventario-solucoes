{{-- Right column of the solution's "diagramas + documentação" card (frame in
     solutions/show.blade.php). The divider between the two columns rides here
     rather than on the frame: this element is the updatable slot, so it's
     re-rendered identically on every swap, while a wrapper around it would be
     one more node between the grid and the column.

     It lists the CADERNOS that document this solution — not pages. A caderno
     may describe several solutions, so the same one legitimately shows up on
     more than one of these cards. --}}
<div id="{{ $domId }}" class="flex min-w-0 flex-col border-t border-line p-6 lg:border-l lg:border-t-0">
    <div class="flex items-center gap-2.5">
        <span class="inline-flex size-7 shrink-0 items-center justify-center rounded-md bg-accent text-white">
            <x-heroicon-o-book-open class="size-4" />
        </span>
        <h2 class="font-display text-[18px] font-semibold text-ink">Cadernos</h2>
        @if ($notebooks->isNotEmpty())
            <span class="rounded-full bg-raised px-2 py-0.5 text-xs font-medium text-muted">{{ $notebooks->count() }}</span>
        @endif

        @can('create', \App\Models\Notebook::class)
            {{-- Opens the caderno panel, where the new one can be linked to
                 this solution in the same gesture that names it. --}}
            <button type="button" data-ak-panel-open data-ak-panel-url="{{ $createUrl }}"
                class="ml-auto inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-field border border-line bg-surface px-2.5 py-1 text-xs font-medium text-ink transition-colors hover:border-accent-line hover:bg-accent-soft/40">
                <x-heroicon-o-plus class="size-3.5" />
                Novo caderno
            </button>
        @endcan
    </div>

    <p class="mt-1.5 text-sm text-muted">Documentação que descreve esta solução.</p>

    @if ($notebooks->isNotEmpty())
        <div class="mt-4 flex flex-col gap-2">
            @foreach ($notebooks as $notebook)
                <a href="{{ $notebook['url'] }}"
                    class="group flex items-center gap-2.5 rounded-field border border-line bg-surface px-3.5 py-2.5 text-sm no-underline transition-colors hover:border-accent-line hover:bg-accent-soft/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent">
                    <x-heroicon-o-book-open class="size-4 shrink-0 text-faint" />
                    <span @class([
                        'min-w-0 flex-1 truncate',
                        'font-medium text-ink' => $notebook['documented'] > 0,
                        'italic text-muted' => $notebook['documented'] === 0,
                    ])>
                        {{ $notebook['name'] }}
                    </span>

                    {{-- A caderno with a live magic link. Worth seeing from
                         here: this is the only screen that shows every caderno
                         a solution is exposed through. --}}
                    @if ($notebook['isShared'])
                        <x-heroicon-o-globe-alt class="size-3.5 shrink-0 text-accent" title="Tem link público" />
                    @endif

                    @if ($notebook['pages'] === 0)
                        <span class="shrink-0 rounded-full bg-raised px-2 py-0.5 text-xs text-muted">Vazio</span>
                    @else
                        <span class="shrink-0 text-xs text-muted">
                            {{ $notebook['documented'] }}/{{ $notebook['pages'] }} {{ $notebook['pages'] === 1 ? 'página' : 'páginas' }}
                        </span>
                    @endif

                    <x-heroicon-o-chevron-right class="size-4 shrink-0 text-faint" />
                </a>
            @endforeach
        </div>
    @else
        <div class="mt-4">
            <x-ui.empty-state illustration="docs" illustration-class="max-w-[104px]"
                title="Nenhum caderno vinculado"
                description="Crie um caderno — ou vincule um que já existe — para descrever o que essa solução faz e como ela é operada." />
        </div>
    @endif
</div>
