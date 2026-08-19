{{-- Inner content of the collapsible docs sidebar. The <aside> wrapper that
     animates the width lives in documentation/edit.blade.php, OUTSIDE this
     updatable slot — so the collapsed state survives a page-move slot swap
     (ajax-slot.js replaces the element carrying this $domId wholesale). --}}
<div id="{{ $domId }}" class="flex w-72 flex-1 flex-col overflow-hidden">
    {{-- The rail is titled with what it documents — the solution (or group) —
         not with the generic word "Páginas": inside a solution's docs, every
         page in the list is already a page, and the one thing the screen never
         said was WHOSE they are. The ↗ opens that record's own page, the same
         split as everywhere else in the app (words read, icon travels). --}}
    <div class="flex items-center justify-between gap-2 border-b border-line px-3 py-2.5">
        <span class="flex min-w-0 items-center gap-1.5">
            <span class="truncate text-[11px] font-bold uppercase tracking-[0.12em] text-faint">{{ $title }}</span>
            @if ($titleUrl)
                <x-ui.external-link :href="$titleUrl" :label="$title" class="text-faint" />
            @endif
        </span>
        <x-forms.button type="button" variant="ghost" data-ak-toggle="doc-new-page-form" data-ak-toggle-classes="hidden"
            class="!h-7 !w-7 !p-0" aria-label="Nova página" title="Nova página">
            <x-heroicon-o-plus class="size-4" />
        </x-forms.button>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto p-2">
        <form id="doc-new-page-form" class="hidden mb-2 flex gap-1.5">
            @csrf
            <x-forms.input name="title" placeholder="Título da página" class="!text-xs" autofocus />
            <x-forms.button data-ak-ajax="doc-new-page-form" data-ak-action="{{ $createPageUrl }}" class="!h-8 !shrink-0 !px-2.5 !text-xs">
                Criar
            </x-forms.button>
        </form>

        <ul class="flex flex-col gap-0.5">
            @foreach ($pages as $page)
                @php ($i = $loop->index)
                <li>
                    <div @class([
                        'group flex items-center gap-1 rounded-field px-2 py-1.5 transition-colors',
                        'bg-accent-soft' => $page['active'],
                        'hover:bg-raised' => ! $page['active'],
                    ])>
                        <a href="{{ $page['editUrl'] }}" @class([
                            'min-w-0 flex-1 truncate text-sm transition-colors',
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
                        @if (! empty($page['destinations'] ?? []))
                            <x-forms.button type="button" variant="ghost" data-ak-toggle="doc-page-container-{{ $i }}" data-ak-toggle-classes="hidden"
                                class="!justify-start !px-2 !py-1 !text-xs">
                                <x-heroicon-o-arrow-right-circle class="size-3.5" /> Mover para…
                            </x-forms.button>
                        @endif
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

                    {{-- Re-file the page under another solution or group. The
                         current container is already absent from the options
                         (destinationsFor()), so every choice here is a real
                         move; confirming navigates to the page's new url. --}}
                    @if (! empty($page['destinations'] ?? []))
                        <form id="doc-page-container-{{ $i }}" class="hidden ml-2 mt-1 flex gap-1.5">
                            @csrf
                            @method('PATCH')
                            <x-forms.select name="container" class="!text-xs" aria-label="Mover a página para">
                                @foreach ($page['destinations'] as $optgroup => $options)
                                    <optgroup label="{{ $optgroup }}">
                                        @foreach ($options as $option)
                                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </x-forms.select>
                            <x-forms.button data-ak-ajax="doc-page-container-{{ $i }}" data-ak-action="{{ $page['containerUrl'] }}" class="!h-8 !shrink-0 !px-2.5 !text-xs">
                                Mover
                            </x-forms.button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>

        {{-- Documentation for each Integration the Solution participates in —
             consolidated into this same tree (not a separate screen). Link
             only: renaming/moving/deleting an integration doesn't make sense here. --}}
        @if (count($integrations))
            <div class="mt-4 border-t border-line pt-3">
                <span class="px-1 text-[11px] font-bold uppercase tracking-[0.12em] text-faint">Integrações</span>

                <ul class="mt-2 flex flex-col gap-0.5">
                    @foreach ($integrations as $integration)
                        <li>
                            <a href="{{ $integration['editUrl'] }}" @class([
                                'block truncate rounded-field px-2 py-1.5 text-sm transition-colors',
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
</div>
