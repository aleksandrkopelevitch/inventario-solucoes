{{-- Generated diagrams for this page. Rendered inside the reading screen's
     right rail, above "Nesta página".

     The whole block disappears when there is nothing — a rail heading over an
     empty list states a feature, not a fact, and the rail is for facts. --}}
<div id="{{ \App\View\Components\Documentation\PageArtifacts::DOM_ID }}">
    @if ($artifacts->isNotEmpty())
        <div class="mb-6">
            <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted">Diagramas gerados</p>

            <ul class="flex flex-col gap-1">
                @foreach ($artifacts as $artifact)
                    <li class="group flex items-center gap-1.5">
                        {{-- A new tab, always: the artifact is a standalone
                             interactive document served under its own sandbox,
                             not a fragment of this page. --}}
                        <a href="{{ $artifact['url'] }}" target="_blank" rel="noopener"
                           class="flex min-w-0 flex-1 items-center gap-1.5 rounded-md px-1.5 py-1 text-xs text-ink hover:bg-accent-soft">
                            <span class="shrink-0 text-muted">
                                @if ($artifact['type'])
                                    <x-dynamic-component :component="'heroicon-o-' . $artifact['type']->icon()" class="size-3.5" />
                                @else
                                    <x-heroicon-o-document class="size-3.5" />
                                @endif
                            </span>
                            <span class="truncate">{{ $artifact['title'] }}</span>
                        </a>

                        @if ($canEdit)
                            <form id="artifact-delete-{{ $artifact['id'] }}" class="contents">
                                <x-forms.button variant="ghost"
                                    data-ak-ajax="artifact-delete-{{ $artifact['id'] }}"
                                    data-ak-action="{{ $artifact['deleteUrl'] }}"
                                    data-ak-confirm="Remover este diagrama gerado?"
                                    class="!h-6 !w-6 !p-0 opacity-0 transition-opacity group-hover:opacity-100"
                                    aria-label="Remover diagrama" title="Remover diagrama">
                                    <x-heroicon-o-trash class="size-3.5" />
                                </x-forms.button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
