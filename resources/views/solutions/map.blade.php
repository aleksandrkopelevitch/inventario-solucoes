{{-- The ecosystem map: a thin bar with the view selector, and the canvas
     filling everything below it.

     Fluid and full height, like `diagrams/show` and the documentation editor —
     the canvas IS the content here. The bar used to carry four filters
     (reading, status, category, directorate); since 2026-10-07 it carries the
     VIEW instead — "Por ligações" or "Por hospedagem" — and, on the second, how
     its arrows are drawn. Finding one system is the search box on the canvas. --}}
<x-layouts.layout title="Mapa de integrações" :fluid="true">
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-white px-3 py-2">
            <div class="flex min-w-0 items-center gap-3">
                <h1 class="shrink-0 font-display text-[17px] font-bold tracking-tight text-ink">Mapa de integrações</h1>
                <p class="hidden min-w-0 truncate text-[12px] text-muted lg:block">
                    Uma seta por par de sistemas, no sentido dos fluxos. Clique num sistema ou numa seta para ver os detalhes.
                </p>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                {{-- A fixed width: the select component wraps itself in a
                     w-full div, which in a flex row takes the whole bar. --}}
                <div class="w-44">
                    <x-forms.select data-ak-map-view aria-label="Visão do mapa" class="!py-1.5 !text-[13px]">
                        @foreach ($views as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-forms.select>
                </div>

                {{-- Only on "Por hospedagem": arrows between the CONTAINERS (one
                     per link the systems have — the default) or between the
                     systems themselves, as on the links view. --}}
                <div data-ak-map-arrows hidden class="flex items-center rounded-field border border-line bg-canvas p-0.5">
                    <x-forms.button type="button" variant="ghost" data-ak-map-arrows-mode="hosting" aria-pressed="true"
                        class="!rounded-[6px] !px-2.5 !py-1 !text-[12px] aria-pressed:!bg-white aria-pressed:!text-ink aria-pressed:!shadow-sm">
                        Setas por hospedagem
                    </x-forms.button>
                    <x-forms.button type="button" variant="ghost" data-ak-map-arrows-mode="solution" aria-pressed="false"
                        class="!rounded-[6px] !px-2.5 !py-1 !text-[12px] aria-pressed:!bg-white aria-pressed:!text-ink aria-pressed:!shadow-sm">
                        Setas por solução
                    </x-forms.button>
                </div>
            </div>
        </div>

        <x-ecosystem-map id="global-map" :source-url="route('solutions.map.data')"
            height="100%" class="min-h-0 flex-1 !rounded-none !shadow-none !ring-0" />
    </div>
</x-layouts.layout>
