<x-layouts.layout :title="$solution->name">
    <div class="mb-6 animate-ak-fade">
        <a href="{{ route('solutions.index') }}" class="group inline-flex items-center gap-1 text-sm text-accent hover:underline">
            <x-heroicon-o-arrow-left class="size-4 transition-transform duration-150 group-hover:-translate-x-0.5" /> Soluções
        </a>
    </div>

    {{-- 1/2. Header + "Operation" block (Solutions\DetailHeader, updatable) --}}
    <div class="animate-ak-rise">
        <x-solutions.detail-header :solution="$solution" />
    </div>

    {{-- 3. Integrations — a plain nav list (name, chain summary, status).
         Each row links straight to that integration's own unified page
         (Documentação/Diagrama tabs, `Solutions\IntegrationWorkspace`) —
         the graphical chain canvas no longer lives inline here, it's
         authored on that page instead. --}}
    <div class="animate-ak-rise" style="animation-delay: 90ms">
        <x-solutions.integrations-map :solution="$solution" />
    </div>

    {{-- 4. Solution's rich documentation (block editor, read-only here).
         Documentation coverage (former F7 block) became the "..." menu in the
         header of the /documentacao sub-page — it no longer lives here. --}}
    <div class="animate-ak-rise" style="animation-delay: 160ms">
        <x-solutions.documentation :solution="$solution" />
    </div>
</x-layouts.layout>
