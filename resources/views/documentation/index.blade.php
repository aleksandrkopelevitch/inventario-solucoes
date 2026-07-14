@php
    $filterBind = ['formId' => 'documentation-filter-form', 'url' => route('documentation.index'), 'event' => 'change'];
    $activeClass = '!border-accent !bg-accent-soft !text-accent !font-semibold';
@endphp

<x-layouts.layout title="Documentação">
    <div class="mb-6">
        <h1 class="font-display text-[32px] font-semibold leading-tight text-ink">Documentação</h1>
        <p class="mt-1 text-sm text-muted">O que está documentado e o que ainda precisa — soluções e integrações do inventário.</p>
    </div>

    {{-- Contadores globais de cobertura (inventário inteiro; não mudam com o
         filtro da lista abaixo). --}}
    <div class="grid gap-3.5 sm:grid-cols-2">
        @foreach ([
            'Soluções' => $counters['solutions'],
            'Integrações' => $counters['integrations'],
        ] as $label => $counter)
            <div class="rounded-card border border-line bg-surface p-5 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
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

    {{-- Busca + filtros (mesmo form: a busca é um campo filter[] preservado ao filtrar) --}}
    <form id="documentation-filter-form" class="mb-3 mt-6 flex flex-col gap-3">
        <div class="max-w-md">
            <x-forms.field name="search">
                <div class="relative">
                    <x-forms.input type="search" id="documentation-search" name="filter[search]" placeholder="Buscar por solução ou integração"
                        class="pr-9"
                        :value="$filters['search'] ?? null"
                        data-ak-search-param="filter[search]"
                        data-ak-search="{{ json_encode(['inputId' => 'documentation-search', 'url' => route('documentation.index')]) }}" />
                    <x-heroicon-o-arrow-path data-ak-filters-loading
                        class="pointer-events-none absolute right-3 top-1/2 hidden size-4 -translate-y-1/2 animate-spin text-accent" />
                </div>
            </x-forms.field>
            <p data-ak-search-hint="documentation-search" class="mt-1.5 h-4 text-xs text-hot"></p>
        </div>

        <div class="grid grid-cols-2 gap-2 sm:max-w-md">
            <x-forms.select name="filter[type]" data-ak-filters="{{ json_encode($filterBind) }}"
                class="{{ filled($filters['type'] ?? null) ? $activeClass : '' }}">
                <option value="">Tudo</option>
                <option value="solutions" @selected(($filters['type'] ?? '') === 'solutions')>Só soluções</option>
                <option value="integrations" @selected(($filters['type'] ?? '') === 'integrations')>Só integrações</option>
            </x-forms.select>

            <x-forms.select name="filter[status]" data-ak-filters="{{ json_encode($filterBind) }}"
                class="{{ filled($filters['status'] ?? null) ? $activeClass : '' }}">
                <option value="">Qualquer status</option>
                <option value="documented" @selected(($filters['status'] ?? '') === 'documented')>Documentado</option>
                <option value="pending" @selected(($filters['status'] ?? '') === 'pending')>Pendente</option>
            </x-forms.select>
        </div>
    </form>

    <div data-ak-filters-dim class="transition-opacity">
        <x-documentation.hub :filters="$filters" />
    </div>

    <div class="mt-6">
        <x-documentation.groups-list />
    </div>
</x-layouts.layout>
