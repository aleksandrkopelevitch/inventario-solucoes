{{-- The diagrams index's list (updatable slot). One card per diagram: name +
     status, the chain summary, and the pages that explain it — the second half
     being the point of the whole module, since a drawing nobody wrote about is
     exactly what used to be invisible. --}}
<div id="{{ $domId }}">
    @if ($rows->isEmpty())
        @if (collect($filters)->filter(fn ($value) => filled($value))->isNotEmpty())
            <p class="rounded-card border border-dashed border-line bg-surface px-4 py-12 text-center text-sm text-muted">
                Nenhum diagrama corresponde aos filtros.
            </p>
        @else
            <x-ui.empty-state illustration="diagrams" illustration-class="max-w-[300px]"
                title="Nenhum diagrama ainda"
                description="Desenhe o primeiro fluxo e vincule-o às páginas de documentação que o explicam." />
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
                                    data-ak-confirm="Excluir o diagrama &quot;{{ $diagram->name }}&quot;? As páginas vinculadas continuam existindo, apenas sem diagrama."
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

                    {{-- Where it's explained. A diagram with no page is the gap
                         this list exists to show, so it gets a line of its own
                         rather than simply nothing. --}}
                    @if ($row['pages']->isNotEmpty())
                        <ul class="divide-y divide-line border-t border-line">
                            @foreach ($row['pages'] as $page)
                                <li>
                                    <a href="{{ $page['url'] }}" class="flex items-center justify-between gap-3 px-4 py-2.5 pl-6 text-sm no-underline transition-colors hover:bg-raised">
                                        <span class="flex min-w-0 items-center gap-2 text-ink">
                                            <x-heroicon-o-document-text class="size-3.5 shrink-0 text-faint" />
                                            <span class="truncate">{{ $page['title'] }}</span>
                                        </span>
                                        @if ($page['notebook'])
                                            <span class="shrink-0 truncate text-xs text-muted">{{ $page['notebook'] }}</span>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="flex items-center gap-2 border-t border-dashed border-line px-4 py-2.5 pl-6 text-xs text-faint">
                            <x-heroicon-o-exclamation-triangle class="size-3.5 shrink-0" />
                            Nenhuma página de documentação aponta para este diagrama.
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
