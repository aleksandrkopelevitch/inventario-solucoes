@php
    // Universal form: a plain Blade echo inside a double-quoted attribute.
    // `@json()` never compiles inside an x-component tag's attribute, and the
    // `:attr=` form never compiles on a plain tag — see AGENTS.md.
    $filterBind = ['formId' => 'submissions-filter-form', 'url' => route('submissions.index')];
    $activeClass = 'border-accent text-accent';
@endphp

<x-layouts.layout title="Comitê de Arquitetura">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4 animate-ak-fade">
        <div>
            <h1 class="font-display text-2xl font-bold text-ink">Comitê de Arquitetura</h1>
            <p class="mt-1 text-sm text-muted">
                Prepare a submissão uma vez: o chamado, o documento e (em breve) o deck saem daqui.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <x-submissions.results-count :filters="$filters" />
            @can('create', App\Models\Submission::class)
                <x-forms.button type="button" data-ak-panel-open
                    data-ak-panel-url="{{ route('submissions.create', ['filter' => $filters]) }}">
                    <x-heroicon-o-plus class="size-4" /> Nova submissão
                </x-forms.button>
            @endcan
        </div>
    </div>

    <x-ui.filter-bar form-id="submissions-filter-form">
        <x-slot:search>
            <x-ui.filter-search id="submissions-search" :url="route('submissions.index')"
                placeholder="Buscar por nome, solução ou chamado"
                :value="$filters['search'] ?? null" />
        </x-slot:search>

        <x-forms.select auto name="filter[status]" data-ak-filters="{{ json_encode($filterBind) }}"
            class="{{ filled($filters['status'] ?? null) ? $activeClass : '' }}">
            <option value="">Situação</option>
            @foreach (App\Enums\SubmissionStatus::options() as $option)
                <option value="{{ $option['value'] }}" @selected(($filters['status'] ?? '') === $option['value'])>{{ $option['label'] }}</option>
            @endforeach
        </x-forms.select>

        <x-forms.select auto name="filter[solution]" data-ak-filters="{{ json_encode($filterBind) }}"
            class="{{ filled($filters['solution'] ?? null) ? $activeClass : '' }}">
            <option value="">Solução</option>
            @foreach ($solutions as $solution)
                <option value="{{ $solution->slug }}" @selected(($filters['solution'] ?? '') === $solution->slug)>{{ $solution->name }}</option>
            @endforeach
        </x-forms.select>
    </x-ui.filter-bar>

    <div data-ak-filters-dim class="transition-opacity">
        <x-submissions.index :filters="$filters" />
    </div>
</x-layouts.layout>
