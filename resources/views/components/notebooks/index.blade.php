{{-- The cadernos catalog, as one updatable slot. A card per caderno: what it
     is called, how much of it is written, and which solutions it documents —
     that last row is the whole reason this module exists, so it is on the card
     rather than one level in. --}}
<div id="{{ $domId }}">
    @if ($notebooks->isEmpty())
        <x-ui.empty-state illustration="docs" illustration-class="max-w-[180px]"
            :title="$hasFilters ? 'Nenhum caderno encontrado' : 'Nenhum caderno criado ainda'"
            :description="$hasFilters
                ? 'Ajuste a busca ou os filtros para encontrar o que procura.'
                : 'Crie o primeiro caderno para começar a documentar — ele pode descrever uma solução, várias, ou um processo que atravessa todas elas.'" />
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($notebooks as $notebook)
                <div class="group flex min-w-0 flex-col rounded-card border border-line bg-surface p-5 shadow-card transition-[border-color,box-shadow,transform] hover:-translate-y-0.5 hover:border-accent-line hover:shadow-card-hover">
                    <div class="flex items-start gap-2.5">
                        <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-md bg-accent text-white">
                            <x-heroicon-o-book-open class="size-4" />
                        </span>

                        <a href="{{ $notebook['url'] }}" class="min-w-0 flex-1 no-underline">
                            <h2 class="truncate font-display text-[17px] font-semibold text-ink group-hover:text-accent">
                                {{ $notebook['name'] }}
                            </h2>
                        </a>

                        @if ($notebook['isShared'])
                            <span class="shrink-0 text-accent" title="Tem link público">
                                <x-heroicon-o-globe-alt class="size-4" />
                            </span>
                        @endif

                        {{-- The row's actions. `x-forms.button`, not a raw
                             <button>: the rule is app-wide and the trash icon
                             would otherwise have been a second exception to it
                             sitting beside the first. --}}
                        @if ($notebook['canEdit'])
                            <x-forms.button type="button" variant="ghost"
                                data-ak-panel-open data-ak-panel-url="{{ $notebook['panelUrl'] }}"
                                class="shrink-0 !rounded-md !p-1 !text-faint hover:!bg-raised hover:!text-ink"
                                aria-label="Editar caderno" title="Editar caderno">
                                <x-heroicon-o-pencil-square class="size-4" />
                            </x-forms.button>
                        @endif

                        {{-- Deleting is the admin's (NotebookPolicy::delete →
                             canDelete), so the trash is a missing affordance for
                             an editor rather than a button that refuses.

                             The hidden form is what `data-ak-ajax` builds its
                             FormData from — it carries the CSRF token and the
                             DELETE spoof, and there is no outer <form> on this
                             page for it to nest inside. --}}
                        @if ($notebook['canDelete'])
                            <form id="notebook-delete-{{ $loop->index }}" class="hidden">
                                @csrf
                                @method('DELETE')
                            </form>
                            <x-forms.button type="button" variant="ghost"
                                data-ak-ajax="notebook-delete-{{ $loop->index }}"
                                data-ak-action="{{ $notebook['deleteUrl'] }}"
                                data-ak-confirm="{{ $notebook['deleteConfirm'] }}"
                                class="shrink-0 !rounded-md !p-1 !text-faint hover:!bg-crit-soft hover:!text-crit"
                                aria-label="Excluir caderno" title="Excluir caderno">
                                <x-heroicon-o-trash class="size-4" />
                            </x-forms.button>
                        @endif
                    </div>

                    <p class="mt-3 text-xs text-muted">
                        @if ($notebook['pages'] === 0)
                            Nenhuma página ainda
                        @else
                            <span class="font-display font-semibold text-ink">{{ $notebook['documented'] }}</span>
                            de {{ $notebook['pages'] }} {{ $notebook['pages'] === 1 ? 'página escrita' : 'páginas escritas' }}
                        @endif
                    </p>

                    {{-- The solutions this caderno documents. `mt-auto` pins the
                         row to the bottom so cards in a row line their chips up
                         with each other regardless of title length. --}}
                    <div class="mt-auto pt-4">
                        @if ($notebook['solutions'] !== [])
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($notebook['solutions'] as $solution)
                                    <a href="{{ $solution['url'] }}"
                                        class="inline-flex max-w-full items-center rounded-full bg-accent-soft px-2.5 py-1 text-xs font-medium text-ink no-underline ring-1 ring-accent-line transition-colors hover:bg-accent-line">
                                        <span class="truncate">{{ $solution['name'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @else
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-raised px-2.5 py-1 text-xs text-muted">
                                <x-heroicon-o-link-slash class="size-3.5" />
                                Sem solução vinculada
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
