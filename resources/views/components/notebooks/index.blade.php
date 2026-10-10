{{-- The cadernos catalog, as one updatable slot. A row per caderno, in the same
     table chrome as the solutions catalog: what it is called, how much of it is
     written, and which solutions it documents — that last column is the whole
     reason this module exists, so it is on the row rather than one level in.

     "Em /docs" is whether the internal knowledge base shows the caderno. An
     admin gets the switch that decides it; everybody else reads the state. --}}
@php
    [$sortForm, $sortUrl] = ['notebooks-filter-form', route('notebooks.index')];
@endphp
<div id="{{ $domId }}">
    @if ($notebooks->isEmpty())
        <x-ui.empty-state illustration="docs" illustration-class="max-w-[180px]"
            :title="$hasFilters ? 'Nenhum caderno encontrado' : 'Nenhum caderno criado ainda'"
            :description="$hasFilters
                ? 'Ajuste a busca ou os filtros para encontrar o que procura.'
                : 'Crie o primeiro caderno para começar a documentar — ele pode descrever uma solução, várias, ou um processo que atravessa todas elas.'" />
    @else
        <div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[860px] text-sm">
                    <thead>
                        <tr class="border-b border-line bg-raised/40">
                            <x-ui.sortable-th column="name" :filters="$filters" :form-id="$sortForm" :url="$sortUrl" class="pl-4">Nome</x-ui.sortable-th>
                            <x-ui.sortable-th column="pages" :filters="$filters" :form-id="$sortForm" :url="$sortUrl">Páginas</x-ui.sortable-th>
                            <x-ui.sortable-th column="solutions" :filters="$filters" :form-id="$sortForm" :url="$sortUrl">Soluções</x-ui.sortable-th>
                            <th scope="col" class="whitespace-nowrap px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted">Em /docs</th>
                            <th scope="col" class="py-2.5 pr-4"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        {{-- Stagger capped like the solutions table, so a long
                             catalog doesn't cascade for seconds. --}}
                        @foreach ($notebooks as $notebook)
                            <tr class="animate-ak-rise transition-colors hover:bg-raised/40"
                                style="animation-delay: {{ min($loop->index, 11) * 25 }}ms">
                                <td class="py-2.5 pl-4 pr-3">
                                    <a href="{{ $notebook['url'] }}" class="flex min-w-0 items-center gap-3 no-underline">
                                        <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-md bg-accent text-white">
                                            <x-heroicon-o-book-open class="size-4" />
                                        </span>
                                        <span class="min-w-0 truncate font-display text-[15px] font-semibold text-ink hover:text-accent">
                                            {{ $notebook['name'] }}
                                        </span>
                                        @if ($notebook['isShared'])
                                            <span class="shrink-0 text-accent" title="Tem link público">
                                                <x-heroicon-o-globe-alt class="size-4" />
                                            </span>
                                        @endif
                                    </a>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-muted">
                                    @if ($notebook['pages'] === 0)
                                        <span class="text-faint">Nenhuma página ainda</span>
                                    @else
                                        <span class="font-display font-semibold text-ink">{{ $notebook['documented'] }}</span>
                                        de {{ $notebook['pages'] }} {{ $notebook['pages'] === 1 ? 'escrita' : 'escritas' }}
                                    @endif
                                </td>
                                <td class="px-3 py-2.5">
                                    @if ($notebook['solutions'] !== [])
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach ($notebook['solutions'] as $solution)
                                                <a href="{{ $solution['url'] }}"
                                                    class="inline-flex max-w-[14rem] items-center rounded-full bg-accent-soft px-2 py-0.5 text-[11px] font-medium text-ink no-underline ring-1 ring-accent-line transition-colors hover:bg-accent-line">
                                                    <span class="truncate">{{ $solution['name'] }}</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 whitespace-nowrap text-faint">
                                            <x-heroicon-o-link-slash class="size-3.5" />
                                            Sem solução vinculada
                                        </span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-2.5">
                                    <div class="flex items-center gap-2">
                                        @if ($canPublish)
                                            {{-- The switch. An `x-forms.button` rather than
                                                 `x-forms.toggle`, because this one WRITES on
                                                 click: the toggle component is a CSS-only
                                                 checkbox for a form submitted later, and there
                                                 is no later here. `aria-pressed` is what says
                                                 "switch" to a screen reader.

                                                 The hidden form is what `data-ak-ajax` builds
                                                 its FormData from: the CSRF token, the PATCH
                                                 spoof and the value being written. --}}
                                            <form id="notebook-publication-{{ $loop->index }}" class="hidden">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="published" value="{{ $notebook['published'] ? '0' : '1' }}">
                                            </form>
                                            <x-forms.button type="button" variant="ghost"
                                                data-ak-ajax="notebook-publication-{{ $loop->index }}"
                                                data-ak-action="{{ $notebook['toggleUrl'] }}"
                                                data-ak-confirm="{{ $notebook['publishConfirm'] }}"
                                                aria-pressed="{{ $notebook['published'] ? 'true' : 'false' }}"
                                                class="shrink-0 !h-6 !w-11 !rounded-full !p-0 {{ $notebook['published'] ? '!bg-accent' : '!bg-line-2' }}"
                                                :aria-label="($notebook['published'] ? 'Tirar' : 'Publicar') . ' ' . $notebook['name'] . ' na base de conhecimento'"
                                                :title="$notebook['published'] ? 'Publicado em /docs desde ' . $notebook['publishedAt'] : 'Não publicado em /docs'">
                                                <span @class([
                                                    'block size-5 rounded-full bg-white shadow transition-transform',
                                                    'translate-x-[10px]' => $notebook['published'],
                                                    '-translate-x-[10px]' => ! $notebook['published'],
                                                ])></span>
                                            </x-forms.button>
                                        @endif

                                        @if ($notebook['published'])
                                            <a href="{{ $notebook['readUrl'] }}" target="_blank" rel="noopener"
                                                class="inline-flex items-center gap-1 text-xs font-medium text-accent no-underline hover:underline"
                                                title="Abrir em /docs">
                                                {{ $canPublish ? 'Abrir' : 'Publicado' }}
                                                <x-heroicon-o-arrow-top-right-on-square class="size-3.5" />
                                            </a>
                                        @elseif (! $canPublish)
                                            <span class="text-faint">—</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="py-2.5 pl-3 pr-4">
                                    <div class="flex items-center justify-end gap-1">
                                        {{-- The row's actions. `x-forms.button`, not a
                                             raw <button>: the rule is app-wide and the
                                             trash icon would otherwise have been a
                                             second exception to it sitting beside the
                                             first. --}}
                                        @if ($notebook['canEdit'])
                                            <x-forms.button type="button" variant="ghost"
                                                data-ak-panel-open data-ak-panel-url="{{ $notebook['panelUrl'] }}"
                                                class="shrink-0 !rounded-md !p-1.5 !text-faint hover:!bg-raised hover:!text-accent"
                                                aria-label="Editar caderno" title="Editar caderno">
                                                <x-heroicon-o-pencil-square class="size-4" />
                                            </x-forms.button>
                                        @endif

                                        {{-- Deleting is the admin's (NotebookPolicy::delete
                                             → canDelete), so the trash is a missing
                                             affordance for an editor rather than a button
                                             that refuses.

                                             The hidden form is what `data-ak-ajax` builds
                                             its FormData from — it carries the CSRF token
                                             and the DELETE spoof, and there is no outer
                                             <form> on this page for it to nest inside. --}}
                                        @if ($notebook['canDelete'])
                                            <form id="notebook-delete-{{ $loop->index }}" class="hidden">
                                                @csrf
                                                @method('DELETE')
                                            </form>
                                            <x-forms.button type="button" variant="ghost"
                                                data-ak-ajax="notebook-delete-{{ $loop->index }}"
                                                data-ak-action="{{ $notebook['deleteUrl'] }}"
                                                data-ak-confirm="{{ $notebook['deleteConfirm'] }}"
                                                class="shrink-0 !rounded-md !p-1.5 !text-faint hover:!bg-crit-soft hover:!text-crit"
                                                aria-label="Excluir caderno" title="Excluir caderno">
                                                <x-heroicon-o-trash class="size-4" />
                                            </x-forms.button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
