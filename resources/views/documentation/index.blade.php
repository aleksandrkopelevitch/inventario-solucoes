@php
    $filterBind = ['formId' => 'documentation-filter-form', 'url' => route('documentation.index'), 'event' => 'change'];
    $activeClass = '!border-accent !bg-accent-soft !text-accent !font-semibold';
@endphp

<x-layouts.layout title="Documentação">
    <x-ui.hero-panel compact class="mb-6">
        <span class="flex items-center gap-2 font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-[color:var(--color-glow-ink)]/70">
            <span class="size-2 rounded-full" style="background: linear-gradient(115deg, var(--color-glow-a), var(--color-lime))"></span>
            Governança
        </span>
        <h1 class="mt-2 font-display text-[34px] font-bold leading-tight tracking-tight text-[color:var(--color-glow-ink)]">Documentação</h1>
        <p class="mt-1 text-sm text-[color:var(--color-glow-ink)]/70">O que está documentado e o que ainda precisa — soluções e integrações do inventário.</p>
    </x-ui.hero-panel>

    {{-- Global coverage counters (whole inventory; don't change with the
         filter on the list below). --}}
    <div class="grid gap-3.5 sm:grid-cols-2">
        @foreach ([
            'Soluções' => $counters['solutions'],
            'Integrações' => $counters['integrations'],
        ] as $label => $counter)
            <div class="rounded-card border border-line bg-surface p-5 shadow-card">
                <div class="flex items-baseline justify-between gap-2">
                    <div class="text-xs uppercase tracking-wide text-faint">{{ $label }} documentadas</div>
                    <div class="text-sm text-muted">{{ $counter['percent'] }}%</div>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="font-display text-[32px] font-semibold text-ink">{{ $counter['documented'] }}</span>
                    <span class="text-sm text-muted">de {{ $counter['total'] }}</span>
                </div>
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-raised">
                    <div class="h-full rounded-full bg-accent" style="width: {{ $counter['percent'] }}%"></div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Same bar as /solutions, /companies and /people (x-ui.filter-bar).
         This page has no active-filter chips component of its own, so the bar
         has no footer — the controls row is the whole bar. --}}
    <x-ui.filter-bar form-id="documentation-filter-form" class="mt-6">
        <x-slot:search>
            <x-ui.filter-search id="documentation-search" :url="route('documentation.index')"
                placeholder="Buscar por solução ou integração"
                :value="$filters['search'] ?? null" />
        </x-slot:search>

        <x-forms.select auto name="filter[type]" data-ak-filters="{{ json_encode($filterBind) }}"
            class="{{ filled($filters['type'] ?? null) ? $activeClass : '' }}">
            <option value="">Tudo</option>
            <option value="solutions" @selected(($filters['type'] ?? '') === 'solutions')>Só soluções</option>
            <option value="integrations" @selected(($filters['type'] ?? '') === 'integrations')>Só integrações</option>
        </x-forms.select>

        <x-forms.select auto name="filter[status]" data-ak-filters="{{ json_encode($filterBind) }}"
            class="{{ filled($filters['status'] ?? null) ? $activeClass : '' }}">
            <option value="">Qualquer status</option>
            <option value="documented" @selected(($filters['status'] ?? '') === 'documented')>Documentado</option>
            <option value="pending" @selected(($filters['status'] ?? '') === 'pending')>Pendente</option>
        </x-forms.select>
    </x-ui.filter-bar>

    <div data-ak-filters-dim class="transition-opacity">
        <x-documentation.hub :filters="$filters" />
    </div>

    <div class="mt-6">
        <x-documentation.groups-list />
    </div>
</x-layouts.layout>
