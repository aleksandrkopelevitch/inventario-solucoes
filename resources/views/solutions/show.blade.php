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

    {{-- 3. Integrations (F3) — a single workspace: the list of integrations
         (create/delete, selectable) sits in the left rail, the graphical
         visualization of the selected integration fills the canvas on the
         right. The canvas also authors blocks/links/protocol and the
         name/status (topbar pencil). Rail and canvas share ONE framed card
         (a divider between them), not two nested bordered boxes. --}}
    <div class="animate-ak-rise mt-5 overflow-hidden rounded-card border border-line bg-surface shadow-card" style="animation-delay: 90ms">
        <div class="p-6 pb-4">
            <div class="flex items-baseline gap-2.5">
                <span class="inline-flex items-center rounded-md bg-accent px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-[0.12em] text-white">F3</span>
                <h2 class="font-display text-[22px] font-semibold text-ink">Integrações</h2>
            </div>
            <p class="mt-1 text-sm text-muted">Selecione uma integração na lista para ver a visualização gráfica.</p>
        </div>

        {{-- One framed body: rail (left) + canvas (right), separated by a
             divider — the tinted rail and the hairline stand in for the old
             nested boxes. --}}
        <div class="flex flex-col border-t border-line lg:flex-row lg:items-stretch">
            {{-- Left rail: the solution's integrations (the updatable slot
                 lives inside this component). --}}
            <div class="w-full shrink-0 border-b border-line bg-canvas p-4 lg:w-2/5 lg:max-w-sm lg:border-b-0 lg:border-r">
                <x-solutions.integrations-map :solution="$solution" />
            </div>

            {{-- Canvas: graphical visualization (pan/zoom + fullscreen), flush inside the frame --}}
            <x-solutions.integration-viz />
        </div>
    </div>

    {{-- 4. Solution's rich documentation (block editor, read-only here).
         Documentation coverage (former F7 block) became the "..." menu in the
         header of the /documentacao sub-page — it no longer lives here. --}}
    <div class="animate-ak-rise" style="animation-delay: 160ms">
        <x-solutions.documentation :solution="$solution" />
    </div>
</x-layouts.layout>
