@php
    $filterBind = ['formId' => 'diagrams-filter-form', 'url' => route('diagrams.index'), 'event' => 'change'];
    $activeClass = '!border-accent !bg-accent-soft !text-accent !font-semibold';
@endphp

<x-layouts.layout title="Diagramas">
    <x-ui.hero-panel compact class="mb-6">
        <span class="flex items-center gap-2 font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-[color:var(--color-glow-ink)]/70">
            <span class="size-2 rounded-full" style="background: linear-gradient(115deg, var(--color-glow-a), var(--color-lime))"></span>
            Governança
        </span>
        <h1 class="mt-2 font-display text-[34px] font-bold leading-tight tracking-tight text-[color:var(--color-glow-ink)]">Diagramas</h1>
        <p class="mt-1 text-sm text-[color:var(--color-glow-ink)]/70">Os fluxos desenhados do ecossistema. Cada diagrama pode ser vinculado a uma ou mais páginas de documentação.</p>
    </x-ui.hero-panel>

    {{-- The two things that can be missing from a diagram, counted separately
         because they go missing independently — see DiagramCatalogService.
         Whole-inventory numbers: they don't follow the filter below. --}}
    <div class="grid gap-3.5 sm:grid-cols-2">
        @foreach ([
            'Desenhados' => ['counter' => $counters['drawn'], 'hint' => 'com mais de um bloco no canvas'],
            'No catálogo' => ['counter' => $counters['placed'], 'hint' => 'que citam ao menos uma solução do catálogo'],
        ] as $label => $card)
            <div class="rounded-card border border-line bg-surface p-5 shadow-card">
                <div class="flex items-baseline justify-between gap-2">
                    <div class="text-xs uppercase tracking-wide text-faint">{{ $label }}</div>
                    <div class="text-sm text-muted">{{ $card['counter']['percent'] }}%</div>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="font-display text-[32px] font-semibold text-ink">{{ $card['counter']['value'] }}</span>
                    <span class="text-sm text-muted">de {{ $card['counter']['total'] }}</span>
                </div>
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-raised">
                    <div class="h-full rounded-full bg-accent" style="width: {{ $card['counter']['percent'] }}%"></div>
                </div>
                <p class="mt-2 text-xs text-faint">{{ $card['hint'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- Same bar as /solutions, /companies, /people and /documentation
         (x-ui.filter-bar). No chips row, so the bar has no footer. --}}
    <x-ui.filter-bar form-id="diagrams-filter-form" class="mt-6">
        <x-slot:search>
            <x-ui.filter-search id="diagrams-search" :url="route('diagrams.index')"
                placeholder="Buscar diagrama por nome"
                :value="$filters['search'] ?? null" />
        </x-slot:search>

        <x-forms.select auto name="filter[status]" data-ak-filters="{{ json_encode($filterBind) }}"
            class="{{ filled($filters['status'] ?? null) ? $activeClass : '' }}">
            <option value="">Qualquer status</option>
            @foreach ($statusOptions as $option)
                <option value="{{ $option['value'] }}" @selected(($filters['status'] ?? '') === $option['value'])>{{ $option['label'] }}</option>
            @endforeach
        </x-forms.select>

        <x-forms.select auto name="filter[placed]" data-ak-filters="{{ json_encode($filterBind) }}"
            class="{{ filled($filters['placed'] ?? null) ? $activeClass : '' }}">
            <option value="">No catálogo ou não</option>
            <option value="yes" @selected(($filters['placed'] ?? '') === 'yes')>Com solução</option>
            <option value="no" @selected(($filters['placed'] ?? '') === 'no')>Sem solução</option>
        </x-forms.select>

    </x-ui.filter-bar>

    @can('create', App\Models\Diagram::class)
        {{-- Its own form, deliberately OUTSIDE x-ui.filter-bar: that component
             IS a <form>, and a form nested in another is dropped by the HTML
             parser — `getElementById` would find it and `new FormData(null)`
             would throw (the same trap the CATI composer hit). No solution
             context here either (that gesture lives on a solution's own detail
             card), so the root block starts as free text named after the
             diagram. --}}
        <form id="diagram-create-form" class="mb-4 flex items-center gap-2 rounded-card border border-line bg-surface p-2.5 shadow-card">
            @csrf
            <x-forms.input type="text" name="name" placeholder="Nome do novo diagrama"
                class="!h-9 min-w-0 flex-1 !text-sm" />
            <x-forms.button data-ak-ajax="diagram-create-form"
                data-ak-action="{{ route('diagrams.store') }}"
                class="!h-9 !shrink-0 !px-3 !text-xs">
                <x-heroicon-o-plus class="size-4" /> Novo diagrama
            </x-forms.button>
        </form>
    @endcan

    <div data-ak-filters-dim class="transition-opacity">
        <x-diagrams.index :filters="$filters" />
    </div>
</x-layouts.layout>
