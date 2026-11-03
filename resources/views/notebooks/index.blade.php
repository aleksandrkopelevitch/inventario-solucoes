@php
    $filterBind = ['formId' => 'notebooks-filter-form', 'url' => route('notebooks.index'), 'event' => 'change'];
    $activeClass = '!border-accent !bg-accent-soft !text-accent !font-semibold';
@endphp

<x-layouts.layout title="Cadernos">
    <x-ui.hero-panel class="mb-6">
        <x-ui.page-header large eyebrow="Documentação" title="Cadernos"
            description="Cada caderno é um corpo de documentação — como um espaço no GitBook. Ele pode descrever várias soluções ao mesmo tempo, ou nenhuma.">
            <x-slot:actions>
                @can('create', \App\Models\Notebook::class)
                    <x-forms.button href="#" data-ak-panel-open data-ak-panel-url="{{ route('notebooks.panel.create', ['filter' => $filters]) }}"
                        class="!rounded-full">
                        <x-heroicon-o-plus class="size-4" /> Novo caderno
                    </x-forms.button>
                @endcan
                <x-forms.button href="{{ route('documentation.index') }}" variant="glass" class="!rounded-full">
                    Ver cobertura <x-heroicon-o-arrow-right class="size-4" />
                </x-forms.button>
                {{-- `/docs` has no rail entry — it is its own shell — so the
                     catalog that decides what it shows is where it is reached from. --}}
                <x-forms.button href="{{ route('docs.index') }}" target="_blank" rel="noopener" variant="glass" class="!rounded-full">
                    Abrir a base de conhecimento <x-heroicon-o-arrow-top-right-on-square class="size-4" />
                </x-forms.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-ui.hero-panel>

    {{-- Search + filters, one bar (same form: search is a filter[] field, so it
         survives every filter change). --}}
    <x-ui.filter-bar form-id="notebooks-filter-form">
        <x-slot:search>
            <x-ui.filter-search id="notebooks-search" :url="route('notebooks.index')"
                placeholder="Buscar por caderno, página ou solução"
                :value="$filters['search'] ?? null" />
        </x-slot:search>

        {{-- Driven by the table's column headers (`x-ui.sortable-th`), not a
             control of its own — see the same field on /solutions. --}}
        <input type="hidden" name="filter[sort]" value="{{ $filters['sort'] ?? 'name' }}">

        <x-forms.select auto name="filter[status]" data-ak-filters="{{ json_encode($filterBind) }}"
            class="{{ filled($filters['status'] ?? null) ? $activeClass : '' }}">
            <option value="">Situação</option>
            <option value="documented" @selected(($filters['status'] ?? '') === 'documented')>Com conteúdo</option>
            <option value="empty" @selected(($filters['status'] ?? '') === 'empty')>Sem conteúdo</option>
            <option value="shared" @selected(($filters['status'] ?? '') === 'shared')>Com link público</option>
            <option value="unlinked" @selected(($filters['status'] ?? '') === 'unlinked')>Sem solução vinculada</option>
            <option value="published" @selected(($filters['status'] ?? '') === 'published')>Publicados em /docs</option>
            <option value="unpublished" @selected(($filters['status'] ?? '') === 'unpublished')>Não publicados em /docs</option>
        </x-forms.select>
    </x-ui.filter-bar>

    <x-notebooks.index :filters="$filters" />
</x-layouts.layout>
