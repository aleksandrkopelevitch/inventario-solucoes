@php
    $filterBind = ['formId' => 'companies-filter-form', 'url' => route('companies.index'), 'event' => 'change'];
    $activeClass = '!border-accent !bg-accent-soft !text-accent !font-semibold';
@endphp

<x-layouts.layout title="Empresas">
    <x-ui.hero-panel compact class="mb-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <span class="flex items-center gap-2 font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-[color:var(--color-glow-ink)]/70">
                    <span class="size-2 rounded-full" style="background: linear-gradient(115deg, var(--color-glow-a), var(--color-lime))"></span>
                    Diretório
                </span>
                <h1 class="mt-2 font-display text-[34px] font-bold leading-tight tracking-tight text-[color:var(--color-glow-ink)]">
                    Empresas
                    <x-companies.results-count :filters="$filters" />
                </h1>
                <p class="mt-1 text-sm text-[color:var(--color-glow-ink)]/70">Fornecedores, parceiros e áreas internas.</p>
            </div>
            @can('create', \App\Models\Company::class)
                <x-forms.button href="#" data-ak-panel-open data-ak-panel-url="{{ route('companies.create', ['filter' => $filters]) }}"
                    class="!rounded-full">
                    <x-heroicon-o-plus class="size-4" /> Nova empresa
                </x-forms.button>
            @endcan
        </div>
    </x-ui.hero-panel>

    {{-- One filter, so the bar is a single line — the old `sm:grid-cols-4`
         reserved a whole band and left 3/4 of it empty. See x-ui.filter-bar. --}}
    <x-ui.filter-bar form-id="companies-filter-form">
        <x-slot:search>
            <x-ui.filter-search id="companies-search" :url="route('companies.index')"
                placeholder="Buscar por nome"
                :value="$filters['search'] ?? null" />
        </x-slot:search>

        <x-forms.select auto name="filter[kind]" data-ak-filters="{{ json_encode($filterBind) }}"
            class="{{ filled($filters['kind'] ?? null) ? $activeClass : '' }}">
            <option value="">Tipo</option>
            @foreach ($kinds as $case)
                <option value="{{ $case->value }}" @selected(($filters['kind'] ?? '') === $case->value)>{{ $case->label() }}</option>
            @endforeach
        </x-forms.select>

        <x-slot:footer>
            <x-companies.filter-chips :filters="$filters" />
        </x-slot:footer>
    </x-ui.filter-bar>

    <div data-ak-filters-dim class="transition-opacity">
        <x-companies.index :filters="$filters" />
    </div>
</x-layouts.layout>
