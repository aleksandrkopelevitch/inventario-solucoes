<x-layouts.layout :title="$solution->name">
    <div class="mb-6">
        <a href="{{ route('solutions.index') }}" class="text-sm text-accent hover:underline">&larr; Soluções</a>
    </div>

    {{-- 1/2. Header + "Operation" block (Solutions\DetailHeader, updatable) --}}
    <x-solutions.detail-header :solution="$solution" />

    {{-- 3. Integrations (F3) — list of integrations on the left (create/delete,
         selectable); on the right, the graphical visualization of the
         selected integration, which also authors blocks/links/protocol and
         rename/status (topbar pencil). --}}
    <div class="mt-5 rounded-card border border-line bg-surface p-6 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
        <div class="flex items-baseline gap-2.5">
            <span class="inline-flex items-center rounded-md bg-accent px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-[0.12em] text-white">F3</span>
            <h2 class="font-display text-[22px] font-semibold text-ink">Integrações</h2>
        </div>
        <p class="mt-1 text-sm text-muted">Selecione uma integração à esquerda para ver a visualização gráfica.</p>

        <div class="mt-4 flex flex-col gap-4 lg:flex-row lg:items-stretch">
            {{-- Left: list of the solution's integrations --}}
            <div class="w-full shrink-0 lg:w-2/5 lg:max-w-sm">
                <x-solutions.integrations-map :solution="$solution" />
            </div>

            {{-- Right: graphical visualization (pan/zoom + fullscreen) of the selected integration --}}
            <x-solutions.integration-viz />
        </div>
    </div>

    {{-- 4. Solution's rich documentation (block editor, read-only here).
         Documentation coverage (former F7 block) became the "..." menu in the
         header of the /documentacao sub-page — it no longer lives here. --}}
    <x-solutions.documentation :solution="$solution" />
</x-layouts.layout>
