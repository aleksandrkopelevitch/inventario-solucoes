{{-- Inner content of the collapsible docs sidebar. The <aside> wrapper that
     animates the width lives in documentation/edit.blade.php, OUTSIDE this
     updatable slot — so the collapsed state survives a page-move slot swap
     (ajax-slot.js replaces the element carrying this $domId wholesale).

     The list is a tree up to `DocumentationPage::MAX_DEPTH` levels deep: rows
     arrive from the controller in reading order (each page followed by its own
     subtree) with their own `depth`, so this stays a single flat `@foreach` —
     one `$loop->index` per row, which is what keeps every hidden form id below
     unique. Recursing instead would either duplicate those ids or need a
     counter threaded through the recursion. --}}
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
                {{-- One indent step per level, each hanging off a guide line,
                     so the rail reads as a tree even where a parent's title is
                     long enough to truncate. The steps are listed rather than
                     computed because Tailwind only ships the classes it can
                     SEE in the source — `ml-{{ $n }}` would compile to nothing.
                     One entry per level below the root, so this list is as long
                     as `DocumentationPage::MAX_DEPTH - 1`; the last one also
                     catches anything deeper, which the server refuses anyway.
                     Beyond level 3 the step halves (`ml-2`): five levels of
                     12px would eat a third of a 288px rail, and what has to
                     survive is the title, not the ceremony. --}}
                @php ($depth = (int) ($page['depth'] ?? 0))
                <li @class([
                    'ml-3 border-l border-line pl-1.5' => $depth === 1,
                    'ml-6 border-l border-line pl-1.5' => $depth === 2,
                    'ml-8 border-l border-line pl-1.5' => $depth === 3,
                    'ml-10 border-l border-line pl-1.5' => $depth >= 4,
                ])>
                    <div @class([
                        'group flex items-center gap-1 rounded-field px-2 py-1.5 transition-colors',
                        'bg-accent-soft' => $page['active'],
                        'hover:bg-raised' => ! $page['active'],
                    ])>
                        <a href="{{ $page['editUrl'] }}" @class([
                            'min-w-0 flex-1 truncate transition-colors',
                            'text-sm' => $depth === 0,
                            'text-[13px]' => $depth >= 1,
                            'font-semibold text-accent' => $page['active'],
                            'text-ink' => ! $page['active'],
                            'italic text-muted' => ! $page['hasContent'],
                        ])>
                            {{ $page['title'] }}
                        </a>

                        {{-- A page that also carries a drawing says so right
                             here, at whatever level it sits: the link is the
                             module's whole point, and the rail is the only
                             place the tree is visible as a tree. Hidden on
                             hover, where the row's action buttons take over the
                             same strip. --}}
                        @if ($page['hasDiagram'] ?? false)
                            <span title="Tem diagrama vinculado" aria-label="Tem diagrama vinculado"
                                class="shrink-0 text-accent transition-opacity group-hover:opacity-0">
                                <x-heroicon-o-share class="size-3.5" />
                            </span>
                        @endif

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

                    {{-- Up/down move a page among its SIBLINGS: a subpage
                         reorders inside its parent and never escapes it that
                         way — changing level is the "Aninhar"/"Promover" pair
                         in the menu below. --}}
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

                        {{-- Offered while there's a level left below this page
                             (`canAddChild`, i.e. everything but the last one) —
                             and StoreDocumentationPageRequest refuses it anyway
                             if the rail turns out to be stale. --}}
                        @if ($page['canAddChild'] ?? false)
                            <x-forms.button type="button" variant="ghost" data-ak-toggle="doc-page-child-{{ $i }}" data-ak-toggle-classes="hidden"
                                class="!justify-start !px-2 !py-1 !text-xs">
                                <x-heroicon-o-plus class="size-3.5" /> Nova subpágina
                            </x-forms.button>
                        @endif

                        {{-- The two level changes, offered only when they're
                             possible: "Aninhar" needs a page above it at the
                             same level AND room for its whole subtree below the
                             cap; "Promover" exists for anything that isn't
                             already at the first level, and moves it ONE level
                             up (a sub-subpage becomes a subpage of its
                             grandparent, not a top-level page). --}}
                        @if ($page['canNest'] ?? false)
                            <x-forms.button type="button" variant="ghost" data-ak-ajax="doc-page-nest-{{ $i }}" data-ak-action="{{ $page['moveUrl'] }}"
                                class="!justify-start !px-2 !py-1 !text-xs">
                                <x-heroicon-o-arrow-small-right class="size-3.5" /> Aninhar na página acima
                            </x-forms.button>
                        @endif
                        @if ($page['canPromote'] ?? false)
                            <x-forms.button type="button" variant="ghost" data-ak-ajax="doc-page-promote-{{ $i }}" data-ak-action="{{ $page['moveUrl'] }}"
                                class="!justify-start !px-2 !py-1 !text-xs">
                                <x-heroicon-o-arrow-small-left class="size-3.5" /> Promover um nível
                            </x-forms.button>
                        @endif

                        @if (! empty($page['destinations'] ?? []))
                            <x-forms.button type="button" variant="ghost" data-ak-toggle="doc-page-container-{{ $i }}" data-ak-toggle-classes="hidden"
                                class="!justify-start !px-2 !py-1 !text-xs">
                                <x-heroicon-o-arrow-right-circle class="size-3.5" /> Mover para…
                            </x-forms.button>
                        @endif

                        {{-- Deleting a parent takes its subpages with it, so the
                             confirmation says so — the rail is the only place
                             that knows how many are about to go. --}}
                        <x-forms.button type="button" variant="ghost" data-ak-ajax="doc-page-destroy-{{ $i }}" data-ak-action="{{ $page['destroyUrl'] }}"
                            data-ak-confirm="Excluir a página &quot;{{ $page['title'] }}&quot;@if ($page['hasChildren'] ?? false) e todas as suas subpáginas@endif? Esta ação não pode ser desfeita."
                            class="!justify-start !px-2 !py-1 !text-xs !text-crit">
                            <x-heroicon-o-trash class="size-3.5" /> Excluir
                        </x-forms.button>
                    </div>

                    <form id="doc-page-destroy-{{ $i }}" class="hidden">
                        @csrf
                        @method('DELETE')
                    </form>

                    <form id="doc-page-nest-{{ $i }}" class="hidden">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="direction" value="in" />
                    </form>
                    <form id="doc-page-promote-{{ $i }}" class="hidden">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="direction" value="out" />
                    </form>

                    <form id="doc-page-rename-{{ $i }}" class="hidden ml-2 mt-1 flex gap-1.5">
                        @csrf
                        @method('PATCH')
                        <x-forms.input name="title" value="{{ $page['title'] }}" class="!text-xs" />
                        <x-forms.button data-ak-ajax="doc-page-rename-{{ $i }}" data-ak-action="{{ $page['renameUrl'] }}" class="!h-8 !shrink-0 !px-2.5 !text-xs">
                            OK
                        </x-forms.button>
                    </form>

                    {{-- Creating a subpage is the SAME endpoint as the rail's
                         "+", plus the parent it goes under — one way a page
                         comes into existence, one redirect straight into it. --}}
                    @if ($page['canAddChild'] ?? false)
                        <form id="doc-page-child-{{ $i }}" class="hidden ml-2 mt-1 flex gap-1.5">
                            @csrf
                            <input type="hidden" name="parent" value="{{ $page['id'] }}" />
                            <x-forms.input name="title" placeholder="Título da subpágina" class="!text-xs" />
                            <x-forms.button data-ak-ajax="doc-page-child-{{ $i }}" data-ak-action="{{ $createPageUrl }}" class="!h-8 !shrink-0 !px-2.5 !text-xs">
                                Criar
                            </x-forms.button>
                        </form>
                    @endif

                    {{-- Re-file the page under another solution or group. The
                         current container is already absent from the options
                         (destinationsFor()), so every choice here is a real
                         move; confirming navigates to the page's new url. A
                         parent takes its subpages along; a subpage moved on its
                         own lands as a top-level page at the destination. --}}
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
    </div>
</div>
