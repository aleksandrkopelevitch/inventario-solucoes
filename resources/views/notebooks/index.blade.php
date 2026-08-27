@php
    $filterBind = ['formId' => 'notebooks-filter-form', 'url' => route('notebooks.index'), 'event' => 'change'];
    $activeClass = '!border-accent !bg-accent-soft !text-accent !font-semibold';
@endphp

<x-layouts.layout title="Cadernos">
    <x-ui.hero-panel class="mb-6">
        <span class="flex items-center gap-2 font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-[color:var(--color-glow-ink)]/70">
            <span class="size-2 rounded-full" style="background: linear-gradient(115deg, var(--color-glow-a), var(--color-lime))"></span>
            Documentação
        </span>
        <h1 class="mt-3 font-display text-[44px] font-bold leading-[0.98] tracking-tight text-[color:var(--color-glow-ink)]">
            Cadernos
        </h1>
        <p class="mt-3 max-w-xl text-[15px] leading-relaxed text-[color:var(--color-glow-ink)]/70">
            Cada caderno é um corpo de documentação — como um espaço no GitBook. Ele
            pode descrever várias soluções ao mesmo tempo, ou nenhuma.
        </p>
        <div class="mt-5 flex flex-wrap items-center gap-3">
            @can('create', \App\Models\Notebook::class)
                <x-forms.button href="#" data-ak-panel-open data-ak-panel-url="{{ route('notebooks.panel.create', ['filter' => $filters]) }}"
                    class="!rounded-full">
                    <x-heroicon-o-plus class="size-4" /> Novo caderno
                </x-forms.button>
            @endcan
            <x-forms.button href="{{ route('documentation.index') }}" variant="glass" class="!rounded-full">
                Ver cobertura <x-heroicon-o-arrow-right class="size-4" />
            </x-forms.button>
        </div>
    </x-ui.hero-panel>

    {{-- Search + filters, one bar (same form: search is a filter[] field, so it
         survives every filter change). --}}
    <x-ui.filter-bar form-id="notebooks-filter-form">
        <x-slot:search>
            <x-ui.filter-search id="notebooks-search" :url="route('notebooks.index')"
                placeholder="Buscar por caderno, página ou solução"
                :value="$filters['search'] ?? null" />
        </x-slot:search>

        <x-forms.select auto name="filter[status]" data-ak-filters="{{ json_encode($filterBind) }}"
            class="{{ filled($filters['status'] ?? null) ? $activeClass : '' }}">
            <option value="">Situação</option>
            <option value="documented" @selected(($filters['status'] ?? '') === 'documented')>Com conteúdo</option>
            <option value="empty" @selected(($filters['status'] ?? '') === 'empty')>Sem conteúdo</option>
            <option value="shared" @selected(($filters['status'] ?? '') === 'shared')>Com link público</option>
            <option value="unlinked" @selected(($filters['status'] ?? '') === 'unlinked')>Sem solução vinculada</option>
        </x-forms.select>
    </x-ui.filter-bar>

    <x-notebooks.index :filters="$filters" />
</x-layouts.layout>
