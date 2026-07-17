@php
    $filterBind = ['formId' => 'solutions-filter-form', 'url' => route('solutions.index'), 'event' => 'change'];
    $activeClass = '!border-accent !bg-accent-soft !text-accent !font-semibold';
@endphp

<x-layouts.layout title="Soluções">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="font-display text-[32px] font-semibold leading-tight text-ink">
                Soluções
                <x-solutions.results-count :filters="$filters" />
            </h1>
            <p class="mt-1 text-sm text-muted">Catálogo das soluções de software da Leo Madeiras.</p>
        </div>
        @can('create', \App\Models\Solution::class)
            <a href="#" data-ak-panel-open data-ak-panel-url="{{ route('solutions.create', ['filter' => $filters]) }}"
               class="inline-flex items-center gap-2 rounded-field bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-press">
                <x-heroicon-o-plus class="size-4" /> Nova solução
            </a>
        @endcan
    </div>

    {{-- Search + filters (same form: search is a filter[] field preserved when filtering) --}}
    <form id="solutions-filter-form" class="mb-3 flex flex-col gap-3">
        <div class="max-w-md">
            <x-forms.field name="search">
                <div class="relative">
                    <x-forms.input type="search" id="solutions-search" name="filter[search]" placeholder="Buscar por nome, fornecedor ou responsável"
                        class="pr-9"
                        :value="$filters['search'] ?? null"
                        data-ak-search-param="filter[search]"
                        data-ak-search="{{ json_encode(['inputId' => 'solutions-search', 'url' => route('solutions.index')]) }}" />
                    <x-heroicon-o-arrow-path data-ak-filters-loading
                        class="pointer-events-none absolute right-3 top-1/2 hidden size-4 -translate-y-1/2 animate-spin text-accent" />
                </div>
            </x-forms.field>
            <p data-ak-search-hint="solutions-search" class="mt-1.5 h-4 text-xs text-hot"></p>
        </div>

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
            <x-forms.select name="filter[category]" data-ak-filters="{{ json_encode($filterBind) }}"
                class="{{ filled($filters['category'] ?? null) ? $activeClass : '' }}">
                <option value="">Categoria</option>
                @foreach ($categories as $option)
                    <option value="{{ $option->value }}" @selected(($filters['category'] ?? '') === $option->value)>{{ $option->label }}</option>
                @endforeach
            </x-forms.select>

            <x-forms.select name="filter[directorate]" data-ak-filters="{{ json_encode($filterBind) }}"
                class="{{ filled($filters['directorate'] ?? null) ? $activeClass : '' }}">
                <option value="">Diretoria</option>
                @foreach ($directorates as $option)
                    <option value="{{ $option->value }}" @selected(($filters['directorate'] ?? '') === $option->value)>{{ $option->label }}</option>
                @endforeach
            </x-forms.select>

            <x-forms.select name="filter[environment]" data-ak-filters="{{ json_encode($filterBind) }}"
                class="{{ filled($filters['environment'] ?? null) ? $activeClass : '' }}">
                <option value="">Hospedagem</option>
                @foreach ($environments as $option)
                    <option value="{{ $option->value }}" @selected(($filters['environment'] ?? '') === $option->value)>{{ $option->label }}</option>
                @endforeach
            </x-forms.select>

            <x-forms.select name="filter[contract]" data-ak-filters="{{ json_encode($filterBind) }}"
                class="{{ filled($filters['contract'] ?? null) ? $activeClass : '' }}">
                <option value="">Contrato</option>
                @foreach ($contractStatuses as $option)
                    <option value="{{ $option->value }}" @selected(($filters['contract'] ?? '') === $option->value)>{{ $option->label }}</option>
                @endforeach
            </x-forms.select>

            <x-forms.select name="filter[status]" data-ak-filters="{{ json_encode($filterBind) }}"
                class="{{ filled($filters['status'] ?? null) ? $activeClass : '' }}">
                <option value="">Status</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}" @selected(($filters['status'] ?? '') === $option->value)>{{ $option->label }}</option>
                @endforeach
            </x-forms.select>

            <x-forms.select name="filter[sort]" data-ak-filters="{{ json_encode($filterBind) }}">
                <option value="name" @selected(($filters['sort'] ?? 'name') === 'name')>Ordenar: Nome (A–Z)</option>
                <option value="status" @selected(($filters['sort'] ?? 'name') === 'status')>Ordenar: Status</option>
            </x-forms.select>

            <x-forms.label data-ak-filters="{{ json_encode($filterBind) }}"
                class="col-span-2 !flex cursor-pointer items-center gap-2 rounded-field border border-line-2 bg-surface px-3 py-2 !text-[13.5px] !text-ink transition-colors has-checked:border-accent has-checked:bg-accent-soft has-checked:text-accent has-checked:font-semibold sm:col-span-3 lg:col-span-6">
                <x-forms.checkbox name="filter[undocumented]" :checked="(bool) ($filters['undocumented'] ?? false)" />
                Somente sem documentação
            </x-forms.label>
        </div>
    </form>

    <div class="mb-5">
        <x-solutions.filter-chips :filters="$filters" />
    </div>

    <div data-ak-filters-dim class="transition-opacity">
        <x-solutions.index :filters="$filters" />
    </div>
</x-layouts.layout>
