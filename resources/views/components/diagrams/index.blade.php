{{-- The diagrams index's list (updatable slot). One card per diagram: name +
     status, the caderno it belongs to, the chain summary, and the systems it
     names. Drawings of every caderno sit together only here, which is why the
     caderno is on every row. --}}
<div id="{{ $domId }}">
    @if ($rows->isEmpty())
        @if (collect($filters)->filter(fn ($value) => filled($value))->isNotEmpty())
            <p class="rounded-card border border-dashed border-line bg-surface px-4 py-12 text-center text-sm text-muted">
                Nenhum diagrama corresponde aos filtros.
            </p>
        @else
            <x-ui.empty-state illustration="diagrams" illustration-class="max-w-[300px]"
                title="Nenhum diagrama ainda"
                description="Diagramas nascem dentro de um caderno: abra uma página e use &quot;Desenhar esta página&quot;, ou &quot;Diagramas do caderno&quot; no menu lateral." />
        @endif
    @else
        <div class="space-y-3">
            @foreach ($rows as $row)
                @php ($diagram = $row['diagram'])
                <div class="rounded-card border border-line bg-surface shadow-card">
                    <div class="flex items-start justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <div class="flex min-w-0 items-center gap-2.5">
                                <span title="{{ $diagram->status->label() }}"
                                    class="size-2 shrink-0 rounded-full {{ $diagram->status->dotClass() }}"></span>
                                <a href="{{ $row['url'] }}" class="truncate font-display text-[15px] font-semibold text-ink no-underline transition-colors hover:text-accent">
                                    {{ $diagram->name }}
                                </a>
                                <span class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $diagram->status->badgeClass() }}">{{ $diagram->status->label() }}</span>
                            </div>

                            <a href="{{ route('notebooks.show', $row['notebook']) }}"
                                class="mt-1.5 inline-flex max-w-full items-center gap-1.5 rounded-full border border-line bg-raised px-2 py-0.5 text-[11px] font-medium text-muted no-underline transition-colors hover:border-accent-line hover:text-ink">
                                <x-heroicon-o-book-open class="size-3.5 shrink-0 text-accent" />
                                <span class="truncate">Caderno: {{ $row['notebook']->name }}</span>
                            </a>

                            <div class="mt-1.5 min-w-0">
                                @if ($row['blocks'] > 1 && $row['summary'])
                                    <span class="block truncate font-mono text-xs text-muted">{{ $row['summary'] }}</span>
                                @else
                                    {{-- One block is a canvas nobody drew on yet, not a
                                         drawing — say so instead of printing a
                                         one-name "summary" that reads like content. --}}
                                    <span class="text-xs italic text-faint">Canvas ainda em branco</span>
                                @endif
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-1.5">
                            <a href="{{ $row['url'] }}"
                                class="inline-flex items-center gap-1.5 rounded-field border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink no-underline transition-colors hover:border-accent-line hover:bg-accent-soft/40">
                                <x-heroicon-o-share class="size-4" />
                                Abrir canvas
                            </a>
                            @can('delete', $diagram)
                                <x-forms.button type="button" variant="ghost"
                                    data-ak-ajax="diagram-index-delete-{{ $diagram->id }}"
                                    data-ak-action="{{ route('diagrams.destroy', $diagram) }}"
                                    data-ak-confirm="Excluir o diagrama &quot;{{ $diagram->name }}&quot;? Páginas que citam este desenho continuam existindo — a citação passa a mostrar que ele foi removido."
                                    title="Excluir diagrama"
                                    class="opacity-45 !p-1.5 transition-opacity hover:opacity-100 hover:!text-crit">
                                    <x-heroicon-o-trash class="size-4" />
                                </x-forms.button>
                                <form id="diagram-index-delete-{{ $diagram->id }}" class="hidden">
                                    @csrf
                                    @method('DELETE')
                                </form>
                            @endcan
                        </div>
                    </div>

                    {{-- The systems the drawing names. One that names none floats
                         free of the catalog — the ecosystem map can't place it —
                         and the component says so rather than drawing nothing. --}}
                    <div class="border-t border-line px-4 py-3">
                        <x-diagrams.solution-chips :solutions="$row['solutions']" />
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
