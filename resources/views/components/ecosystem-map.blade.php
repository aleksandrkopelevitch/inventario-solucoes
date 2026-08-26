@props([
    'id',
    'sourceUrl' => null,
    'height' => '620px',
    'emptyMessage' => 'Nenhuma solução conectada para exibir com os filtros atuais.',
])

{{--
    Ecosystem map (read-only) — radial hub-and-spoke layout: each solution
    is a hub (rounded card, same look as each solution's diagram canvas
    — `resources/views/components/solutions/chain/viz.blade.php`)
    with a ring of direct neighbors around it. Neighbors (satellites) are
    the SAME card type (avatar + name, compact variant) — not an avatar
    alone — so the name is always readable. Replaces the old
    `<x-flow-canvas>` (2D canvas + dagre left→right), which drew one curve
    per chain SEGMENT with no dedup, tangling the drawing as more
    diagrams were registered.

    Data source: DiagramGraphService's neutral contract (already
    deduped by pair — one edge per pair of solutions, not one per segment —
    see `DiagramGraphService::dedupePairs()`), fetched via `sourceUrl`
    (today only `solutions.map.data`). The whole drawing is DOM+SVG (not
    2D canvas) — real nodes in `<div>`s, edges in an overlay `<svg>`, both
    inside `[data-eco-world]` with a single pan/zoom `transform`, exactly
    the same pattern as `chain-viz.js` (without any of that page's
    authoring tools: no drag, no node/edge editor, no comment sidebar —
    clicking a hub or satellite opens a popover with that solution's
    attributes + a "Ver mais" button to its page, in a new tab).

    Hubs with many connections (more than `EXPAND_THRESHOLD` in
    `ecosystem-map.js`) start out collapsed — just the card + a badge with
    the count; clicking the badge expands/collapses that ring (a full
    reflow of the packed grid, which reserves more space for each hub
    accounting for its ring's size when expanded). A solution can appear
    both as a hub (its own position) AND as a satellite in another hub's
    ring — intentional: that's what eliminates the crossed lines from the
    old drawing.
--}}
<div
    data-ecosystem-map
    id="{{ $id }}"
    class="ak-viz relative w-full overflow-hidden rounded-card border border-line bg-surface"
    style="height: {{ $height }}"
    data-source-url="{{ $sourceUrl }}"
