{{-- Left column of the solution's "diagramas + documentação" card (the frame
     itself is in solutions/show.blade.php — this is the updatable slot, so it
     can't own the card's border). A plain nav list: each row opens that
     diagram's own canvas page. Two kinds of row live here together on purpose
     (a drawing this solution is a box in, and a drawing its documentation
     points at) — see the component's docblock. --}}
<div id="{{ $domId }}" class="flex min-w-0 flex-col p-6" aria-label="Diagramas da solução">
    <div class="flex items-center gap-2.5">
        <span class="inline-flex size-7 shrink-0 items-center justify-center rounded-md bg-accent text-white">
            <x-heroicon-o-share class="size-4" />
        </span>
        <h2 class="font-display text-[18px] font-semibold text-ink">Diagramas</h2>
        @if ($rows->count())
            {{-- Lives inside the slot, so create/delete keep it in sync
                 automatically (renaming/status changes never affect it). --}}
            <span class="rounded-full bg-raised px-2 py-0.5 text-xs font-medium text-muted">{{ $rows->count() }}</span>
        @endif
    </div>

    <p class="mt-1.5 text-sm text-muted">Fluxos desenhados em que esta solução aparece. O texto que os explica vive nas páginas de documentação.</p>

    @can('create', App\Models\Diagram::class)
        {{-- Creates a new Diagram (name only) with the current solution as
             the root node and goes STRAIGHT to its canvas, where the blocks,
             links, protocol and status are authored. Name is optional (falls
             back to the solution's own name), so "Novo" alone is a valid
             gesture. --}}
        <form id="diagram-create-form" class="mt-4 flex items-center gap-2">
            @csrf
            <input type="hidden" name="solution_id" value="{{ $solution->id }}">
            <x-forms.input type="text" name="name" placeholder="Nome do novo diagrama (opcional)"
                class="!h-9 min-w-0 flex-1 !text-sm" />
            <x-forms.button data-ak-ajax="diagram-create-form"
                data-ak-action="{{ route('diagrams.store') }}"
                class="!h-9 !shrink-0 !px-3 !text-xs">
                <x-heroicon-o-plus class="size-4" /> Novo
            </x-forms.button>
        </form>
    @endcan

    <div class="mt-4 flex flex-col gap-2">
        @forelse ($rows as $row)
            @php($diagram = $row['diagram'])
            <a href="{{ $row['editUrl'] }}"
                class="group flex items-start gap-2.5 rounded-field border border-line bg-surface px-3.5 py-2.5 no-underline transition-colors hover:border-accent-line hover:bg-accent-soft/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent">
                {{-- Status dot — quick visual anchor, tinted by status. Same
                     tone map as the pill below it (`App\Enums\DiagramStatus`). --}}
                <span title="{{ $diagram->status->label() }}"
                    class="mt-1.5 size-2 shrink-0 rounded-full {{ $diagram->status->dotClass() }}"></span>

                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-ink">{{ $diagram->name }}</span>
                    {{-- Status and chain summary share the second line: at half
                         the card's width there's no room for a status column of
                         its own beside the name. --}}
                    <span class="mt-1 flex min-w-0 items-center gap-2">
                        <span class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $diagram->status->badgeClass() }}">{{ $diagram->status->label() }}</span>
                        @if ($row['summary'])
                            <span class="min-w-0 truncate font-mono text-xs text-muted">{{ $row['summary'] }}</span>
                        @endif
                    </span>
                </span>

                @can('delete', $diagram)
                    <x-forms.button type="button" variant="ghost"
                        data-ak-ajax="diagram-delete-{{ $diagram->id }}"
                        data-ak-action="{{ route('diagrams.destroy', ['diagram' => $diagram, 'solution' => $solution]) }}"
                        data-ak-confirm="Excluir o diagrama &quot;{{ $diagram->name }}&quot;? Esta ação não pode ser desfeita."
                        title="Excluir diagrama"
                        class="!shrink-0 opacity-45 !p-1.5 transition-opacity group-hover:opacity-100 hover:!text-crit">
                        <x-heroicon-o-trash class="size-4" />
                    </x-forms.button>
                @endcan
                <x-heroicon-o-chevron-right class="mt-1 size-4 shrink-0 text-faint" />
            </a>
            @can('delete', $diagram)
                <form id="diagram-delete-{{ $diagram->id }}" class="hidden">
                    @csrf
                    @method('DELETE')
                </form>
            @endcan
        @empty
            <x-ui.empty-state illustration="diagrams" illustration-class="max-w-[268px]"
                title="Nenhum diagrama ainda"
                description="Crie o primeiro para desenhar o fluxo entre os sistemas e vinculá-lo a uma página de documentação." />
        @endforelse
    </div>
</div>
