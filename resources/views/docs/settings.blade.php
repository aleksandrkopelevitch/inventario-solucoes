@php
    $filterBind = ['formId' => 'docs-settings-filter-form', 'url' => route('docs.settings'), 'event' => 'change'];
    $activeClass = '!border-accent !bg-accent-soft !text-accent !font-semibold';
@endphp

<x-layouts.layout title="Base de conhecimento">
    <x-ui.hero-panel class="mb-6">
        <span class="flex items-center gap-2 font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-[color:var(--color-glow-ink)]/70">
            <span class="size-2 rounded-full" style="background: linear-gradient(115deg, var(--color-glow-a), var(--color-lime))"></span>
            Documentação
        </span>
        <h1 class="mt-3 font-display text-[44px] font-bold leading-[0.98] tracking-tight text-[color:var(--color-glow-ink)]">
            Base de conhecimento
        </h1>
        <p class="mt-3 max-w-xl text-[15px] leading-relaxed text-[color:var(--color-glow-ink)]/70">
            Escolha quais cadernos qualquer pessoa da Leo Madeiras enxerga em
            <span class="font-mono text-[13px]">/docs</span> — basta estar logada com
            a conta corporativa. É diferente do link público, que não pede login
            nenhum e vai para fora da empresa.
        </p>
        <div class="mt-5 flex flex-wrap items-center gap-3">
            <x-forms.button href="{{ route('docs.index') }}" target="_blank" rel="noopener" class="!rounded-full">
                Abrir a base de conhecimento <x-heroicon-o-arrow-top-right-on-square class="size-4" />
            </x-forms.button>
            <x-forms.button href="{{ route('notebooks.index') }}" variant="glass" class="!rounded-full">
                Ir para os cadernos <x-heroicon-o-arrow-right class="size-4" />
            </x-forms.button>
        </div>
    </x-ui.hero-panel>

    {{-- Search + filters, one bar (same form: search is a filter[] field, so it
         survives every filter change). --}}
    <x-ui.filter-bar form-id="docs-settings-filter-form">
        <x-slot:search>
            <x-ui.filter-search id="docs-settings-search" :url="route('docs.settings')"
                placeholder="Buscar por caderno, página ou solução"
                :value="$filters['search'] ?? null" />
        </x-slot:search>

        <x-forms.select auto name="filter[status]" data-ak-filters="{{ json_encode($filterBind) }}"
            class="{{ filled($filters['status'] ?? null) ? $activeClass : '' }}">
            <option value="">Situação</option>
            <option value="published" @selected(($filters['status'] ?? '') === 'published')>Publicados</option>
            <option value="unpublished" @selected(($filters['status'] ?? '') === 'unpublished')>Não publicados</option>
        </x-forms.select>
    </x-ui.filter-bar>

    <x-docs.publication-list :filters="$filters" />
</x-layouts.layout>
