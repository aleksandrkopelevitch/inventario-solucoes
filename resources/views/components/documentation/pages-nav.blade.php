<div id="{{ $domId }}" class="w-64 shrink-0 border-r border-line bg-white p-3">
    <div class="flex items-center justify-between gap-2 px-1">
        <span class="text-xs font-semibold uppercase tracking-wide text-faint">Páginas</span>
        <x-forms.button type="button" variant="ghost" data-ak-toggle="doc-new-page-form" data-ak-toggle-classes="hidden"
            class="!h-7 !w-7 !p-0" aria-label="Nova página" title="Nova página">
            <x-heroicon-o-plus class="size-4" />
        </x-forms.button>
    </div>

    <form id="doc-new-page-form" class="hidden mt-2 flex gap-1.5 px-1">
        @csrf
        <x-forms.input name="title" placeholder="Título da página" class="!text-xs" autofocus />
        <x-forms.button data-ak-ajax="doc-new-page-form" data-ak-action="{{ $createPageUrl }}" class="!h-8 !shrink-0 !px-2.5 !text-xs">
            Criar
        </x-forms.button>
    </form>

    <ul class="mt-2 flex flex-col gap-0.5">
        @foreach ($pages as $page)
            @php ($i = $loop->index)
            <li>
                <div @class([
                    'group flex items-center gap-1 rounded-field px-2 py-1.5',
                    'bg-accent-soft' => $page['active'],
                    'hover:bg-raised' => ! $page['active'],
                ])>
                    <a href="{{ $page['editUrl'] }}" @class([
                        'min-w-0 flex-1 truncate text-sm',
                        'font-semibold text-accent' => $page['active'],
                        'text-ink' => ! $page['active'],
                        'italic text-muted' => ! $page['hasContent'],
                    ])>
                        {{ $page['title'] }}
                    </a>

                    <div class="flex shrink-0 items-center opacity-0 transition-opacity group-hover:opacity-100">
                        <x-forms.button type="button" variant="ghost" data-ak-ajax="doc-page-move-up-{{ $i }}" data-ak-action="{{ $page['moveUrl'] }}"
                            class="!h-6 !w-6 !p-0" title="Mover para cima" aria-label="Mover para cima">
                            <x-heroicon-o-chevron-up class="size-3.5" />
                        </x-forms.button>
                        <x-forms.button type="button" variant="ghost" data-ak-ajax="doc-page-move-down-{{ $i }}" data-ak-action="{{ $page['moveUrl'] }}"
                            class="!h-6 !w-6 !p-0" title="Mover para baixo" aria-label="Mover para baixo">
                            <x-heroicon-o-chevron-down class="size-3.5" />
                        </x-forms.button>
                        <x-forms.button type="button" variant="ghost" data-ak-toggle="doc-page-menu-{{ $i }}" data-ak-toggle-classes="hidden" data-ak-toggle-blur="true"
                            class="!h-6 !w-6 !p-0" title="Mais ações" aria-label="Mais ações">
                            <x-heroicon-o-ellipsis-vertical class="size-3.5" />
                        </x-forms.button>
                    </div>
                </div>

                <form id="doc-page-move-up-{{ $i }}" class="hidden">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="direction" value="up" />
                </form>
                <form id="doc-page-move-down-{{ $i }}" class="hidden">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="direction" value="down" />
                </form>

                <div id="doc-page-menu-{{ $i }}" class="hidden ml-2 mt-1 flex flex-col gap-1 rounded-field border border-line bg-white p-2 shadow-sm">
                    <x-forms.button type="button" variant="ghost" data-ak-toggle="doc-page-rename-{{ $i }}" data-ak-toggle-classes="hidden"
                        class="!justify-start !px-2 !py-1 !text-xs">
                        <x-heroicon-o-pencil class="size-3.5" /> Renomear
                    </x-forms.button>
                    <x-forms.button type="button" variant="ghost" data-ak-ajax="doc-page-destroy-{{ $i }}" data-ak-action="{{ $page['destroyUrl'] }}"
                        data-ak-confirm="Excluir a página &quot;{{ $page['title'] }}&quot;? Esta ação não pode ser desfeita."
                        class="!justify-start !px-2 !py-1 !text-xs !text-crit">
                        <x-heroicon-o-trash class="size-3.5" /> Excluir
                    </x-forms.button>
                </div>
                <form id="doc-page-destroy-{{ $i }}" class="hidden">
                    @csrf
                    @method('DELETE')
                </form>

                <form id="doc-page-rename-{{ $i }}" class="hidden ml-2 mt-1 flex gap-1.5">
                    @csrf
                    @method('PATCH')
                    <x-forms.input name="title" value="{{ $page['title'] }}" class="!text-xs" />
                    <x-forms.button data-ak-ajax="doc-page-rename-{{ $i }}" data-ak-action="{{ $page['renameUrl'] }}" class="!h-8 !shrink-0 !px-2.5 !text-xs">
                        OK
                    </x-forms.button>
                </form>
            </li>
        @endforeach
    </ul>

    {{-- Documentação de cada Integration em que a Solution participa —
         consolidada nesta mesma árvore (não é uma tela à parte). Somente
         link: renomear/mover/apagar uma integração não faz sentido aqui. --}}
    @if (count($integrations))
        <div class="mt-4 border-t border-line pt-3">
            <span class="px-1 text-xs font-semibold uppercase tracking-wide text-faint">Integrações</span>

            <ul class="mt-2 flex flex-col gap-0.5">
                @foreach ($integrations as $integration)
                    <li>
                        <a href="{{ $integration['editUrl'] }}" @class([
                            'block truncate rounded-field px-2 py-1.5 text-sm',
                            'bg-accent-soft font-semibold text-accent' => $integration['active'],
                            'text-ink hover:bg-raised' => ! $integration['active'],
                            'italic text-muted' => ! $integration['hasContent'],
                        ])>
                            {{ $integration['title'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
