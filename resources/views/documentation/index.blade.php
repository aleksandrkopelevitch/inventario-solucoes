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
        <p class="mt-1 text-sm text-[color:var(--color-glow-ink)]/70">O que está documentado e o que ainda falta. Os cadernos são escritos em <a href="{{ route('notebooks.index') }}" class="font-medium underline">Cadernos</a>.</p>
    </x-ui.hero-panel>

    {{-- Global coverage counters (whole inventory; don't change with the
         filter on the list below). --}}
    <div class="grid gap-3.5 sm:grid-cols-3">
        @foreach ([
            'Soluções' => $counters['solutions'],
            'Cadernos' => $counters['notebooks'],
            'Páginas' => $counters['pages'],
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
                placeholder="Buscar por caderno, página ou solução"
                :value="$filters['search'] ?? null" />
        </x-slot:search>

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

    {{-- The gap the hub exists to show, and the half the caderno listing above
         structurally cannot: a solution nobody has documented has no caderno to
         appear under. `notebookCount` distinguishes the two jobs — nothing
         linked at all, versus a caderno linked but still empty. --}}
    @if ($gaps->isNotEmpty())
        <div class="mt-6 rounded-card border border-line bg-surface p-5 shadow-card">
            <h2 class="font-display text-lg font-semibold text-ink">Soluções sem documentação</h2>
            <p class="mt-0.5 text-sm text-muted">
                {{ $gaps->count() }} {{ $gaps->count() === 1 ? 'solução ainda não tem' : 'soluções ainda não têm' }}
                nenhum caderno com conteúdo.
            </p>

            <div class="mt-4 flex flex-wrap gap-1.5">
                @foreach ($gaps as $gap)
                    <a href="{{ $gap['url'] }}"
                        class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-line bg-surface px-2.5 py-1 text-xs font-medium text-ink no-underline transition-colors hover:border-accent-line hover:bg-accent-soft/40">
                        <span class="truncate">{{ $gap['name'] }}</span>
                        @if ($gap['notebookCount'] > 0)
                            <span class="shrink-0 text-faint" title="Tem caderno vinculado, mas sem conteúdo">
                                <x-heroicon-o-book-open class="size-3.5" />
                            </span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</x-layouts.layout>
