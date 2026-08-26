@use('App\Support\CategoryPalette')
@php
    $filterBind = ['formId' => 'solutions-filter-form', 'url' => route('solutions.index'), 'event' => 'change'];
    $activeClass = '!border-accent !bg-accent-soft !text-accent !font-semibold';
    // Active category filter wears its family color (matches its chip + cards);
    // the other active filters keep the plain green highlight.
    $categoryActive = filled($filters['category'] ?? null)
        ? '!font-semibold '.CategoryPalette::selectActiveClass($filters['category'])
        : '';
    // Small semantic dot per status value for the hero stat-strip — not tied
    // to CategoryPalette (that's for the `category` attribute, a different
    // dimension) nor to `--color-accent` (kept for badges/links elsewhere).
    $statusDot = [
        'active'     => 'bg-lime',
        'evaluating' => 'bg-hot',
        'planned'    => 'bg-line-2',
        'deprecated' => 'bg-crit',
    ];
@endphp

<x-layouts.layout title="Soluções">
    {{-- The redesign's one gradient "glow" signature (see [[radiant-protocol-redesign]])
         replaces the old lime radial wash — same spot, same purpose (a
         deliberate moment of color behind the page title), Radiant's actual
         hero gradient instead of a green-tinted one. --}}
    <x-ui.hero-panel class="mb-6">
        <span class="flex items-center gap-2 font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-[color:var(--color-glow-ink)]/70">
            <span class="size-2 rounded-full" style="background: linear-gradient(115deg, var(--color-glow-a), var(--color-lime))"></span>
            Catálogo · {{ $catalogStats['total'] }} soluções
        </span>
        <h1 class="mt-3 font-display text-[44px] font-bold leading-[0.98] tracking-tight text-[color:var(--color-glow-ink)]">
            Soluções
            <x-solutions.results-count :filters="$filters" />
        </h1>
        <p class="mt-3 max-w-lg text-[15px] leading-relaxed text-[color:var(--color-glow-ink)]/70">
            Do chão de fábrica ao e-commerce — todo o ecossistema de software da Leo Madeiras num só lugar.
        </p>
        <div class="mt-5 flex flex-wrap items-center gap-3">
            @can('create', \App\Models\Solution::class)
                <x-forms.button href="#" data-ak-panel-open data-ak-panel-url="{{ route('solutions.create', ['filter' => $filters]) }}"
                    class="!rounded-full">
                    <x-heroicon-o-plus class="size-4" /> Nova solução
                </x-forms.button>
            @endcan
            <x-forms.button href="{{ route('solutions.map') }}" variant="glass" class="!rounded-full">
                Ver mapa do ecossistema <x-heroicon-o-arrow-right class="size-4" />
            </x-forms.button>
        </div>
    </x-ui.hero-panel>

    {{-- Whole-catalog summary — unfiltered on purpose, doesn't move with the
         grid below (same "global counters" convention as the Documentação hub). --}}
    <div class="mb-6 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-card border border-line bg-surface px-5 py-3.5 text-sm text-muted shadow-card">
        @foreach ($catalogStats['byStatus'] as $row)
            <span class="flex items-center gap-1.5">
                <span class="size-1.5 rounded-full {{ $statusDot[$row['value']] ?? 'bg-line-2' }}"></span>
                <span class="font-display font-semibold text-ink">{{ $row['count'] }}</span> {{ mb_strtolower($row['label']) }}
            </span>
        @endforeach
        <span class="ml-auto">{{ $catalogStats['diagrams'] }} diagramas desenhados</span>
    </div>

    {{-- Search + filters, one bar (same form: search is a filter[] field, so it
         survives every filter change). Seven controls, so this is the page
         where the bar wraps onto a second line — see x-ui.filter-bar. --}}
    <x-ui.filter-bar form-id="solutions-filter-form">
        <x-slot:search>
            <x-ui.filter-search id="solutions-search" :url="route('solutions.index')"
                placeholder="Buscar por nome, fornecedor ou responsável"
                :value="$filters['search'] ?? null" />
        </x-slot:search>

        {{-- `filter[sort]` has no control of its own: sorting is driven by
             clicking the table's column headers (`x-solutions.sortable-th`),
             which toggle this same field and re-fire the AJAX filter pipeline
             (`sortable-table.js`). It only needs to sit inside this form for
             `executeFilters()` to serialize it. `type="hidden"` inputs aren't
             rendered at all, so it costs the flex row nothing. --}}
        <input type="hidden" name="filter[sort]" value="{{ $filters['sort'] ?? 'name' }}">

        <x-forms.select auto name="filter[category]" data-ak-filters="{{ json_encode($filterBind) }}"
            class="{{ $categoryActive }}">
            <option value="">Categoria</option>
            @foreach ($categories as $option)
                <option value="{{ $option->value }}" @selected(($filters['category'] ?? '') === $option->value)>{{ $option->label }}</option>
            @endforeach
        </x-forms.select>

        <x-forms.select auto name="filter[directorate]" data-ak-filters="{{ json_encode($filterBind) }}"
            class="{{ filled($filters['directorate'] ?? null) ? $activeClass : '' }}">
            <option value="">Diretoria</option>
            @foreach ($directorates as $option)
                <option value="{{ $option->value }}" @selected(($filters['directorate'] ?? '') === $option->value)>{{ $option->label }}</option>
            @endforeach
        </x-forms.select>

        <x-forms.select auto name="filter[environment]" data-ak-filters="{{ json_encode($filterBind) }}"
            class="{{ filled($filters['environment'] ?? null) ? $activeClass : '' }}">
            <option value="">Hospedagem</option>
            @foreach ($environments as $option)
                <option value="{{ $option->value }}" @selected(($filters['environment'] ?? '') === $option->value)>{{ $option->label }}</option>
            @endforeach
        </x-forms.select>

        <x-forms.select auto name="filter[contract]" data-ak-filters="{{ json_encode($filterBind) }}"
            class="{{ filled($filters['contract'] ?? null) ? $activeClass : '' }}">
            <option value="">Contrato</option>
            @foreach ($contractStatuses as $option)
                <option value="{{ $option->value }}" @selected(($filters['contract'] ?? '') === $option->value)>{{ $option->label }}</option>
            @endforeach
        </x-forms.select>

        <x-forms.select auto name="filter[status]" data-ak-filters="{{ json_encode($filterBind) }}"
            class="{{ filled($filters['status'] ?? null) ? $activeClass : '' }}">
            <option value="">Status</option>
            @foreach ($statuses as $option)
                <option value="{{ $option->value }}" @selected(($filters['status'] ?? '') === $option->value)>{{ $option->label }}</option>
            @endforeach
        </x-forms.select>

        {{-- A boolean is a PILL, not a field: it hugs its label instead of
             stretching a 990px border around 234px of content (76% air), and
             the round shape says what the control does — rectangle = pick a
             value, pill = on/off. Same radius as the active-filter chips in
             the footer below, so switching it on and seeing its chip appear
             reads as one gesture.
             `!leading-5` matches the 38px height of the selects beside it
             (x-forms.label's own `leading-6` would make it 42px), and
             `has-checked:!text-accent` needs the `!` to beat `!text-ink` —
             without it the checked state never actually recoloured the text. --}}
        <x-forms.label data-ak-filters="{{ json_encode($filterBind) }}"
            class="!inline-flex shrink-0 cursor-pointer items-center gap-2 rounded-full border border-line-2 bg-surface px-3.5 py-2 !text-[13.5px] !leading-5 !text-ink transition-colors has-checked:border-accent has-checked:bg-accent-soft has-checked:font-semibold has-checked:!text-accent">
            <x-forms.checkbox name="filter[undocumented]" :checked="(bool) ($filters['undocumented'] ?? false)" />
            Sem documentação
        </x-forms.label>

        <x-slot:footer>
            <x-solutions.filter-chips :filters="$filters" />
        </x-slot:footer>
    </x-ui.filter-bar>

    <div data-ak-filters-dim class="transition-opacity">
        <x-solutions.index :filters="$filters" />
    </div>
</x-layouts.layout>
