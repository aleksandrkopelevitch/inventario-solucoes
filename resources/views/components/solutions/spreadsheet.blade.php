{{-- The solutions spreadsheet — ONE body for both audiences (the inventory's
     `solutions.spreadsheet` and the magic link `public.solutions.spreadsheet`),
     the way `x-documentation.reader-body` is one body for `/docs` and
     `public-docs`. What differs is passed in: the dataset (already narrowed to
     the audience by SolutionSpreadsheetService), where the export lives, the
     browser-storage key, and the `heading`/`actions` slots.

     The grid itself is drawn by `solutions-sheet.js` from the JSON below: the
     catalog is ~100 rows, so filtering, sorting and hiding columns happen in
     the browser, and the export is asked for exactly what is on screen. --}}
@props(['sheet', 'exportUrl', 'storageKey'])

@php
    $groups = collect($sheet['columns'])->groupBy('group');
    $menuItem = 'flex w-full items-center gap-2 px-3 py-2 text-[13px] font-medium text-ink no-underline hover:bg-raised';
@endphp

<div data-ak-sheet
     data-ak-sheet-export-url="{{ $exportUrl }}"
     data-ak-sheet-storage="{{ $storageKey }}"
     {{ $attributes->class(['flex min-h-0 flex-1 flex-col']) }}>

    {{-- JSON_HEX_TAG: a `</script>` typed into a description must not end
         this element. --}}
    <script type="application/json" data-ak-sheet-data>{!! json_encode($sheet, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) !!}</script>

    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-line bg-white px-4 py-2.5 md:px-5">
        <div class="flex min-w-0 items-center gap-3">
            {{ $heading ?? '' }}
            <span data-ak-sheet-count class="shrink-0 rounded-full bg-raised px-2 py-0.5 font-mono text-[11px] font-bold text-muted"></span>
        </div>

        <div class="ml-auto flex flex-wrap items-center gap-2">
            <div class="relative w-full sm:w-64">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-faint" />
                <x-forms.input type="search" data-ak-sheet-search
                    placeholder="Buscar nas colunas visíveis"
                    aria-label="Buscar nas colunas visíveis"
                    class="!h-9 !pl-8 !text-[13px]" />
            </div>

            {{-- The hook is on a wrapper: the button's own `inline-flex`
                 would win over a `hidden` toggled onto it. --}}
            <span data-ak-sheet-clear class="hidden">
                <x-forms.button type="button" variant="ghost" class="!h-9 !px-3 !text-[13px] !text-crit">
                    <x-heroicon-o-x-mark class="size-4" /> Limpar filtros
                </x-forms.button>
            </span>

            {{-- Columns: every one listed under its group, so a hidden column
                 is always one click away. The checkboxes are the state;
                 solutions-sheet.js reads them and restores them from storage. --}}
            <div class="relative">
                <x-forms.button type="button" variant="glass"
                    data-ak-toggle="sheet-columns-dropdown" data-ak-toggle-classes="hidden" data-ak-toggle-blur="true"
                    class="!h-9 !px-3 !text-[13px]">
                    <x-heroicon-o-view-columns class="size-4" /> Colunas
                </x-forms.button>
                <div id="sheet-columns-dropdown"
                     class="hidden absolute right-0 top-full z-40 mt-1.5 max-h-[70vh] w-72 overflow-y-auto rounded-field border border-line bg-surface p-3 shadow-xl">
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <span class="text-[11px] font-bold uppercase tracking-[0.12em] text-muted">Colunas visíveis</span>
                        <span class="flex gap-1">
                            <x-forms.button type="button" variant="ghost" data-ak-sheet-columns="all" class="!px-2 !py-1 !text-[12px]">Todas</x-forms.button>
                            <x-forms.button type="button" variant="ghost" data-ak-sheet-columns="default" class="!px-2 !py-1 !text-[12px]">Padrão</x-forms.button>
                        </span>
                    </div>

                    @foreach ($groups as $group => $columns)
                        <p class="mt-2 px-1 pb-1 text-[10px] font-bold uppercase tracking-[0.12em] text-faint">{{ $group }}</p>
                        @foreach ($columns as $column)
                            {{-- The solution's name is the row's identity and
                                 cannot be hidden — a row of attributes with no
                                 name says nothing. --}}
                            <x-forms.label class="flex cursor-pointer items-center gap-2 rounded px-1 py-1 !text-[13px] !leading-5 hover:bg-raised">
                                <x-forms.checkbox data-ak-sheet-column="{{ $column['key'] }}"
                                    :checked="! $column['hidden']" :disabled="$column['key'] === 'name'" />
                                {{ $column['label'] }}
                            </x-forms.label>
                        @endforeach
                    @endforeach
                </div>
            </div>

            <div class="relative">
                <x-forms.button type="button"
                    data-ak-toggle="sheet-export-dropdown" data-ak-toggle-classes="hidden" data-ak-toggle-blur="true"
                    class="!h-9 !px-3 !text-[13px]">
                    <x-heroicon-o-arrow-down-tray class="size-4" /> Exportar
                </x-forms.button>
                <div id="sheet-export-dropdown"
                     class="hidden absolute right-0 top-full z-40 mt-1.5 w-64 overflow-hidden rounded-field border border-line bg-surface py-1 shadow-xl">
                    <p class="px-3 pb-1 pt-2 text-[11px] text-muted">Exporta as linhas e colunas que estão na tela.</p>
                    {{-- Real links, so they download even before the module
                         runs; solutions-sheet.js adds the visible columns and
                         rows to the query string on click. --}}
                    <a href="{{ $exportUrl }}?format=xlsx" data-ak-sheet-export="xlsx" class="{{ $menuItem }}">
                        <x-heroicon-o-table-cells class="size-4 text-accent" /> Excel (.xlsx)
                    </a>
                    <a href="{{ $exportUrl }}?format=csv" data-ak-sheet-export="csv" class="{{ $menuItem }}">
                        <x-heroicon-o-document-text class="size-4 text-muted" /> CSV
                    </a>
                </div>
            </div>

            {{ $actions ?? '' }}
        </div>
    </div>

    <div data-ak-sheet-grid class="min-h-0 flex-1 overflow-auto bg-white">
        <p class="p-6 text-sm text-muted">Carregando planilha…</p>
    </div>

    {{-- The per-column value filter: one popover, moved under whichever header
         opened it. --}}
    <div data-ak-sheet-popover role="dialog" aria-label="Filtrar coluna"
         class="hidden fixed z-50 w-72 rounded-field border border-line bg-surface p-3 shadow-xl">
        <p data-ak-sheet-popover-title class="mb-2 truncate text-[11px] font-bold uppercase tracking-[0.12em] text-muted"></p>

        <div data-ak-sheet-popover-sort class="mb-2 flex gap-1">
            <x-forms.button type="button" variant="ghost" data-ak-sheet-sort="asc" class="!flex-1 !px-2 !py-1 !text-[12px]">
                <x-heroicon-o-bars-arrow-down class="size-4" /> Crescente
            </x-forms.button>
            <x-forms.button type="button" variant="ghost" data-ak-sheet-sort="desc" class="!flex-1 !px-2 !py-1 !text-[12px]">
                <x-heroicon-o-bars-arrow-up class="size-4" /> Decrescente
            </x-forms.button>
        </div>

        <x-forms.input type="search" data-ak-sheet-popover-search placeholder="Buscar valor"
            aria-label="Buscar valor" class="!h-8 !text-[13px]" />

        <div class="mt-2 flex items-center justify-between text-[12px]">
            <x-forms.button type="button" variant="ghost" data-ak-sheet-popover-all class="!px-2 !py-1 !text-[12px]">Marcar todos</x-forms.button>
            <x-forms.button type="button" variant="ghost" data-ak-sheet-popover-none class="!px-2 !py-1 !text-[12px]">Desmarcar todos</x-forms.button>
        </div>

        <div data-ak-sheet-popover-list class="mt-1 max-h-64 overflow-y-auto"></div>

        <div class="mt-2 flex justify-end border-t border-line pt-2">
            <x-forms.button type="button" variant="ghost" data-ak-sheet-popover-reset class="!px-2 !py-1 !text-[12px] !text-crit">
                Limpar filtro da coluna
            </x-forms.button>
        </div>
    </div>

    {{-- Icons for the JS-drawn header, rendered here so they are the same
         heroicons as the rest of the app. --}}
    <template data-ak-sheet-icon="filter"><x-heroicon-s-funnel class="size-3.5" /></template>
    <template data-ak-sheet-icon="asc"><x-heroicon-s-arrow-up class="size-3" /></template>
    <template data-ak-sheet-icon="desc"><x-heroicon-s-arrow-down class="size-3" /></template>
</div>
