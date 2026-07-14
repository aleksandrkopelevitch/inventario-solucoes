@props([
    'id',
    'sourceUrl' => null,
    'height' => '620px',
    'emptyMessage' => 'Nenhuma solução conectada para exibir com os filtros atuais.',
])

{{--
    Mapa do ecossistema (somente leitura) — layout radial hub-and-spoke:
    cada solução é um hub (cartão arredondado, mesmo visual do canvas de
    integração de cada solução — `resources/views/components/solutions/
    integration-viz.blade.php`) com um círculo de vizinhos diretos ao redor.
    Vizinhos (satélites) são o MESMO tipo de cartão (avatar + nome, variante
    compacta) — não um avatar-só — pra sempre dar pra ler o nome. Substitui
    o antigo `<x-flow-canvas>` (canvas 2D + dagre esquerda→direita), que
    desenhava uma curva por SEGMENTO de chain sem dedup, emaranhando o
    desenho conforme mais integrações eram cadastradas.

    Fonte dos dados: contrato neutro do IntegrationGraphService (já
    deduplicado por par — uma aresta por par de soluções, não uma por
    segmento — ver `IntegrationGraphService::dedupePairs()`), buscado via
    fetch em `sourceUrl` (hoje só `solutions.map.data`). Todo o desenho é
    DOM+SVG (não canvas-2D) — nós reais em `<div>`, arestas num `<svg>`
    overlay, ambos dentro de `[data-eco-world]` com um único `transform` de
    pan/zoom, exatamente o padrão de `integration-viz.js` (sem nenhuma das
    ferramentas de autoria de lá: sem drag, sem editor de nó/aresta, sem
    sidebar de comentário — clique num hub ou satélite abre um popover com
    os atributos daquela solução + botão "Ver mais" pra sua página, em nova
    aba).

    Hubs com muitas conexões (mais que `EXPAND_THRESHOLD` em
    `ecosystem-map.js`) nascem colapsados — só o cartão + uma badge com a
    contagem; clique na badge expande/colapsa o próprio anel (reflow
    completo do grid empacotado, que reserva mais espaço pra cada hub
    considerando o tamanho do seu anel quando expandido). Uma solução pode
    aparecer como hub (posição própria) E como satélite no anel de outro hub
    — intencional: é o que elimina os cruzamentos de linha do desenho antigo.
--}}
<div
    data-ecosystem-map
    id="{{ $id }}"
    class="ak-viz relative w-full overflow-hidden rounded-card border border-line bg-surface"
    style="height: {{ $height }}"
    data-source-url="{{ $sourceUrl }}"
    data-nav-base="{{ url('/solucoes') }}"
>
    <div data-eco-stage class="relative h-full min-h-0">
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
                {{-- hubs/satélites injetados por ecosystem-map.js --}}
            </div>
        </div>

        <div data-eco-status class="pointer-events-none absolute left-2.5 top-2.5 z-10 rounded border border-line bg-surface/90 px-2 py-0.5 font-mono text-[10px] text-faint backdrop-blur"></div>

        {{-- Mesma ressalva de `hidden`+`flex` do flow-canvas: cada estado
             sobreposto fica sozinho no elemento que o JS alterna. --}}
        <div data-eco-loading class="absolute inset-0 z-[5] bg-surface text-sm text-faint">
            <div class="flex h-full items-center justify-center">Carregando grafo…</div>
        </div>
        <div data-eco-empty class="absolute inset-0 z-[5] {{ $sourceUrl ? 'hidden' : '' }} bg-surface text-sm text-faint">
            <div class="flex h-full items-center justify-center px-6 text-center">{{ $emptyMessage }}</div>
        </div>

        {{-- Busca de sistema (lupa ou Ctrl+K/Cmd+K) — ecosystem-map.js abre/
             fecha via `style.display` (não a classe `hidden`), pela mesma
             razão de especificidade documentada junto ao satélite abaixo. --}}
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

    {{-- Inline (não @push): igual ao integration-viz, a página monta este
         componente uma única vez, sem risco de duplicar estilo. Reaproveita
         os mesmos tokens/classes `--viz-*`/`.ak-viz-node*` do
         integration-viz para hub e satélite renderizarem com o mesmo
         cartão — `.ak-eco-*` é só o vocabulário novo (variante compacta do
         satélite, badge de contagem, popover de atributos). --}}
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

            /* Halo por trás de um hub expandido + seu anel de satélites —
               amarra o cluster visualmente (`ecosystem-map.js::drawHalo`).
               Bem sutil de propósito: é fundo, não deve competir com os
               cartões nem com a linha da seta. */
            .ak-eco-halo {
                fill: var(--viz-node);
                opacity: .16;
            }

            /* Cartão do hub — idêntico ao bloco do integration-viz. */
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
            /* Hub em branco, satélite na cor de acento (`--viz-node`, herdada
               da regra base acima) — antes os dois usavam a mesma cor e o
               olho não distinguia "a solução central desta vizinhança" de
               "uma vizinha dela". O anel (`box-shadow` inset-like via
               0 0 0 Npx) substitui a borda pra não alterar o box model. */
            .ak-viz-node.ak-eco-hub {
                background: #fff;
                box-shadow: 0 0 0 1.5px var(--viz-node), 0 1px 2px rgba(16, 24, 40, .08);
            }
            .ak-viz-node.ak-eco-hub:hover {
                box-shadow: 0 0 0 1.5px var(--viz-select), 0 4px 14px rgba(16, 24, 40, .16);
            }
            /* Hub é arrastável (ecosystem-map.js::startHubDrag) — `grab`/
               `grabbing` avisa isso, diferente do satélite (só clicável). */
            .ak-viz-node.ak-eco-hub { cursor: grab; }
            .ak-viz-node.ak-eco-hub.is-dragging {
                cursor: grabbing;
                z-index: 10;
                box-shadow: 0 0 0 1.5px var(--viz-select), 0 8px 22px rgba(16, 24, 40, .22);
            }
            /* Destaque temporário ao focar um sistema pela busca
               (ecosystem-map.js::focusHub) — mesmo tom de seleção do hover,
               só que sustentado por alguns segundos sem precisar do mouse
               em cima. */
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

            /* Satélite: mesmo cartão do hub (`.ak-viz-node`), variante
               compacta — posicionado pelo CENTRO (`translate(-50%,-50%)`),
               ao contrário do hub (posicionado pelo canto, vindo do grid),
               porque sua posição nasce de trigonometria sobre um ponto
               central (`ringRadius()`/`drawRing()` em ecosystem-map.js). */
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

            /* Badge de contagem — colapsado (número, clique expande) ou
               expandido (traço, clique recolhe). Canto inferior direito do hub. */
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

            /* Popover de atributos — aberto ao clicar num hub ou satélite
               (ecosystem-map.js::openPopover). Mesmo visual dos editores
               flutuantes do integration-viz (cartão arredondado + sombra). */
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

            /* Busca de sistema (ecosystem-map.js — `openSearch`/
               `renderSearchResults`). Reaproveita `.ak-viz-node-body`/
               `.ak-viz-node-avatar` (mesmo avatar do cartão) pra cada item
               da lista de resultados. */
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
