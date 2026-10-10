{{-- The cadernos catalog, as one updatable slot. A row per caderno, in the same
     table chrome as the solutions catalog: what it is called, how much of it is
     written, and which solutions it documents — that last column is the whole
     reason this module exists, so it is on the row rather than one level in. --}}
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
                <table class="w-full min-w-[760px] text-sm">
                    <thead>
                        <tr class="border-b border-line bg-raised/40">
                            <x-ui.sortable-th column="name" :filters="$filters" :form-id="$sortForm" :url="$sortUrl" class="pl-4">Nome</x-ui.sortable-th>
                            <x-ui.sortable-th column="pages" :filters="$filters" :form-id="$sortForm" :url="$sortUrl">Páginas</x-ui.sortable-th>
                            <x-ui.sortable-th column="solutions" :filters="$filters" :form-id="$sortForm" :url="$sortUrl">Soluções</x-ui.sortable-th>
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
