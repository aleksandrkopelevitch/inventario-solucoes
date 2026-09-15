@props([
    'id'        => null,
    'sourceUrl' => null,
    'height'    => '620px',
])

{{-- Ecosystem map shell: a dark canvas surface inside the app's light chrome,
     plus the controls that sit on top of it. Everything that MOVES is drawn by
     `resources/js/modules/ecosystem-map.js` into the single <canvas> — this
     file owns the frame, the buttons and the two overlays the module fills
     (`data-ak-map-tip`, `data-ak-map-card`).

     The surface is dark on purpose and it is the app's own dark: the same
     near-black the sidebar is built from, not a second theme. A graph reads
     better against it (a glow needs somewhere to glow), and the map is the one
     screen in the inventory that is a picture rather than a document.

     The renderer is adapted from the "Second Brain" visualizer by Jay E /
     RoboNuggets under CC BY 4.0 — the credit at the bottom left is that
     license's attribution requirement, not decoration. See NOTICE. --}}

<div
    @if ($id) id="{{ $id }}" @endif
    data-ak-ecosystem-map
    data-ak-map-url="{{ $sourceUrl }}"
    {{ $attributes->class(['relative overflow-hidden rounded-card bg-[#0b0e11] shadow-card ring-1 ring-black/15']) }}
    style="height: {{ $height }}"
>
    <canvas data-ak-map-canvas class="absolute inset-0 h-full w-full cursor-grab touch-none"></canvas>

    {{-- Top bar: search on the left, camera controls on the right. The row
         itself doesn't take the pointer, so dragging the graph works right up
         to the edges — only the controls inside it do. --}}
    <div class="pointer-events-none absolute inset-x-0 top-0 flex items-start justify-between gap-3 p-3">
        <div class="pointer-events-auto w-full max-w-[280px]">
            <div class="relative">
                <x-forms.input
                    data-ak-map-search
                    type="search"
                    placeholder="Buscar sistema ou diagrama…"
                    autocomplete="off"
                    class="!border-white/15 !bg-white/10 !py-1.5 !text-[13px] !text-white placeholder:!text-white/40 focus:!border-lime focus:!shadow-[0_0_0_3px_rgba(170,219,30,0.18)]"
                />
                <div
                    data-ak-map-results
                    hidden
                    class="absolute inset-x-0 top-full z-10 mt-1 overflow-hidden rounded-field border border-white/15 bg-[#11161a]/95 shadow-xl backdrop-blur"
                ></div>
            </div>

            <div data-ak-map-crumb class="mt-2 flex flex-wrap gap-1.5"></div>
        </div>

        <div class="pointer-events-auto flex shrink-0 items-center gap-1 rounded-field border border-white/10 bg-white/5 p-1 backdrop-blur">
            <x-forms.button type="button" variant="ghost" data-ak-map-action="layout" title="Alternar entre órbitas e simulação de força"
                class="!rounded-md !px-2.5 !py-1 !text-[11px] !font-medium !text-white/70 hover:!bg-white/10 hover:!text-white">
                <span data-label>Órbitas</span>
            </x-forms.button>
            <x-forms.button type="button" variant="ghost" data-ak-map-action="orbit" data-ak-map-on="false" title="Girar o mapa lentamente"
                class="!rounded-md !px-2.5 !py-1 !text-[11px] !font-medium !text-white/70 hover:!bg-white/10 hover:!text-white data-[ak-map-on=true]:!bg-lime/20 data-[ak-map-on=true]:!text-lime">
                Girar
            </x-forms.button>
            <span class="mx-0.5 h-4 w-px bg-white/10"></span>
            <x-forms.button type="button" variant="ghost" data-ak-map-action="zoom-out" title="Diminuir zoom"
                class="!rounded-md !px-2.5 !py-1 !text-base !leading-none !text-white/70 hover:!bg-white/10 hover:!text-white">−</x-forms.button>
            <x-forms.button type="button" variant="ghost" data-ak-map-action="zoom-in" title="Aumentar zoom"
                class="!rounded-md !px-2.5 !py-1 !text-base !leading-none !text-white/70 hover:!bg-white/10 hover:!text-white">+</x-forms.button>
            <x-forms.button type="button" variant="ghost" data-ak-map-action="fit" title="Centralizar"
                class="!rounded-md !px-2 !py-1 !text-white/70 hover:!bg-white/10 hover:!text-white">
                <x-heroicon-o-viewfinder-circle class="size-4" />
            </x-forms.button>
            <x-forms.button type="button" variant="ghost" data-ak-map-action="collapse" title="Recolher tudo (Esc)"
                class="!rounded-md !px-2 !py-1 !text-white/70 hover:!bg-white/10 hover:!text-white">
                <x-heroicon-o-arrows-pointing-in class="size-4" />
            </x-forms.button>
            <x-forms.button type="button" variant="ghost" data-ak-map-action="fullscreen" title="Tela cheia"
                class="!rounded-md !px-2 !py-1 !text-white/70 hover:!bg-white/10 hover:!text-white">
                <x-heroicon-o-arrows-pointing-out class="size-4" />
            </x-forms.button>
        </div>
    </div>

    {{-- Hover tooltip and the click card. Both are filled by the module and
         positioned over the canvas; the tooltip never takes the pointer, or it
         would steal the very hover that opened it. --}}
    <div
        data-ak-map-tip
        hidden
        class="pointer-events-none absolute z-20 max-w-[240px] rounded-lg border border-white/15 bg-[#11161a]/95 px-3 py-2 text-[12px] leading-snug text-white shadow-xl backdrop-blur"
    ></div>

    <div
        data-ak-map-card
        hidden
        class="absolute bottom-3 right-3 z-20 w-[260px] rounded-card border border-white/15 bg-[#11161a]/95 p-3 shadow-xl backdrop-blur"
    ></div>

    {{-- Legend + attribution, bottom left. --}}
    <div class="pointer-events-none absolute inset-x-0 bottom-0 flex items-end justify-between gap-3 p-3">
        <div class="rounded-lg border border-white/10 bg-black/30 px-3 py-2 backdrop-blur">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[10px] uppercase tracking-wider text-white/45">
                <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-white/70"></span>Sistema</span>
                <span class="flex items-center gap-1.5"><span class="size-2.5 rotate-90 bg-lime/80 [clip-path:polygon(25%_0,75%_0,100%_50%,75%_100%,25%_100%,0_50%)]"></span>Diagrama</span>
                <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-[3px] bg-white/40"></span>Bloco do fluxo</span>
            </div>
            <p data-ak-map-status class="mt-1 text-[10px] text-white/35"></p>
        </div>

        <p class="text-right text-[9px] leading-tight text-white/25">
            Visualização adaptada do<br>
            <a href="https://skool.com/robonuggets" target="_blank" rel="noopener"
               class="pointer-events-auto underline decoration-white/20 underline-offset-2 transition hover:text-white/50">Second Brain · Jay E / RoboNuggets</a>
            · CC BY 4.0
        </p>
    </div>
</div>
