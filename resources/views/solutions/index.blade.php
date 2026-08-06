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
        <span class="ml-auto">{{ $catalogStats['integrations'] }} integrações mapeadas</span>
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

        {{-- `filter[sort]` moved from this grid to a hidden field (below):
             sorting is now driven by clicking the table's column headers
             (`x-solutions.sortable-th`), which toggle this same field and
             re-fire the AJAX filter pipeline (`sortable-table.js`) instead of
             a standalone dropdown. --}}
        <input type="hidden" name="filter[sort]" value="{{ $filters['sort'] ?? 'name' }}">

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
            <x-forms.select name="filter[category]" data-ak-filters="{{ json_encode($filterBind) }}"
                class="{{ $categoryActive }}">
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

            <x-forms.label data-ak-filters="{{ json_encode($filterBind) }}"
                class="col-span-2 !flex cursor-pointer items-center gap-2 rounded-field border border-line-2 bg-surface px-3 py-2 !text-[13.5px] !text-ink transition-colors has-checked:border-accent has-checked:bg-accent-soft has-checked:text-accent has-checked:font-semibold sm:col-span-3 lg:col-span-5">
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
