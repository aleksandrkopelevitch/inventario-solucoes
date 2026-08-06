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

    <form id="companies-filter-form" class="mb-3 flex flex-col gap-3">
        <div class="max-w-md">
            <x-forms.field name="search">
                <div class="relative">
                    <x-forms.input type="search" id="companies-search" name="filter[search]" placeholder="Buscar por nome"
                        class="pr-9"
                        :value="$filters['search'] ?? null"
                        data-ak-search-param="filter[search]"
                        data-ak-search="{{ json_encode(['inputId' => 'companies-search', 'url' => route('companies.index')]) }}" />
                    <x-heroicon-o-arrow-path data-ak-filters-loading
                        class="pointer-events-none absolute right-3 top-1/2 hidden size-4 -translate-y-1/2 animate-spin text-accent" />
                </div>
            </x-forms.field>
            <p data-ak-search-hint="companies-search" class="mt-1.5 h-4 text-xs text-hot"></p>
        </div>

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <x-forms.select name="filter[kind]" data-ak-filters="{{ json_encode($filterBind) }}"
                class="{{ filled($filters['kind'] ?? null) ? $activeClass : '' }}">
                <option value="">Tipo</option>
                @foreach ($kinds as $case)
                    <option value="{{ $case->value }}" @selected(($filters['kind'] ?? '') === $case->value)>{{ $case->label() }}</option>
                @endforeach
            </x-forms.select>
        </div>
    </form>

    <div class="mb-5">
        <x-companies.filter-chips :filters="$filters" />
    </div>

    <div data-ak-filters-dim class="transition-opacity">
        <x-companies.index :filters="$filters" />
    </div>
</x-layouts.layout>
