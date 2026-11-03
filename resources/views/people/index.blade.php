@php
    $filterBind = ['formId' => 'people-filter-form', 'url' => route('people.index'), 'event' => 'change'];
    $activeClass = '!border-accent !bg-accent-soft !text-accent !font-semibold';
@endphp

<x-layouts.layout title="Pessoas">
    <x-ui.hero-panel compact class="mb-6">
        <x-ui.page-header eyebrow="Diretório" title="Pessoas" description="Responsáveis internos e contatos de fornecedores.">
            <x-slot:count><x-people.results-count :filters="$filters" /></x-slot:count>
            <x-slot:actions>
                @can('manage', \App\Models\User::class)
                    {{-- The roster is a view of THIS module, so it is reachable from
                         the module rather than only from the user menu. --}}
                    <x-forms.button href="{{ route('people.accounts') }}" variant="ghost" class="!rounded-full">
                        <x-heroicon-o-key class="size-4" /> Quem tem acesso
                    </x-forms.button>
                @endcan
                @can('create', \App\Models\Person::class)
                    <x-forms.button href="#" data-ak-panel-open data-ak-panel-url="{{ route('people.create', ['filter' => $filters]) }}"
                        class="!rounded-full">
                        <x-heroicon-o-plus class="size-4" /> Nova pessoa
                    </x-forms.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-ui.hero-panel>

    {{-- Two filters, one line. `filter[company]` is the select the `auto`
         prop's `max-w-52` exists for: it lists every company, so its longest
         option — not the selected one — is what a `<select>` sizes itself to. --}}
    <x-ui.filter-bar form-id="people-filter-form">
        <x-slot:search>
            <x-ui.filter-search id="people-search" :url="route('people.index')"
                placeholder="Buscar por nome, e-mail, telefone, empresa ou sistema"
                :value="$filters['search'] ?? null" />
        </x-slot:search>

        <x-forms.select auto name="filter[company]" data-ak-filters="{{ json_encode($filterBind) }}"
            class="{{ filled($filters['company'] ?? null) ? $activeClass : '' }}">
            <option value="">Empresa</option>
            @foreach ($companies as $company)
                <option value="{{ $company->id }}" @selected((string) ($filters['company'] ?? '') === (string) $company->id)>{{ $company->name }}</option>
            @endforeach
        </x-forms.select>

        <x-forms.select auto name="filter[role]" data-ak-filters="{{ json_encode($filterBind) }}"
            class="{{ filled($filters['role'] ?? null) ? $activeClass : '' }}">
            <option value="">Papel</option>
            @foreach ($roles as $case)
                <option value="{{ $case->value }}" @selected(($filters['role'] ?? '') === $case->value)>{{ $case->label() }}</option>
            @endforeach
        </x-forms.select>

        <x-slot:footer>
            <x-people.filter-chips :filters="$filters" />
        </x-slot:footer>
    </x-ui.filter-bar>

    <div data-ak-filters-dim class="transition-opacity">
        <x-people.index :filters="$filters" />
    </div>
</x-layouts.layout>
