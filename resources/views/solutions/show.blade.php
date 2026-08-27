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

    {{-- 2.5. A committee approved a topology for this solution and nobody has
         applied it to the catalog yet. Above the diagrams list on purpose:
         it is a warning ABOUT that list — the graph below is showing the
         previous scenario. Not a slot: nothing on this page changes it, and it
         is resolved on the submission's own screen. --}}
    @foreach ($pendingTopologies as $pending)
        <div class="animate-ak-rise mt-5 flex flex-wrap items-center gap-3 rounded-card border border-cat-amber-line bg-cat-amber-soft px-4 py-3" style="animation-delay: 80ms">
            <x-heroicon-o-arrow-path-rounded-square class="size-5 shrink-0 text-cat-amber-ink" />
            <p class="min-w-0 flex-1 text-sm text-cat-amber-ink">
                O comitê aprovou uma topologia para esta solução em
                {{ $pending->approved_at->format('d/m/Y') }} que ainda não foi aplicada —
                os diagramas abaixo mostram o cenário anterior.
            </p>
            <a href="{{ route('submissions.show', $pending->submission) }}"
               class="shrink-0 text-sm font-medium text-cat-amber-ink underline">Ver a submissão</a>
        </div>
    @endforeach

    {{-- 3. What this solution HAS been documented with, in one card: the
         diagrams it takes part in on the left, the cadernos that document it on
         the right. They were two stacked cards until 2026-08-17 — the same
         kind of thing (a list of pages you open to read/edit, both living
         under the same consolidated docs screen), read one under the other as
         if they were unrelated sections.

         Each column stays its OWN updatable slot (`Diagrams` / `Notebooks`):
         creating or deleting on one side must not re-render the other. That's also why the card's chrome (border/shadow/radius)
         lives here and not in either component — a slot swap replaces the
         component's root node wholesale, so the frame has to be outside it.
         The divider between the columns is the one exception, carried by the
         right column itself (it re-renders identically on every swap). --}}
    <div class="animate-ak-rise mt-5 grid overflow-hidden rounded-card border border-line bg-surface shadow-card lg:grid-cols-2"
         style="animation-delay: 90ms">
        <x-solutions.diagrams :solution="$solution" />
        <x-solutions.notebooks :solution="$solution" />
    </div>
</x-layouts.layout>
