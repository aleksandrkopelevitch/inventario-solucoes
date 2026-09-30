{{-- A caderno's drawings, in `#main-modal` — opened from the caderno's rail,
     the same from every page, because a diagram belongs to the caderno and not
     to any one page of it.

     One row per drawing: status, name, the systems it names (the same chips as
     `/diagrams`, so a drawing reads the same in both places), "Abrir canvas" in
     a new tab (the page being written stays put) and "Copiar citação", which
     hands over the `{% diagram %}` line that puts the picture in a page. The
     footer is the one way left to start a drawing by hand. --}}
<div class="flex items-start justify-between border-b border-line px-5 py-4">
    <div class="min-w-0">
        <h2 class="font-display text-lg font-semibold text-ink">Diagramas do caderno</h2>
        <p class="mt-0.5 truncate text-xs text-muted">{{ $notebook->name }} · {{ $diagrams->count() }} {{ $diagrams->count() === 1 ? 'diagrama' : 'diagramas' }}</p>
    </div>
    <x-forms.button type="button" variant="ghost" data-close class="!p-1 !text-xl !leading-none !text-faint hover:!bg-transparent">&times;</x-forms.button>
</div>

<div class="max-h-[60vh] overflow-y-auto px-5 py-4">
    @forelse ($diagrams as $diagram)
        <div data-ak-copy class="mb-2.5 rounded-field border border-line bg-surface px-3.5 py-3 last:mb-0">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="size-2 shrink-0 rounded-full {{ $diagram->status->dotClass() }}" title="{{ $diagram->status->label() }}"></span>
                        <a href="{{ route('diagrams.show', $diagram) }}" target="_blank" rel="noopener"
                            class="truncate text-sm font-semibold text-ink no-underline hover:text-accent">{{ $diagram->name }}</a>
                        <span class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $diagram->status->badgeClass() }}">{{ $diagram->status->label() }}</span>
                    </div>
                    @if (count($diagram->chain['nodes'] ?? []) <= 1)
                        <p class="mt-1 text-xs italic text-faint">Canvas ainda em branco</p>
                    @endif
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    {{-- Built in PHP: the `{% … %}` braces and the quotes inside
                         them are not something to interpolate into an attribute. --}}
                    @php($citation = '{% diagram slug="' . $diagram->slug . '" %}')
                    <x-forms.input type="hidden" data-ak-copy-field :value="$citation" />
                    <x-forms.button type="button" variant="ghost" data-ak-copy-trigger data-ak-copy-message="Citação copiada — cole no texto de uma página."
                        class="!h-8 !w-8 !p-0" title="Copiar citação" aria-label="Copiar citação">
                        <x-heroicon-o-clipboard-document class="size-4" />
                    </x-forms.button>
                    <a href="{{ route('diagrams.show', $diagram) }}" target="_blank" rel="noopener"
                        class="inline-flex items-center gap-1.5 rounded-field border border-line bg-surface px-2.5 py-1.5 text-xs font-medium text-ink no-underline transition-colors hover:border-accent-line hover:bg-accent-soft/40">
                        <x-heroicon-o-share class="size-4" /> Abrir canvas
                    </a>
                </div>
            </div>

            <x-diagrams.solution-chips :solutions="$diagram->participants" class="mt-2.5" />
        </div>
    @empty
        <x-ui.empty-state illustration="diagrams" illustration-class="max-w-[220px]"
            title="Nenhum diagrama neste caderno"
            description="Use &quot;Desenhar esta página&quot; para gerar um a partir do texto, ou comece um em branco abaixo." />
    @endforelse
</div>

@can('create', App\Models\Diagram::class)
    <form id="notebook-diagram-create-form" class="flex items-center gap-2 border-t border-line px-5 py-3">
        @csrf
        <x-forms.input name="name" placeholder="Nome do novo diagrama" maxlength="255" class="!h-9 min-w-0 flex-1 !text-sm" />
        <x-forms.button data-ak-ajax="notebook-diagram-create-form"
            data-ak-action="{{ route('notebooks.diagrams.store', $notebook) }}"
            class="!h-9 !shrink-0 !px-3 !text-xs">
            <x-heroicon-o-plus class="size-4" /> Em branco
        </x-forms.button>
    </form>
@endcan