>
    <div class="relative h-full min-h-0">
        <div data-eco-viewport class="ak-viz-viewport">
            <div data-eco-world class="ak-viz-world">
                <svg data-eco-edges class="ak-viz-edges" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <marker data-eco-marker-end viewBox="0 0 10 10" refX="9" refY="5"
                            markerWidth="7" markerHeight="7" orient="auto-start-reverse" markerUnits="userSpaceOnUse">
                            <path d="M0 0 L10 5 L0 10 z" />
                        </marker>
                        <marker data-eco-marker-start viewBox="0 0 10 10" refX="9" refY="5"
                            markerWidth="7" markerHeight="7" orient="auto-start-reverse" markerUnits="userSpaceOnUse">
                            <path d="M0 0 L10 5 L0 10 z" />
                        </marker>
                    </defs>
                </svg>
                {{-- hubs/satellites injected by ecosystem-map.js --}}
            </div>
        </div>

        <div data-eco-status class="pointer-events-none absolute left-2.5 top-2.5 z-10 rounded border border-line bg-surface/90 px-2 py-0.5 font-mono text-[10px] text-faint backdrop-blur"></div>

        {{-- Same `hidden`+`flex` caveat as flow-canvas: each overlaid state
             lives alone in the element the JS toggles. --}}
        <div data-eco-loading class="absolute inset-0 z-[5] bg-surface text-sm text-faint">
            <div class="flex h-full items-center justify-center">Carregando grafo…</div>
        </div>
        <div data-eco-empty class="absolute inset-0 z-[5] {{ $sourceUrl ? 'hidden' : '' }} bg-surface text-sm text-faint">
            <div class="flex h-full items-center justify-center px-6 text-center">{{ $emptyMessage }}</div>
        </div>

        {{-- System search (magnifier or Ctrl+K/Cmd+K) — ecosystem-map.js opens/
             closes it via `style.display` (not the `hidden` class), for the
             same specificity reason documented next to the satellite below. --}}
        <div data-eco-search-overlay class="absolute inset-0 z-40 items-start justify-center bg-ink/25 pt-20 backdrop-blur-[1px]" style="display: none">
            <div class="w-full max-w-md rounded-xl border border-line bg-surface shadow-[0_12px_32px_rgba(16,24,40,.24)]">
                <div class="flex items-center gap-2 border-b border-line px-3 py-2.5">
                    <x-heroicon-o-magnifying-glass class="size-4 shrink-0 text-faint" />
                    <x-forms.input type="text" data-eco-search-input placeholder="Buscar sistema…"
                        class="!border-0 !bg-transparent !p-0 !text-sm !shadow-none focus:!shadow-none" />
                    <kbd class="shrink-0 rounded border border-line bg-raised px-1.5 py-0.5 font-mono text-[10px] text-faint">Esc</kbd>
                </div>
                <ul data-eco-search-results class="max-h-72 overflow-y-auto p-1.5"></ul>
                <div data-eco-search-empty class="hidden px-3 py-8 text-center text-sm text-faint">Nenhum sistema encontrado.</div>
            </div>
        </div>

        <div data-eco-bottombar
            class="absolute bottom-3 left-1/2 z-10 flex -translate-x-1/2 items-center gap-1 rounded-lg border border-line bg-surface/95 p-1 shadow-[0_2px_8px_rgba(20,58,34,0.08)] backdrop-blur">
            <x-forms.button type="button" variant="ghost" data-eco-search-open title="Buscar sistema (Ctrl+K)"
                class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                <x-heroicon-o-magnifying-glass class="size-4" />
            </x-forms.button>
            <span class="mx-0.5 h-5 w-px bg-line"></span>
            <x-forms.button type="button" variant="ghost" data-eco-zoom-out title="Diminuir zoom"
                class="!rounded-md !px-2.5 !py-1 !text-base !font-medium !text-ink hover:!bg-accent-soft">−</x-forms.button>
            <span data-eco-zoom-label class="w-12 select-none text-center font-mono text-[11px] text-faint">100%</span>
            <x-forms.button type="button" variant="ghost" data-eco-zoom-in title="Aumentar zoom"
                class="!rounded-md !px-2.5 !py-1 !text-base !font-medium !text-ink hover:!bg-accent-soft">+</x-forms.button>
            <span class="mx-0.5 h-5 w-px bg-line"></span>
            <x-forms.button type="button" variant="ghost" data-eco-fit title="Centralizar"
                class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                <x-heroicon-o-arrows-pointing-in class="size-4" />
            </x-forms.button>
            <x-forms.button type="button" variant="ghost" data-eco-fullscreen title="Tela cheia"
                class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                <x-heroicon-o-arrows-pointing-out data-eco-fs-open class="size-4" />
                <x-heroicon-o-arrows-pointing-in data-eco-fs-close class="hidden size-4" />
            </x-forms.button>
        </div>
    </div>

    {{-- Inline (not @push): like diagram-viz, the page mounts this
         component only once, with no risk of duplicating the style. Reuses
         the same `--viz-*`/`.ak-viz-node*` tokens/classes from
         diagram-viz so hub and satellite render with the same
         card — `.ak-eco-*` is just the new vocabulary (satellite's compact
         variant, count badge, attribute popover). --}}
    <style>
            [data-ecosystem-map] {
                --viz-bg: #F7F9FC;
                --viz-grid: #E7ECF4;
                --viz-line: #94A3C4;
                --viz-node: #C9D4F7;
                --viz-ink: #1A1A2E;
                --viz-select: #4A90D9;
            }
            .ak-viz-viewport {
                position: absolute;
                inset: 0;
                overflow: hidden;
                cursor: grab;
                touch-action: none;
                background:
                    radial-gradient(circle at 1px 1px, var(--viz-grid) 1px, transparent 0) 0 0 / 26px 26px,
                    var(--viz-bg);
            }
            .ak-viz-viewport.is-panning { cursor: grabbing; }
            .ak-viz-world {
                position: absolute;
                top: 0;
                left: 0;
                transform-origin: 0 0;
            }
            .ak-viz-edges {
                position: absolute;
                top: 0;
                left: 0;
                overflow: visible;
                pointer-events: none;
            }
            .ak-viz-edges path.ak-viz-edge {
                fill: none;
                stroke: var(--viz-line);
                stroke-width: 1.6;
            }
            .ak-viz-edges marker path { fill: var(--viz-line); }

            /* Halo behind an expanded hub + its satellite ring — visually
               ties the cluster together (`ecosystem-map.js::drawHalo`).
               Deliberately subtle: it's background, shouldn't compete with
               the cards or the arrow line. */
            .ak-eco-halo {
                fill: var(--viz-node);
                opacity: .16;
            }

            /* Hub card — identical to the block in diagram-viz. */
            .ak-viz-node {
                position: absolute;
                display: flex;
                flex-direction: column;
                gap: 4px;
                width: max-content;
                min-width: 54px;
                max-width: 200px;
                padding: 10px 14px;
                border-radius: 13px;
                background: var(--viz-node);
                color: var(--viz-ink);
                font-family: 'Space Grotesk', 'Inter', system-ui, sans-serif;
                font-size: 13px;
                line-height: 1.35;
                font-weight: 500;
                white-space: normal;
                overflow-wrap: break-word;
                user-select: none;
                box-shadow: 0 1px 2px rgba(16, 24, 40, .08), 0 0 0 1px rgba(16, 24, 40, .03);
                cursor: pointer;
                transition: box-shadow .12s ease;
            }
            /* White hub, accent-colored satellite (`--viz-node`, inherited
               from the base rule above) — the two used to share the same
               color and the eye couldn't tell "this neighborhood's central
               solution" from "one of its neighbors". The ring (inset-like
               `box-shadow` via 0 0 0 Npx) replaces the border so it doesn't
               change the box model. */
            .ak-viz-node.ak-eco-hub {
                background: #fff;
                box-shadow: 0 0 0 1.5px var(--viz-node), 0 1px 2px rgba(16, 24, 40, .08);
            }
            .ak-viz-node.ak-eco-hub:hover {
                box-shadow: 0 0 0 1.5px var(--viz-select), 0 4px 14px rgba(16, 24, 40, .16);
            }
            /* Hub is draggable (ecosystem-map.js::startHubDrag) — `grab`/
               `grabbing` signals that, unlike the satellite (click-only).
               `--readonly` (no `positionUrl` — a viewer, per
               DiagramGraphService::putNode()) drops back to a plain
               click cursor: dragging never persists for that role, so the
               grab affordance would be a lie. */
            .ak-viz-node.ak-eco-hub { cursor: grab; }
            .ak-viz-node.ak-eco-hub.ak-eco-hub--readonly { cursor: pointer; }
            .ak-viz-node.ak-eco-hub.is-dragging {
                cursor: grabbing;
                z-index: 10;
                box-shadow: 0 0 0 1.5px var(--viz-select), 0 8px 22px rgba(16, 24, 40, .22);
            }
            /* Temporary highlight when focusing a system via search
               (ecosystem-map.js::focusHub) — same selection tone as hover,
               just held for a few seconds without needing the mouse over
               it. */
            .ak-viz-node.ak-eco-hub.is-focused {
                box-shadow: 0 0 0 3px var(--viz-select), 0 8px 22px rgba(16, 24, 40, .22);
                animation: ak-eco-focus-pulse 1s ease-out 2;
            }
            @keyframes ak-eco-focus-pulse {
                0% { box-shadow: 0 0 0 3px var(--viz-select), 0 0 0 10px rgba(74, 144, 217, .35); }
                100% { box-shadow: 0 0 0 3px var(--viz-select), 0 0 0 10px rgba(74, 144, 217, 0); }
            }
            .ak-viz-node-body {
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .ak-viz-node-avatar {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 20px;
                height: 20px;
                border-radius: 50%;
                overflow: hidden;
                flex-shrink: 0;
                background: #fff;
                box-shadow: 0 0 0 1px rgba(16, 24, 40, .08);
                font-size: 10px;
                font-weight: 700;
            }
            .ak-viz-node-avatar img {
                width: 100%;
                height: 100%;
                object-fit: contain;
            }
            .ak-viz-node-avatar.is-fallback {
                background: var(--viz-select);
                color: #fff;
            }

            /* Satellite: same card as the hub (`.ak-viz-node`), compact
               variant — positioned by its CENTER (`translate(-50%,-50%)`),
               unlike the hub (positioned by its corner, coming from the
               grid), because its position comes from trigonometry around a
               central point (`ringRadius()`/`drawRing()` in ecosystem-map.js). */
            .ak-viz-node.ak-eco-satellite-card {
                position: absolute;
                transform: translate(-50%, -50%);
                padding: 6px 9px;
                font-size: 11px;
                max-width: 130px;
                gap: 3px;
                z-index: 5;
                transition: transform .12s ease, box-shadow .12s ease;
            }
            .ak-viz-node.ak-eco-satellite-card .ak-viz-node-avatar {
                width: 16px;
                height: 16px;
                font-size: 8px;
            }
            .ak-viz-node.ak-eco-satellite-card:hover {
                transform: translate(-50%, -50%) scale(1.06);
                box-shadow: 0 4px 14px rgba(16, 24, 40, .16);
            }

            /* Count badge — collapsed (number, click expands) or expanded
               (dash, click collapses). Hub's bottom-right corner. */
            .ak-eco-badge {
                position: absolute;
                right: -8px;
                bottom: -8px;
                min-width: 20px;
                height: 20px;
                padding: 0 5px;
                border: 2px solid var(--viz-bg);
                border-radius: 999px;
                background: var(--viz-select);
                color: #fff;
                font-family: ui-monospace, 'SF Mono', Menlo, Consolas, monospace;
                font-size: 10.5px;
                font-weight: 700;
                line-height: 16px;
                text-align: center;
                cursor: pointer;
                z-index: 6;
            }
            .ak-eco-badge:hover { filter: brightness(1.08); }
            .ak-eco-badge.is-open {
                background: #fff;
                color: var(--viz-line);
                border-color: var(--viz-line);
            }

            [data-ecosystem-map]:fullscreen {
                background: var(--viz-bg);
                border-radius: 0;
            }
            [data-ecosystem-map]:fullscreen .ak-viz-viewport { border-radius: 0; }

            /* Attributes popover — opened by clicking a hub or satellite
               (ecosystem-map.js::openPopover). Same look as diagram-viz's
               floating editors (rounded card + shadow). */
            .ak-eco-popover {
                position: absolute;
                z-index: 30;
                width: 240px;
                padding: 12px;
                border-radius: 12px;
                border: 1px solid var(--viz-line);
                background: #fff;
                box-shadow: 0 8px 28px rgba(16, 24, 40, .16);
                font-family: 'Space Grotesk', 'Inter', system-ui, sans-serif;
            }
            .ak-eco-popover-head {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 8px;
                margin-bottom: 8px;
            }
            .ak-eco-popover-title {
                font-size: 13.5px;
                font-weight: 700;
                color: var(--viz-ink);
            }
            .ak-eco-popover-close {
                flex-shrink: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                width: 20px;
                height: 20px;
                margin: -2px -2px 0 0;
                border: none;
                border-radius: 6px;
                background: transparent;
                color: #8891ab;
                font-size: 16px;
                line-height: 1;
                cursor: pointer;
            }
            .ak-eco-popover-close:hover {
                background: #f1f3f9;
                color: var(--viz-ink);
            }
            .ak-eco-popover-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 6px 10px;
                margin-bottom: 10px;
            }
            .ak-eco-popover-attr {
                display: flex;
                flex-direction: column;
                gap: 1px;
                min-width: 0;
            }
            .ak-eco-popover-attr-label {
                font-size: 9px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .04em;
                color: #8891ab;
            }
            .ak-eco-popover-attr-value {
                font-size: 11.5px;
                color: var(--viz-ink);
                overflow-wrap: break-word;
            }
            .ak-eco-popover-more {
                display: block;
                text-align: center;
                padding: 6px 10px;
                border-radius: 8px;
                background: var(--viz-select);
                color: #fff;
                font-size: 12px;
                font-weight: 600;
                text-decoration: none;
                cursor: pointer;
            }
            .ak-eco-popover-more:hover { filter: brightness(1.08); }

            /* System search (ecosystem-map.js — `openSearch`/
               `renderSearchResults`). Reuses `.ak-viz-node-body`/
               `.ak-viz-node-avatar` (same card avatar) for each item in the
               results list. */
            .ak-eco-search-item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
                padding: 7px 9px;
                border-radius: 8px;
                cursor: pointer;
                font-family: 'Space Grotesk', 'Inter', system-ui, sans-serif;
                font-size: 12.5px;
                color: var(--viz-ink);
            }
            .ak-eco-search-item.is-active { background: var(--viz-bg); }
            .ak-eco-search-item-label {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .ak-eco-search-item-meta {
                flex-shrink: 0;
                font-size: 10.5px;
                color: #8891ab;
            }
    </style>
</div>
