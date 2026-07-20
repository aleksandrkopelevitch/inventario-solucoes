@php
    $filterBind = ['formId' => 'people-filter-form', 'url' => route('people.index'), 'event' => 'change'];
    $activeClass = '!border-accent !bg-accent-soft !text-accent !font-semibold';
@endphp

<x-layouts.layout title="Pessoas">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="font-display text-[32px] font-semibold leading-tight text-ink">
                Pessoas
                <x-people.results-count :filters="$filters" />
            </h1>
            <p class="mt-1 text-sm text-muted">Responsáveis internos e contatos de fornecedores.</p>
        </div>
        @can('create', \App\Models\Person::class)
            <x-forms.button href="#" data-ak-panel-open data-ak-panel-url="{{ route('people.create', ['filter' => $filters]) }}">
                <x-heroicon-o-plus class="size-4" /> Nova pessoa
            </x-forms.button>
        @endcan
    </div>

    <form id="people-filter-form" class="mb-3 flex flex-col gap-3">
        <div class="max-w-md">
            <x-forms.field name="search">
                <div class="relative">
                    <x-forms.input type="search" id="people-search" name="filter[search]" placeholder="Buscar por nome, empresa ou sistema"
                        class="pr-9"
                        :value="$filters['search'] ?? null"
                        data-ak-search-param="filter[search]"
                        data-ak-search="{{ json_encode(['inputId' => 'people-search', 'url' => route('people.index')]) }}" />
                    <x-heroicon-o-arrow-path data-ak-filters-loading
                        class="pointer-events-none absolute right-3 top-1/2 hidden size-4 -translate-y-1/2 animate-spin text-accent" />
                </div>
            </x-forms.field>
            <p data-ak-search-hint="people-search" class="mt-1.5 h-4 text-xs text-hot"></p>
        </div>

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <x-forms.select name="filter[company]" data-ak-filters="{{ json_encode($filterBind) }}"
                class="{{ filled($filters['company'] ?? null) ? $activeClass : '' }}">
                <option value="">Empresa</option>
                @foreach ($companies as $company)
                    <option value="{{ $company->id }}" @selected((string) ($filters['company'] ?? '') === (string) $company->id)>{{ $company->name }}</option>
                @endforeach
            </x-forms.select>

            <x-forms.select name="filter[role]" data-ak-filters="{{ json_encode($filterBind) }}"
                class="{{ filled($filters['role'] ?? null) ? $activeClass : '' }}">
                <option value="">Papel</option>
                @foreach ($roles as $case)
                    <option value="{{ $case->value }}" @selected(($filters['role'] ?? '') === $case->value)>{{ $case->label() }}</option>
                @endforeach
            </x-forms.select>
        </div>
    </form>

    <div class="mb-5">
        <x-people.filter-chips :filters="$filters" />
    </div>

    <div data-ak-filters-dim class="transition-opacity">
        <x-people.index :filters="$filters" />
    </div>
</x-layouts.layout>
