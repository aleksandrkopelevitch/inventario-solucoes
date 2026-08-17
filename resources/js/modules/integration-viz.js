import { toCanvas, getFontEmbedCSS } from 'html-to-image'
import GIF from 'gif.js'
import gifWorkerUrl from 'gif.js/dist/gif.worker.js?url'
import { setButtonLoading } from './button-loading'

// `gifWorkerUrl` (Vite's `?url` import) resolves to a URL on the DEV SERVER's
// own origin (e.g. `https://host:5174/...`) whenever Vite is running in dev
// mode, which is a DIFFERENT origin than the page itself (`https://host`, no
// port) even on the same hostname — `new Worker(url)` enforces same-origin
// unconditionally (unlike `fetch()`, this isn't CORS-negotiable), so passing
// `gifWorkerUrl` straight to `GIF`'s `workerScript` option throws
// `SecurityError: Failed to construct 'Worker'` every single time in dev
// (confirmed 2026-08-04 — exactly the "Não foi possível gerar o vídeo."
// report). Fetching the script's TEXT first and handing the worker a
// `Blob`/`URL.createObjectURL()` of it sidesteps the restriction entirely — a
// blob: URL is always same-origin-safe to build a Worker from — and this
// works identically in production (same-origin build, the extra fetch is
// just redundant but harmless), so it's a real fix, not a dev-only patch.
// Memoized at module level (not per mount()) since the script's content is
// static for the page's lifetime and every diagram instance would resolve
// the exact same URL; the blob URL is intentionally never revoked — it needs
// to keep working for as long as the page can still open the export menu.
let gifWorkerBlobUrl = null
async function resolveGifWorkerUrl() {
    if (gifWorkerBlobUrl) return gifWorkerBlobUrl
    const res = await fetch(gifWorkerUrl)
    const text = await res.text()
    gifWorkerBlobUrl = URL.createObjectURL(new Blob([text], { type: 'application/javascript' }))
    return gifWorkerBlobUrl
}

// Visualização gráfica da integração — aba "Diagrama" da página unificada da
// integração (Solutions\IntegrationWorkspace; a página também tem a aba
// "Documentação"). Desenha a cadeia (`chain`) da integração como um grafo —
// nós ligados por setas cujo sentido segue o segmento (`->` ida, `<-`
// volta, `<->` ambos) e cujo rótulo é o protocolo. Cada nó tem um TIPO
// (`kind`, ver `App\Enums\ChainNodeKind`): `system` (uma Solução cadastrada ou
// um sistema externo em texto livre), `decision` (a bifurcação do fluxo,
// desenhada como hexágono chanfrado), `actor` (pessoa/área, desenhada como
// badge arredondado com ícone) ou `start`/`end` (início/fim do fluxo,
// desenhados como um círculo de cor sólida — verde/vermelho — com o ícone do
// tipo dentro e o rótulo escrito ABAIXO do círculo, não ao lado). O tipo vem
// resolvido no grafo (`nodes[i].kind` + `nodes[i].icon`, SVG já renderizado no
// servidor); nós salvos antes dos tipos existirem chegam como `system`.
//
// Nós em <div> com sombra + arestas em SVG dentro de um #world com transform.
// Os dados vêm resolvidos no `data-integration-graph` da única linha (oculta,
// auto-selecionada) que `integration-workspace.blade.php` renderiza
// (`integration-select.js` emite `ak:integration-selected`). Nós que
// referenciam uma Solução também trazem `logo`, `environment` e `cloud`
// (rótulo + SVG de ícone já renderizado no servidor) — exibidos como avatar
// e chips discretos em cima do bloco.
//
// Duas camadas são puramente visuais (`viz_layout`, nunca `chain`): a borda
// de um bloco e uma seta podem ser marcadas tracejadas independentemente uma
// da outra (um botão-ícone em cada toolbar — nunca um checkbox), e raias
// (`lanes`) — retângulos de fundo livres e coloridos, com uma etiqueta
// vertical de altura cheia na borda esquerda, num tom mais escuro pra se
// destacar (`darkenHex()`) — marcam uma área do fluxo; uma raia nunca
// referencia um nó, o usuário só arrasta os blocos pra dentro da área
// desejada como já faz em qualquer lugar do canvas. O botão "Raias" da
// topbar cria uma na hora (centrada no viewport atual, sem painel/diálogo no
// caminho) e já a seleciona. Posição/tamanho se editam direto no canvas
// (`rebuildLanes()`): arrastar o CORPO da raia (etiqueta inclusa) move
// (`drag.type === 'lane-move'`), arrastar uma de suas 3 alças redimensiona —
// direita (só largura), embaixo (só altura) ou o canto (ambas, `drag.type
// === 'lane-resize'`). Só um clique SEM arraste NA ETIQUETA (`drag.onLabel`)
// abre o toolbar de cor/nome/remover (`selectLane()`) — clicar sem arrastar
// no resto do corpo não faz nada, de propósito: a etiqueta escura é o único
// alvo de seleção.
//
// Clicar (sem arrastar) um nó seleciona e abre a toolbar contextual (título /
// comentário / abrir solução) num painel flutuante fixo no canto superior
// esquerdo do canvas — estilo excalidraw.com, nunca ancorado ao bloco —, que
// some assim que o bloco é desselecionado. Funciona com ou sem `editable`.
// Edição de posição (quando `editable`): arraste um bloco para reposicioná-lo;
// arraste o handle de uma ponta de seta para grudá-la numa das 8 âncoras do nó
// (4 principais + 2 no topo + 2 na base). "Organizar" recalcula o layout
// padrão esquerda→direita. O botão "Salvar" persiste layout + âncoras +
// comentários (`viz_layout`) no servidor — é só apresentação, não mexe na
// topologia.
//
// TIPO de um bloco já existente (indisponível no nó raiz, índice 0) troca na
// SEGUNDA LINHA da própria toolbar — ícones só (`refreshKindRow()`/
// `changeNodeKind()`, a partir de `[data-ak-node-kinds]`), aplicando na hora
// (PATCH em `graph.nodeUpdateUrl`, sem "Salvar" separado — o servidor
// rederiva participants/source/target/direction, resposta já resolvida, ver
// `Solutions\IntegrationsMap::resolveNode`). Texto/Solução do bloco são
// editados direto na FORMA, por duplo clique (`startInlineLabelEdit()`): num
// bloco `system` isso também é a busca de Solução (autocomplete inline);
// decisão/ator/início/fim são só texto livre, início/fim com um padrão
// próprio ("Início"/"Fim") que o servidor preenche quando fica em branco.
//
// A pill de protocolo em cima de cada seta segue o mesmo espírito: clicável
// quando `editable` (inclusive a pill tracejada "+ protocolo" de um passo sem
// protocolo ainda), abre um painel compacto, uma linha só de ÍCONES
// (`selectEdge()`/`openProtocolEditor()`, mesmo painel fixo à esquerda que a
// toolbar do bloco usa — não mora dentro dela, os dois só se excluem
// mutuamente): sentido (dois toggles
// independentes, `->`/`<-`/`<->`), tracejado (outro ícone-toggle) e
// "Desligar" (remove só a ligação — os blocos continuam existindo, é assim
// que um bloco pode acabar sem nenhuma interligação); sentido e tracejado
// aplicam na hora, sem "Salvar". O protocolo em si não tem campo NESSE
// painel — duplo clique na própria pill vira um `<input>` no lugar
// (`startInlineProtocolEdit()`), texto livre com autocomplete do enum
// `Protocol` como sugestão. PATCH em `graph.edgeUpdateUrl` (sentido ou
// protocolo) ou DELETE em `graph.edgeRemoveUrl` (desligar); não há ligação
// "raiz" protegida aqui, qualquer edge pode ser editada/removida.
//
// O botão "+" da topbar (`openAddEditor()`) acrescenta um bloco NOVO E PURO:
// uma linha horizontal de ÍCONES de tipo (`buildAddKindIcons()`, mesma lista
// de `refreshKindRow()` mas sem seleção persistente) — sem seta e sem
// protocolo, e sem Solução/texto livre pra preencher aqui. Clicar um ícone já
// cria o bloco (`createNodeFromKind()`, POST em `graph.nodeAddUrl` com o
// próprio nome do tipo como texto inicial), que `appendNode()` desenha e
// posiciona à direita do último bloco, e o passo seguinte é
// `startInlineLabelEdit()` nele — nomear (ou, pra `system`, buscar a Solução)
// acontece direto no bloco recém-criado, mesmo gesto de renomear um bloco já
// existente. O MESMO painel reabre (`openQuickAddEditor()`), no mesmo canto
// fixo, quando uma seta puxada de uma porta é solta em espaço vazio (ver a
// forma 1 abaixo) — nesse caso o bloco nasce NO PONTO onde a seta foi
// solta (não à direita do último) e já liga automaticamente com a porta de
// origem, no mesmo clique do ícone; só a posição do bloco novo usa esse
// ponto, o painel em si sempre abre no canto.
//
// A chain é um GRAFO LIVRE, não uma linha reta, e não exige que todo bloco
// esteja ligado a algo: `graph.edges[i]` traz `{from, to, arrow, protocol}`
// com índices de nó explícitos, e o número de edges é independente do número
// de nós. Duas formas de ligar/religar blocos, ambas disponíveis em TODOS os
// nós (inclusive o raiz):
//   1. Arrastar uma seta pra fora de uma das 4 PORTAS de um bloco (os
//      circulinhos que aparecem no hover, filhos do nó — ver `paintNode()` e
//      `startPortDrag()`) e soltar sobre qualquer outro bloco cria uma ligação
//      NOVA na hora: POST em `graph.edgeAddUrl` com `->` e sem protocolo,
//      sem diálogo nenhum no caminho — sentido/protocolo se ajustam depois na
//      pill. Durante o arraste, `drag.type === 'connect'` desenha uma prévia
//      tracejada até o ponteiro e destaca o bloco sob ele; soltar sobre o
//      próprio bloco de origem cancela, mas soltar em CANVAS VAZIO abre o
//      painel "Adicionar bloco" ali mesmo (`openQuickAddEditor()`, ver acima)
//      — puxar uma seta pro vazio ganha um bloco novo já ligado, em vez de
//      simplesmente não fazer nada.
//   2. Arrastar o handle de uma ponta de seta JÁ EXISTENTE para dentro de
//      OUTRO bloco (não só pra outra âncora do mesmo par de nós) religa
//      aquela ligação pra esse bloco — `nodeAtPoint()` decide, durante o
//      arraste, se o ponteiro está sobre um nó diferente do nó original
//      daquela ponta; ao soltar, `retargetEdge()` faz o PATCH em
//      `graph.edgeRetargetUrl` (aplicado otimista antes da resposta, pra não
//      "voltar" visualmente enquanto o request está em voo — reverte em caso
//      de erro). Um bloco não pode se ligar a ele mesmo: soltar sobre a
//      ponta oposta da MESMA ligação é ignorado, mantendo o nó original.
// Entre as duas formas de ligar + o bloco puro do painel de adicionar +
// "Desligar" no editor de ligação, a topologia é um grafo livre de verdade —
// nós e ligações são criados independentemente, sem forçar todo bloco a estar
// conectado.
//
// Nome e status da integração — o único metadado que não mora num nó/aresta
// da chain — NÃO são editados aqui: vivem na barra superior da página
// (`Solutions\IntegrationMeta`, edição in-line), visível também na aba
// Documentação. Este módulo tinha um painel próprio pra isso até 2026-08-17;
// dois editores do mesmo campo dessincronizam na primeira edição. Criar uma
// Integration nova é o form "Nova" da lista da solução
// (`integrations-map.blade.php`), que já entrega a chain com só o nó raiz.

const SVG_NS = 'http://www.w3.org/2000/svg'
const MIN_SCALE = 0.3
const MAX_SCALE = 2.2
const LEVEL_GAP = 90 // espaço horizontal entre nós consecutivos
const FIT_PAD = 60
const EDGE_GAP = 8   // afastamento da linha em relação ao centro do handle (evita invadir o círculo)
const MOVE_TOLERANCE = 3 // distância (px, espaço do mundo) para distinguir clique de arraste

// 8 âncoras por nó (fração da largura/altura + normal de saída da curva).
const ANCHORS = {
    l:  { fx: 0,    fy: 0.5, nx: -1, ny: 0 },
    r:  { fx: 1,    fy: 0.5, nx: 1,  ny: 0 },
    t:  { fx: 0.5,  fy: 0,   nx: 0,  ny: -1 },
    b:  { fx: 0.5,  fy: 1,   nx: 0,  ny: 1 },
    tl: { fx: 0.25, fy: 0,   nx: 0,  ny: -1 }, // intermediária topo
    tr: { fx: 0.75, fy: 0,   nx: 0,  ny: -1 }, // intermediária topo
    bl: { fx: 0.25, fy: 1,   nx: 0,  ny: 1 },  // intermediária base
    br: { fx: 0.75, fy: 1,   nx: 0,  ny: 1 },  // intermediária base
}
const ANCHOR_KEYS = Object.keys(ANCHORS)
// Lados que ganham uma porta de ligação no bloco (as 4 âncoras principais —
// as intermediárias do topo/base existem só pra grudar ponta de seta).
const ANCHOR_SIDES = ['t', 'r', 'b', 'l']

// Paleta de cor de bloco (mesma lógica do mapa mental de referência: presets
// + cor personalizada) e famílias de fonte selecionáveis por bloco. Tons bem
// claros de propósito (2026-07-28: a paleta anterior tinha cores fortes
// demais) + branco puro como primeira opção — o texto permanece escuro em
// todas (ver `textColorFor()`), já que a luminância de qualquer uma delas é
// alta.
const PALETTE = ['#FFFFFF', '#E9EDFB', '#E6F1FC', '#E3F4EA', '#FCF1D4', '#FBE7EC', '#EFE7FB', '#EDF1F5']
const FONTS = {
    sans: "'Space Grotesk', 'Inter', system-ui, sans-serif",
    serif: "Georgia, 'Times New Roman', serif",
    mono: "ui-monospace, 'SF Mono', Menlo, Consolas, monospace",
}
// `sm` é o tamanho de hoje (13px, ver `.ak-viz-node` no CSS) — mantido aqui
// como valor explícito (em vez de "ausente = padrão do CSS") pra caber no
// mesmo padrão do <select> de fonte, com um valor sempre selecionado.
const FONT_SIZES = { sm: '13px', md: '15px', lg: '17px' }

// Cores padrão de uma raia nova (ciclo, uma por `lanes.length` no momento da
// criação) e tamanho inicial (px de mundo) — ambos só sugestões, editáveis
// depois arrastando a raia/suas alças, ou pelo painel "Raias". Mesma lista
// serve os dois seletores de cor (corpo e cabeçalho, ver
// `buildLaneSwatches()`/`buildLaneHeaderSwatches()`) — preto/branco/bege/
// cinza cobrem os casos neutros que as 6 cores "de marca" originais não
// cobriam (um cabeçalho preto/branco puro, por exemplo).
const LANE_COLORS = ['#2F6FED', '#7C3AED', '#16A34A', '#EA580C', '#DB2777', '#0891B2', '#000000', '#FFFFFF', '#E8DCC4', '#9CA3AF']
const LANE_DEFAULT_WIDTH = 420
const LANE_DEFAULT_HEIGHT = 240
// Tamanho mínimo/máximo (px de mundo) de uma raia em qualquer dimensão —
// mesmo clamp aplicado tanto ao redimensionar arrastando uma alça
// (`drag.type === 'lane-resize'`) quanto na validação do servidor
// (`SaveIntegrationLayoutRequest`).
const LANE_MIN_SIZE = 100
const LANE_MAX_SIZE = 6000

// Anotação básica ("post-it") — largura fixa, sem redimensionar (ver
// `rebuildNotes()`); a altura cresce sozinha com o texto
// (`contenteditable`), então não há um valor fixo equivalente pra ela.
const NOTE_DEFAULT_WIDTH = 190
const NOTE_MIN_HEIGHT = 90

// Estilo padrão de uma raia nova — cantos retos, borda sólida, preenchimento
// liso, orientação horizontal (etiqueta vertical na borda esquerda, como
// sempre foi), etiqueta visível e texto pequeno. Uma raia salva antes de
// qualquer um destes campos existir não traz a chave (`applyLayout()` faz o
// backfill lendo este mesmo objeto), então mudar um destes defaults muda
// também a leitura de raias antigas — não faça isso sem pensar na
// retrocompatibilidade. `headerColor` fica de fora deste objeto de
// propósito: ausente (não `null` explícito) é o sinal de "ainda não
// customizado" que `laneHeaderColor()` usa pra decidir entre o valor
// explícito e o escurecido automático da cor do corpo — colocá-lo aqui como
// `null` fixo funcionaria igual, mas o objeto já teria a chave, obscurecendo
// esse contrato.
const LANE_STYLE_DEFAULTS = {
    rounded: false,
    dashed: false,
    opacity: 0.08,
    orientation: 'horizontal',
    showTitle: true,
    fontSize: 'sm',
}
// Faixa do slider de opacidade — mesmo range validado em
// `SaveIntegrationLayoutRequest`.
const LANE_OPACITY_MIN = 0.03
const LANE_OPACITY_MAX = 0.5
// Tamanhos de texto do rótulo da raia — escala própria (menor que a dos
// blocos, `FONT_SIZES`), já que a etiqueta é uma faixa estreita; `sm` (11px)
// é o tamanho de sempre, mantido como default explícito pela mesma razão de
// `FONT_SIZES` acima.
const LANE_FONT_SIZES = { sm: '11px', md: '13px', lg: '15px' }

// ── modo apresentação — bolinhas viajando pelas setas ───────────────────
// Até 5 bolinhas simultâneas, uma por "ramo" do fluxo — ver
// `computePresentationPaths()`. Paleta vibrante e bem espalhada no círculo
// cromático (roxo, o lima da própria marca, amarelo, laranja, ciano) pra
// que as 5 bolinhas fiquem sempre fáceis de distinguir entre si — separada
// de `LANE_COLORS` de propósito, já que ali a cor precisa combinar com o
// fundo translúcido de uma raia inteira, enquanto aqui é só um pontinho
// brilhante sobre a aresta. Teto de segurança contra ciclo patológico
// (nunca deve ser atingido na prática — a proteção de ciclo de verdade é
// por nó já visitado NO MESMO caminho, não por contagem).
const PRESENT_MAX_PATHS = 5
const PRESENT_HARD_CAP_EDGES = 200
const PRESENT_DOT_COLORS = ['#A855F7', '#AADB1E', '#FACC15', '#FB923C', '#22D3EE']

// ── Exportar diagrama (imagem/GIF) — ver `captureDiagramCanvas()` ──────────
// Recorta exatamente ao redor do conteúdo (nós ∪ raias), nunca ao viewport
// aberto no navegador — é isso que evita a "moldura" de espaço em branco que
// `fit()` deixa de propósito (letterbox contain, pensado pra edição, onde o
// viewport tem lá seu próprio formato). `EXPORT_LONG_SIDE` é o lado mais
// comprido da imagem final; o outro lado é derivado da proporção real do
// conteúdo, então a saída SEMPRE preenche o quadro por completo.
const EXPORT_PAD = 48
const EXPORT_LONG_SIDE = 1600
// Frames capture back-to-back — no artificial delay between them (see
// exportVideo()); real capture time already dwarfs any inter-frame wait
// worth imposing (measured 2026-08-03: ~550-900ms per frame, mostly the DOM
// clone + serialize step — NOT pixel count, confirmed by timing 1600px vs
// 1100px captures directly: barely different). `EXPORT_GIF_LONG_SIDE`
// (smaller than the still PNG's `EXPORT_LONG_SIDE`) buys a modest amount of
// that back on the rasterize/decode step, but the real lever for "more
// frames" is `EXPORT_GIF_SECONDS` — this is architecturally a slow,
// per-frame-DOM-clone capture, not a real-time recorder, so there's a hard
// floor on frame RATE; the only way to get more frames is more total time.
const EXPORT_GIF_LONG_SIDE = 1100
const EXPORT_GIF_SECONDS = 14
// 1×1 transparent PNG — `html-to-image`'s own fallback for a broken `<img>`
// (logo file missing/404) is `imagePlaceholder || ''`, and an EMPTY `src` is
// a real browser trap: `<img src="">` resolves to the CURRENT page URL and
// tries to load the HTML document itself as an image, which fails and takes
// the whole capture down with it (confirmed via a broken Solution logo in
// this exact diagram — `err.target.src` came back as this page's own URL).
// Passing a real, valid placeholder avoids that trap entirely.
const EXPORT_IMAGE_PLACEHOLDER = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='

// Screenshot "look" presets — a deliberately narrow set of CSS-only overrides
// (canvas background + edge/marker/pill color, nothing else) applied for the
// duration of a single capture via a `data-viz-preset` attribute set on
// `world`/the edges `<svg>` right before `toCanvas()` and removed right
// after (see `captureDiagramCanvas()`) — the matching rules live in the
// component's outer `<style>` (nodes, real HTML — computed style gets copied
// per-element regardless of external stylesheet) and in the edges SVG's OWN
// internal `<style>` (nested SVGs are raw-cloned wholesale — see that
// block's comment). Deliberately does NOT touch font, font-size, padding, or
// anything else that affects layout/wrapping: a prior attempt to get this
// same variety by sending the exported PNG to Gemini for a visual "restyle"
// reliably garbled small text ("SAP S/4HANA" → "SAM4AMA", "AllStrategy" →
// "AllSnatag") since it's a generative model re-drawing pixels, not a
// stylesheet — removed 2026-08-03. A CSS-only swap can never do that: the
// same DOM, the same box model, just different colors.
// Only `bg` — export's flat canvas fill (`toCanvas()`'s `backgroundColor`
// option, a plain color, no gradient support). Edge/marker/pill/node-shadow
// colors for each theme live directly in this component's CSS (the outer
// <style> for nodes, the edges <svg>'s own internal <style> for
// edges/markers/pills) — kept here only where JS genuinely needs the value
// (this one, since a detached export clone has no `.ak-viz-viewport` to read
// a computed background from). `corporativo`'s live canvas is actually a
// subtle gradient (see `.ak-viz-viewport[data-viz-preset="corporativo"]`) —
// canvas fills can't do gradients here, so this approximates its overall tone.
const EXPORT_PRESETS = {
    original:    { bg: '#F7F9FC' },
    casual:      { bg: '#FFF7ED' },
    corporativo: { bg: '#F5F8F6' },
    tech:        { bg: '#132A45' },
}

// Descobre até `PRESENT_MAX_PATHS` caminhos no grafo livre da chain, um por
// ramificação — função pura, sem tocar em DOM/estado do módulo, só em
// `graph.nodes`/`graph.edges` (mesmo formato de `graphRef`). Cada aresta
// contribui uma única direção de "saída" (`outgoing[node]`): `'->'` sai de
// `from`; `'<-'` sai de `to` (a caminhada amostra o `<path>` de trás pra
// frente — ver `reversed` no consumidor); `'<->'` só sai de `from`, nunca
// cria a entrada reversa — assim uma ligação bidirecional é percorrida numa
// única direção, por no máximo uma bolinha, sem precisar de exclusão
// nenhuma depois. Raiz = nó sem nenhuma entrada nesse mesmo sentido; uma
// raiz sem NENHUMA saída (nó isolado) é ignorada — não haveria o que animar,
// e não vale gastar uma das 5 vagas com ela.
//
// Fila FIFO de "sementes" (`{startNode, forcedEdge}`): as raízes entram
// primeiro, em ordem de índice — cada caminhada segue sempre a saída de
// MENOR índice do nó atual, e a primeira vez que QUALQUER caminhada passa
// por um nó com 2+ saídas, as demais saem como sementes novas no fim da
// fila (`branchSpawned`, global — um nó de merge não gera ramos duplicados
// só porque um segundo caminho também passou por ele depois). Isso também
// dá a ordem de descoberta "por ramificação, largura primeiro" pedida: os
// ramos do primeiro caminho vêm antes dos ramos do segundo.
//
// Proteção de ciclo: cada caminhada tem seu próprio `visited` (nós); ao
// tentar avançar para um nó já visitado NESSA caminhada, a aresta que fecha
// o ciclo ainda entra na lista — é ela que faz a volta da bolinha ler como
// um loop contínuo de verdade — e a caminhada para ali (não greda em loop
// infinito reprocessando o mesmo trecho).
function computePresentationPaths(graph) {
    const nodeCount = graph?.nodes?.length || 0
    const edgeList = graph?.edges || []
    if (!nodeCount || !edgeList.length) return []

    const outgoing = Array.from({ length: nodeCount }, () => [])
    const hasIncoming = new Array(nodeCount).fill(false)
    edgeList.forEach((edge, i) => {
        const arrow = edge.arrow || '->'
        if (arrow === '->' || arrow === '<->') {
            outgoing[edge.from]?.push({ edgeIndex: i, to: edge.to, reversed: false })
            hasIncoming[edge.to] = true
        } else if (arrow === '<-') {
            outgoing[edge.to]?.push({ edgeIndex: i, to: edge.from, reversed: true })
            hasIncoming[edge.from] = true
        }
    })

    const queue = []
    for (let n = 0; n < nodeCount; n++) {
        if (!hasIncoming[n] && outgoing[n].length > 0) queue.push({ startNode: n, forcedEdge: null })
    }

    const branchSpawned = new Set()
    const paths = []
    let qi = 0
    while (qi < queue.length && paths.length < PRESENT_MAX_PATHS) {
        const { startNode, forcedEdge } = queue[qi++]
        const visited = new Set([startNode])
        const pathEdges = []
        let current = startNode
        let firstStep = forcedEdge

        while (pathEdges.length < PRESENT_HARD_CAP_EDGES) {
            const opts = outgoing[current]
            let step
            if (firstStep) {
                step = firstStep
                firstStep = null
            } else {
                if (opts.length === 0) break // beco sem saída
                step = opts[0]
            }
            if (opts.length > 1 && !branchSpawned.has(current)) {
                branchSpawned.add(current)
                opts.forEach((o) => { if (o !== step) queue.push({ startNode: current, forcedEdge: o }) })
            }

            const closesCycle = visited.has(step.to)
            pathEdges.push({ edgeIndex: step.edgeIndex, reversed: step.reversed })
            if (closesCycle) break
            visited.add(step.to)
            current = step.to
        }

        if (pathEdges.length > 0) paths.push({ startNode, edges: pathEdges })
    }

    return paths.map((p, k) => ({ ...p, color: PRESENT_DOT_COLORS[k % PRESENT_DOT_COLORS.length] }))
}

// Nós sem NENHUMA aresta tocando-os (nem `from` nem `to`, de qualquer
// `arrow`) nunca entram em `computePresentationPaths()` — não há bolinha
// que algum dia os alcance, então não faz sentido deixá-los esperando o
// "sweep" de segurança de `onDotFirstLoopComplete()` (que só dispara depois
// de TODA bolinha fechar sua 1ª volta, o que pode demorar). Função pura, à
// parte de `computePresentationPaths()` porque a regra é outra: aqui é só
// grau zero, sem nenhuma noção de caminho/direção.
function computeIsolatedNodes(graphRef) {
    const nodeCount = graphRef?.nodes?.length || 0
    const connected = new Array(nodeCount).fill(false)
    ;(graphRef?.edges || []).forEach((edge) => {
        connected[edge.from] = true
        connected[edge.to] = true
    })
    return connected.flatMap((isConnected, i) => (isConnected ? [] : [i]))
}

function luminance(hex) {
    const h = hex.replace('#', '')
    const r = parseInt(h.substr(0, 2), 16) / 255
    const g = parseInt(h.substr(2, 2), 16) / 255
    const b = parseInt(h.substr(4, 2), 16) / 255
    return 0.2126 * r + 0.7152 * g + 0.0722 * b
}
const textColorFor = (hex) => (luminance(hex) < 0.55 ? '#FFFFFF' : '#1A1A2E')
const isHex = (v) => typeof v === 'string' && /^#[0-9a-f]{6}$/i.test(v)
function hexToRgba(hex, alpha) {
    const h = hex.replace('#', '')
    const r = parseInt(h.substr(0, 2), 16)
    const g = parseInt(h.substr(2, 2), 16)
    const b = parseInt(h.substr(4, 2), 16)
    return `rgba(${r}, ${g}, ${b}, ${alpha})`
}
// Mesma cor, um tom mais escuro — o AUTOMÁTICO do cabeçalho da raia (a
// faixa/etiqueta com o título) quando o usuário nunca escolheu uma cor de
// cabeçalho própria, ver `laneHeaderColor()` logo abaixo.
function darkenHex(hex, amount) {
    const h = hex.replace('#', '')
    const scale = (v) => Math.max(0, Math.min(255, Math.round(v * (1 - amount))))
    const r = scale(parseInt(h.substr(0, 2), 16))
    const g = scale(parseInt(h.substr(2, 2), 16))
    const b = scale(parseInt(h.substr(4, 2), 16))
    return `#${[r, g, b].map((v) => v.toString(16).padStart(2, '0')).join('')}`
}

// `background` CSS do CORPO de uma raia — sempre sólido (a cor com alpha na
// opacidade escolhida); os padrões diagonal/trançado que existiam aqui foram
// removidos (só sólido agora), ver `LANE_STYLE_DEFAULTS`.
function laneBackgroundCss(lane) {
    const opacity = Number.isFinite(lane.opacity) ? lane.opacity : LANE_STYLE_DEFAULTS.opacity
    return hexToRgba(lane.color, opacity)
}

// Cor do CABEÇALHO (etiqueta/faixa com o título) — independente da cor do
// corpo (`lane.color`) quando o usuário escolheu uma explicitamente
// (`lane.headerColor`, `setLaneHeaderColor()`); sem escolha explícita, cai
// automaticamente pro escurecido de sempre da cor do corpo, então uma raia
// que nunca mexeu nisso continua com a aparência de sempre.
function laneHeaderColor(lane) {
    return isHex(lane.headerColor) ? lane.headerColor : darkenHex(lane.color, 0.35)
}

// Avatar do bloco: logo da solução, ou (sem logo) um badge com a inicial do
// nome — mesmo fallback do catálogo (`x-ui.logo`), refeito aqui em DOM puro
// porque os nós do data-viz não passam por Blade. Em bloco de decisão/ator,
// o lugar do logo é ocupado pelo ícone do tipo (`data.icon`, heroicon já
// renderizado no servidor — ver `ChainNodeKind::icon()`).
function buildAvatar(data) {
    const avatar = document.createElement('span')
    avatar.className = 'ak-viz-node-avatar'
    if (data.logo) {
        const img = document.createElement('img')
        img.src = data.logo
        img.alt = ''
        avatar.appendChild(img)
    } else {
        avatar.classList.add('is-fallback')
        avatar.textContent = (data.label ?? '').trim().charAt(0).toUpperCase() || '?'
    }
    return avatar
}

function buildKindIcon(icon) {
    const avatar = document.createElement('span')
    avatar.className = 'ak-viz-node-avatar is-kind'
    avatar.innerHTML = icon
    return avatar
}

// 4 portas de ligação por bloco (topo/direita/base/esquerda) — o "puxe uma
// seta daqui". São filhas do nó (acompanham posição/tamanho sem conta
// nenhuma) e não têm listener próprio: `startNodePointer()` reconhece o
// `[data-viz-port]` no alvo do mousedown e inicia o arraste de ligação em vez
// do arraste do bloco. Visíveis só no hover/seleção e só quando editável (CSS).
function buildPorts(el) {
    ANCHOR_SIDES.forEach((side) => {
        const port = document.createElement('span')
        port.className = 'ak-viz-port is-' + side
        port.setAttribute('data-viz-port', side)
        port.title = 'Arraste até outro bloco para criar uma ligação'
        el.appendChild(port)
    })
}

// (Re)desenha o conteúdo de um bloco a partir dos dados resolvidos do nó —
// usado tanto ao montar o grafo inteiro (`render()`) quanto após editar o
// título de um nó pontualmente (`applyNodeData()`), para as duas rotas nunca
// divergirem na montagem do DOM do bloco.
function paintNode(el, data) {
    const kind = data.kind || 'system'
    // Só um bloco de sistema com uma Solução cadastrada (logo real) pode
    // virar "somente logo" — texto livre/decisão/ator não têm imagem
    // nenhuma pra mostrar sozinha, e uma Solução sem logo cairia no
    // fallback de inicial, que não faz sentido "sozinho" no lugar do cartão.
    const logoOnly = kind === 'system' && !!data.solution && !!data.logo && !!data.logoOnly
    // `is-free` (tracejado de "externo à Leo") é só do bloco de sistema sem
    // Solução — decisão/ator/início/fim têm forma/cor próprias, ver as
    // classes abaixo.
    el.classList.toggle('is-free', kind === 'system' && !data.solution)
    el.classList.toggle('is-decision', kind === 'decision')
    el.classList.toggle('is-actor', kind === 'actor')
    el.classList.toggle('is-start', kind === 'start')
    el.classList.toggle('is-end', kind === 'end')
    el.classList.toggle('is-image', kind === 'image')
    el.classList.toggle('is-logo-only', logoOnly)
    el.classList.toggle('has-comment', !!data.comment)
    el.classList.toggle('is-dashed', !!data.dashed)
    el.innerHTML = ''

    // "Somente logo": o cartão inteiro (avatar + nome) some, sobra só a
    // imagem da Solução em tamanho real — mesmo espírito de uma imagem
    // colada (`kind === 'image'` acima), mas aqui é uma Solução do catálogo,
    // não uma mídia própria do nó.
    if (logoOnly) {
        const img = document.createElement('img')
        img.src = data.logo
        img.alt = data.label || ''
        el.appendChild(img)

        const badge = document.createElement('span')
        badge.className = 'ak-viz-comment-badge'
        el.appendChild(badge)

        buildPorts(el)
        return
    }

    // Imagem colada (Ctrl+V): só a própria imagem, sem avatar/rótulo — o
    // conteúdo já É a imagem. Continua um bloco como qualquer outro (porta,
    // badge de comentário), então pode enviar/receber setas normalmente.
    // `data.mediaUrl` ausente (mídia removida por fora, ou um nó `image` mal
    // formado) cai num quadro vazio com o ícone de fallback em vez de quebrar.
    if (kind === 'image') {
        if (data.mediaUrl) {
            const img = document.createElement('img')
            img.src = data.mediaUrl
            img.alt = data.label || 'Imagem'
            img.draggable = false
            el.appendChild(img)
        } else {
            const fallback = document.createElement('span')
            fallback.className = 'ak-viz-node-image-fallback'
            if (data.icon) fallback.innerHTML = data.icon
            el.appendChild(fallback)
        }

        const badge = document.createElement('span')
        badge.className = 'ak-viz-comment-badge'
        el.appendChild(badge)

        buildPorts(el)
        return
    }

    // Início/Fim: só o ícone dentro do círculo sólido + o rótulo escrito
    // ABAIXO dele (`.ak-viz-node-endcap-label`), nunca ao lado — layout
    // totalmente diferente dos demais tipos, ver CSS (`.is-start`/`.is-end`).
    if (kind === 'start' || kind === 'end') {
        if (data.icon) el.appendChild(buildKindIcon(data.icon))
        const label = document.createElement('span')
        label.className = 'ak-viz-node-endcap-label'
        label.textContent = data.label ?? (kind === 'start' ? 'Início' : 'Fim')
        el.appendChild(label)

        const badge = document.createElement('span')
        badge.className = 'ak-viz-comment-badge'
        el.appendChild(badge)

        buildPorts(el)
        return
    }

    // Corpo do bloco: avatar (logo da solução, ou inicial do nome quando não
    // há logo; ícone do tipo em decisão/ator) + nome. Nó de sistema em texto
    // livre não tem avatar nenhum.
    const body = document.createElement('div')
    body.className = 'ak-viz-node-body'
    if (data.solution) body.appendChild(buildAvatar(data))
    else if (data.icon) body.appendChild(buildKindIcon(data.icon))
    const text = document.createElement('span')
    text.className = 'ak-viz-node-text'
    text.textContent = data.label ?? '?'
    body.appendChild(text)
    el.appendChild(body)

    const badge = document.createElement('span')
    badge.className = 'ak-viz-comment-badge'
    el.appendChild(badge)

    buildPorts(el)
}

const mounted = new WeakSet()
const roots = new Set()
const savedLayouts = new Map() // slug -> último layout salvo na sessão (mantém consistência sem reload)
let uidCounter = 0
let solutionsListCache = null // [{id,name}] — lido uma vez de [data-ak-solutions] (integration-workspace.blade.php)
let protocolsListCache = null // [{value,label}] — lido uma vez de [data-ak-protocols] (integration-workspace.blade.php)
let kindsListCache = null // [{value,label,system,placeholder}] — lido uma vez de [data-ak-node-kinds] (integration-workspace.blade.php)

function getSolutionsList() {
    if (solutionsListCache) return solutionsListCache
    const raw = document.querySelector('[data-ak-solutions]')?.getAttribute('data-ak-solutions')
    try {
        solutionsListCache = raw ? JSON.parse(raw) : []
    } catch {
        solutionsListCache = []
    }
    return solutionsListCache
}

function getProtocolsList() {
    if (protocolsListCache) return protocolsListCache
    const raw = document.querySelector('[data-ak-protocols]')?.getAttribute('data-ak-protocols')
    try {
        protocolsListCache = raw ? JSON.parse(raw) : []
    } catch {
        protocolsListCache = []
    }
    return protocolsListCache
}

// Tipos de bloco (`App\Enums\ChainNodeKind`) — resolvidos no servidor, nunca
// hardcoded aqui: `system` é o único que aceita Solução cadastrada, e cada
// tipo traz o placeholder do input de texto livre.
function getNodeKindsList() {
    if (kindsListCache) return kindsListCache
    const raw = document.querySelector('[data-ak-node-kinds]')?.getAttribute('data-ak-node-kinds')
    try {
        kindsListCache = raw ? JSON.parse(raw) : []
    } catch {
        kindsListCache = []
    }
    return kindsListCache
}

function nodeKind(value) {
    const kinds = getNodeKindsList()
    return kinds.find((k) => k.value === value) ?? kinds.find((k) => k.system) ?? { value: 'system', system: true, placeholder: '' }
}

export function init() {
    document.querySelectorAll('[data-integration-viz]').forEach(mount)
}

document.addEventListener('ak:integration-selected', (e) => {
    roots.forEach((root) => root.__akVizRender?.(e.detail?.graph ?? null, e.detail?.name ?? '', e.detail?.slug ?? ''))
})

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
}

// ── markdown (parser enxuto, sem dependências) ────────────────────
function escapeHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

// escapeHtml() alone doesn't touch quotes, which is fine for HTML content but
// not for a value going inside a quoted attribute — a literal `"` in a
// user-authored comment (e.g. an image alt text) would otherwise break out of
// `src="…"`/`alt="…"` and inject arbitrary attributes.
function escapeAttr(s) {
    return escapeHtml(s).replace(/"/g, '&quot;')
}

// Blocks `javascript:`/`data:`/`vbscript:` etc. in a comment's link/image
// target — only http(s) and root-relative/hash URLs render as a real link;
// anything else falls back to `#` rather than executing on click.
function safeUrl(u) {
    return /^(https?:\/\/|\/|#)/i.test(u) ? u : '#'
}

function mdInline(s) {
    const codes = []
    s = s.replace(/`([^`]+)`/g, (m, c) => { codes.push(c); return '\x00' + (codes.length - 1) + '\x00' })
    s = s.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, (m, a, u) => `<img src="${escapeAttr(safeUrl(u))}" alt="${escapeAttr(a)}" style="max-width:100%;border-radius:6px">`)
    s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (m, t, u) => `<a href="${escapeAttr(safeUrl(u))}" target="_blank" rel="noopener">${t}</a>`)
    s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    s = s.replace(/__([^_]+)__/g, '<strong>$1</strong>')
    s = s.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>')
    s = s.replace(/(^|[^\w])_([^_\n]+)_/g, '$1<em>$2</em>')
    s = s.replace(/~~([^~]+)~~/g, '<del>$1</del>')
    s = s.replace(/\x00(\d+)\x00/g, (m, i) => `<code>${codes[+i]}</code>`)
    return s
}

function renderMarkdown(src) {
    if (!src || !src.trim()) return '<p class="md-empty">Sem comentário ainda.</p>'
    src = src.replace(/\r\n/g, '\n')
    const blocks = []
    src = src.replace(/```([\s\S]*?)```/g, (m, code) => { blocks.push(code.replace(/^\n/, '').replace(/\n$/, '')); return '\x01' + (blocks.length - 1) + '\x01' })
    const lines = src.split('\n')
    let html = ''
    let i = 0
    const isSpecial = (ln) => /^\x01\d+\x01$/.test(ln) || /^(#{1,6})\s/.test(ln) || /^\s*>/.test(ln) || /^\s*[-*+]\s/.test(ln) || /^\s*\d+\.\s/.test(ln) || /^\s*([-*_])\1\1+\s*$/.test(ln) || /^\s*$/.test(ln)
    while (i < lines.length) {
        const line = lines[i]
        const cm = line.match(/^\x01(\d+)\x01$/)
        if (cm) { html += `<pre><code>${escapeHtml(blocks[+cm[1]])}</code></pre>`; i++; continue }
        if (/^\s*$/.test(line)) { i++; continue }
        const h = line.match(/^(#{1,6})\s+(.*)$/)
        if (h) { const lv = h[1].length; html += `<h${lv}>${mdInline(escapeHtml(h[2]))}</h${lv}>`; i++; continue }
        if (/^\s*([-*_])\1\1+\s*$/.test(line)) { html += '<hr>'; i++; continue }
        if (/^\s*>/.test(line)) {
            const buf = []
            while (i < lines.length && /^\s*>/.test(lines[i])) { buf.push(lines[i].replace(/^\s*>\s?/, '')); i++ }
            html += `<blockquote>${renderMarkdown(buf.join('\n'))}</blockquote>`
            continue
        }
        if (/^\s*[-*+]\s+/.test(line)) {
            const items = []
            while (i < lines.length && /^\s*[-*+]\s+/.test(lines[i])) { items.push(lines[i].replace(/^\s*[-*+]\s+/, '')); i++ }
            html += '<ul>' + items.map((x) => `<li>${mdInline(escapeHtml(x))}</li>`).join('') + '</ul>'
            continue
        }
        if (/^\s*\d+\.\s+/.test(line)) {
            const items = []
            while (i < lines.length && /^\s*\d+\.\s+/.test(lines[i])) { items.push(lines[i].replace(/^\s*\d+\.\s+/, '')); i++ }
            html += '<ol>' + items.map((x) => `<li>${mdInline(escapeHtml(x))}</li>`).join('') + '</ol>'
            continue
        }
        const para = []
        while (i < lines.length && !isSpecial(lines[i])) { para.push(lines[i]); i++ }
        html += `<p>${mdInline(escapeHtml(para.join('\n'))).replace(/\n/g, '<br>')}</p>`
    }
    return html
}

function mount(root) {
    if (mounted.has(root)) return
    mounted.add(root)
    roots.add(root)

    const stage = root.querySelector('[data-viz-stage]')
    const viewport = root.querySelector('[data-viz-viewport]')
    const world = root.querySelector('[data-viz-world]')
    const edges = root.querySelector('[data-viz-edges]')
    const empty = root.querySelector('[data-viz-empty]')
    const emptyTitle = root.querySelector('[data-viz-empty-title]')
    const emptyHint = root.querySelector('[data-viz-empty-hint]')
    const zoomLabel = root.querySelector('[data-viz-zoom-label]')
    const markerEnd = root.querySelector('[data-viz-marker-end]')
    const markerStart = root.querySelector('[data-viz-marker-start]')
    const saveBtn = root.querySelector('[data-viz-save]')
    const saveSep = root.querySelector('[data-viz-save-sep]')
    const saveLabel = root.querySelector('[data-viz-save-label]')
    // Nome da integração desenhada agora — o canvas não o EXIBE mais (a barra
    // superior da página faz isso, com o status junto), mas o rótulo do estado
    // vazio ainda o usa, e o re-render de `removeNode()` precisa dele sem
    // depender de lê-lo de volta do DOM.
    let currentName = ''
    const organizeBtn = root.querySelector('[data-viz-organize]')
    const addNodeBtn = root.querySelector('[data-viz-add-node]')
    const lanesBtn = root.querySelector('[data-viz-lanes]')
    const notesBtn = root.querySelector('[data-viz-add-note]')
    const presentToggleBtn = root.querySelector('[data-viz-present-toggle]')
    const presentIconStart = root.querySelector('[data-viz-present-icon-start]')
    const presentIconStop = root.querySelector('[data-viz-present-icon-stop]')
    const presentSpeedWrap = root.querySelector('[data-viz-present-speed-wrap]')
    const presentSpeedSelect = root.querySelector('[data-viz-present-speed]')
    const exportToggleBtn = root.querySelector('[data-viz-export-toggle]')
    const exportPngBtn = root.querySelector('[data-viz-export-png]')
    const exportGifBtn = root.querySelector('[data-viz-export-gif]')
    const exportStatus = root.querySelector('[data-viz-export-status]')
    const themeSelect = root.querySelector('[data-viz-theme]')
    const laneToolbar = root.querySelector('[data-viz-lane-toolbar]')
    const laneToolbarSwatches = root.querySelector('[data-viz-lane-toolbar-swatches]')
    const laneToolbarHeaderSwatches = root.querySelector('[data-viz-lane-toolbar-header-swatches]')
    const laneToolbarRemove = root.querySelector('[data-viz-lane-toolbar-remove]')
    const laneToolbarRoundedBtn = root.querySelector('[data-viz-lane-toolbar-rounded]')
    const laneToolbarRoundedIcon = root.querySelector('[data-viz-lane-toolbar-rounded-icon]')
    const laneToolbarDashedBtn = root.querySelector('[data-viz-lane-toolbar-dashed]')
    const laneToolbarOrientationBtns = root.querySelectorAll('[data-viz-lane-toolbar-orientation]')
    const laneToolbarFontSize = root.querySelector('[data-viz-lane-toolbar-font-size]')
    const laneToolbarOpacity = root.querySelector('[data-viz-lane-toolbar-opacity]')
    // O componente `<x-forms.toggle>` renderiza o `<input type=checkbox>` real
    // DENTRO do `<label>` que recebe `data-viz-lane-toolbar-title` (o
    // `$attributes` do componente só chega no elemento raiz) — por isso o
    // hook aponta pro wrapper, e o checkbox de verdade se pega com
    // `.querySelector('input')` nele, mesma ideia de `viz-text-color-input`
    // ter um `id` próprio além do `data-viz-text-color` do componente.
    const laneToolbarTitleWrap = root.querySelector('[data-viz-lane-toolbar-title]')
    const laneToolbarTitleInput = laneToolbarTitleWrap?.querySelector('input') ?? null
    const addEditor = root.querySelector('[data-viz-add-editor]')
    const addKindIcons = root.querySelector('[data-viz-add-kind-icons]')
    const bottomBar = root.querySelector('[data-viz-bottombar]')
    const toolbar = root.querySelector('[data-viz-toolbar]')
    const toolbarStyle = root.querySelector('[data-viz-toolbar-style]')
    // Segunda linha: tracejado, borda leve de imagem/"somente logo"
    // (condicionais), tipo do bloco (ícones — ver `refreshKindRow()`/
    // `changeNodeKind()`) e as ações (comentário/excluir), tudo junto.
    const toolbarRow2 = root.querySelector('[data-viz-toolbar-row2]')
    const toolbarSwatches = root.querySelector('[data-viz-swatches]')
    const toolbarCustomColor = root.querySelector('[data-viz-custom-color]')
    const toolbarTextColor = root.querySelector('[data-viz-text-color]')
    const toolbarTextColorWrap = root.querySelector('[data-viz-text-color-wrap]')
    const toolbarFont = root.querySelector('[data-viz-font]')
    const toolbarFontSize = root.querySelector('[data-viz-font-size]')
    const toolbarDashedBtn = root.querySelector('[data-viz-toolbar-dashed]')
    const toolbarImageBorderWrap = root.querySelector('[data-viz-toolbar-image-border]')
    const toolbarImageBorderToggle = root.querySelector('[data-viz-toolbar-image-border-toggle]')
    const toolbarImageBorderColor = root.querySelector('[data-viz-image-border-color]')
    const toolbarLogoOnlyWrap = root.querySelector('[data-viz-toolbar-logo-only]')
    const toolbarLogoOnlyToggle = root.querySelector('[data-viz-toolbar-logo-only-toggle]')
    const toolbarComment = root.querySelector('[data-viz-toolbar-comment]')
    const toolbarRenameBtn = root.querySelector('[data-viz-toolbar-rename]')
    const toolbarRemoveBtn = root.querySelector('[data-viz-toolbar-remove]')
    const toolbarRemoveSep = root.querySelector('[data-viz-toolbar-remove-sep]')
    const toolbarKindRow = root.querySelector('[data-viz-toolbar-kind]')
    const toolbarKindIcons = root.querySelector('[data-viz-toolbar-kind-icons]')
    const protocolEditor = root.querySelector('[data-viz-protocol-editor]')
    const protocolArrowLeft = root.querySelector('[data-viz-protocol-arrow-left]')
    const protocolArrowRight = root.querySelector('[data-viz-protocol-arrow-right]')
    const protocolDashedBtn = root.querySelector('[data-viz-protocol-dashed]')
    const protocolDelete = root.querySelector('[data-viz-protocol-delete]')
    const sidebar = root.querySelector('[data-viz-sidebar]')
    const sidebarNode = root.querySelector('[data-viz-sidebar-node]')
    const sidebarInput = root.querySelector('[data-viz-sidebar-input]')
    const sidebarPreview = root.querySelector('[data-viz-sidebar-preview]')
    const sidebarClose = root.querySelector('[data-viz-sidebar-close]')

    const uid = 'akviz' + ++uidCounter
    markerEnd.id = uid + '-end'
    markerStart.id = uid + '-start'

    const view = { x: FIT_PAD, y: FIT_PAD, scale: 1 }
    let nodes = []          // { label, solution, url, comment, logo, environment, cloud, el, w, h, x, y }
    let graphRef = null
    let edgeAnchors = []    // [{from, to, dashed}] por índice de edge (âncora visual — from/to aqui são anchor keys, não nós)
    let lanes = []          // [{label, color, x, y, width, height}] — raias (viz_layout.lanes), puramente visual
    let laneEls = []        // [{wrap, label, handles:{e,s,se}}] — elementos DOM das raias, paralelos a `lanes`
    let notes = []          // [{x, y, text}] — anotações "post-it" (viz_layout.notes), puramente visuais como as raias
    let noteEls = []        // [{wrap, body}] — elementos DOM das anotações, paralelos a `notes`
    let selectedLane = null // índice da raia com o toolbar (cor/nome/remover) aberto, ou null
    let creatingEdge = false // POST de ligação nova em voo — ver `appendEdgeLocally()`
    let slug = ''
    let editable = false
    let saveUrl = null
    let currentTheme = 'original' // ver applyTheme() — persistido em viz_layout.theme, ao vivo no canvas E no export
    // ── modo apresentação — ver `enterPresentation()`/`presentTick()` ──
    let presenting = false
    let savedEditableBeforePresenting = false // valor real de `editable` (vindo do servidor), restaurado ao sair
    let presentPaths = []             // computePresentationPaths() do graphRef atual
    let presentDots = []              // estado de execução de cada bolinha — ver startPresentAnimation()
    let presentRafId = null
    let presentLastTs = null          // timestamp do frame anterior — null força o 1º frame a ter dt=0
    let presentSpeedMultiplier = 1    // 0.5–1.5, controlado pelo <select> de velocidade
    const PRESENT_BASE_SPEED = 90      // px de mundo por segundo, em 1x
    let presentRevealedNodes = []     // bool[] por índice de nó — fadeIn é idempotente (ver revealNode())
    let presentRevealedEdges = []     // bool[] por índice de edge — mesma ideia, ver revealEdge()
    let presentFirstLoopPending = 0   // quantas bolinhas ainda não fecharam a 1ª volta
    let presentFallbackFired = false  // já revelou tudo que sobrou ao fim da 1ª volta de todas
    let drag = null         // {type:'handle'|'node', ...} — 'handle' carrega edge/end/origNode/otherNode/targetNode
    let dirty = false
    let selectedIndex = null
    let commentIndex = null
    let selectedEdge = null // índice em chain.edges com o editor de protocolo aberto
    let edgeLabelEls = []   // <g> de cada pill de protocolo desenhada no draw() atual — base p/ ancorar o input inline de edição de protocolo
    // Espelho local dos dois botões-toggle de sentido do editor de ligação
    // (`data-viz-protocol-arrow-left/right`) — `left` = cabeça de seta na
    // origem (`<-`), `right` = cabeça de seta no destino (`->`); ambos juntos
    // formam `<->`. Nunca fica com os dois desligados: '->'/'<-'/'<->' são os
    // únicos valores válidos, então `toggleArrowSide()` ignora o clique que
    // desligaria o último ativo — ver `currentArrowValue()`/`setArrowUI()`.
    let arrowState = { left: false, right: true }
    let pastingImage = false // uma imagem colada por vez — ver handlePasteImage()
    // true quando o `render()` mais recente aplicou posições SALVAS
    // (`viz_layout`) em vez de `layoutDefault()` — o `ResizeObserver` de
    // "hidden tab" abaixo só reflowa (`layoutDefault()` de novo) quando isto
    // é false; um layout salvo não é dele pra reposicionar.
    let usedCustomLayout = false
    // Preenchidos só quando o painel "Adicionar bloco" abre a partir de
    // soltar uma seta no CANVAS VAZIO (não no botão "+" da topbar) — ver
    // `openQuickAddEditor()`. `quickAddOrigin` é a porta de onde a seta
    // saiu (pra `createEdgeFrom()` depois de criar o bloco); `quickAddPos`
    // é o ponto de MUNDO onde soltar, pra o bloco novo nascer ali (em vez de
    // à direita do último bloco, como o "+" da topbar faz) — o painel em si
    // não usa esse ponto, ele sempre abre no canto fixo do canvas. Os dois
    // voltam a `null` juntos em `closeAddEditor()`.
    let quickAddOrigin = null
    let quickAddPos = null
    // Edição inline do protocolo direto no rótulo da seta
    // (`startInlineProtocolEdit()`) — `inlineProtocolInput` é o `<input>`
    // flutuante ativo (ou `null`), `inlineProtocolReposition` a função que o
    // reancora (junto da sugestão) toda vez que o canvas roda `applyView()`/
    // `draw()`, já que este input (diferente do painel de contexto da seta)
    // continua vivendo colado à própria pill, em espaço de TELA, não de
    // `world`.
    let inlineProtocolInput = null
    let inlineProtocolReposition = null
    let inlineProtocolEditIndex = null // índice do edge em edição inline, ou null — `drawProtocolPill()` esconde o texto estático dele
    // Mesma ideia pro renomear inline de uma raia (`startInlineLaneLabelEdit()`)
    // — reancora o input flutuante da etiqueta a cada `applyView()`.
    let inlineLaneLabelReposition = null

    function applyView() {
        world.style.transform = `translate(${view.x}px,${view.y}px) scale(${view.scale})`
        if (zoomLabel) zoomLabel.textContent = Math.round(view.scale * 100) + '%'
        inlineProtocolReposition?.()
        inlineLaneLabelReposition?.()
        // A raia/o bloco/a seta em si não precisam de nada aqui: são filhos
        // de `world` (espaço de mundo), então pan/zoom já os move/escala de
        // graça via a própria transform CSS acima. Os painéis de contexto
        // (toolbar do bloco, da raia, editor de protocolo) não reancoram mais
        // — são fixos no canto do `stage` (estilo excalidraw.com), então pan/
        // zoom nunca precisa movê-los.
    }

    function screenToWorld(clientX, clientY) {
        const r = viewport.getBoundingClientRect()
        return {
            x: (clientX - r.left - view.x) / view.scale,
            y: (clientY - r.top - view.y) / view.scale,
        }
    }

    function anchorPoint(node, key) {
        const a = ANCHORS[key] ?? ANCHORS.r
        return { x: node.x + node.w * a.fx, y: node.y + node.h * a.fy, nx: a.nx, ny: a.ny }
    }

    function clearWorld() {
        nodes.forEach((n) => n.el.remove())
        nodes = []
        // Raias são um estado por integração, não por sessão da página — sem
        // isto, trocar para uma integração sem raias (ou com menos) deixaria
        // raias da integração anterior penduradas em `world` —
        // `nodes.forEach(...).remove()` acima só limpa os nós, nenhum deles
        // é uma raia. `entry.wrap.remove()` basta: label/alças são filhas
        // dele, saem junto.
        laneEls.forEach((entry) => entry.wrap.remove())
        laneEls = []
        lanes = []
        noteEls.forEach((entry) => entry.wrap.remove())
        noteEls = []
        notes = []
        clearOverlays()
    }

    function clearOverlays() {
        edges.querySelectorAll('.ak-viz-edge, .ak-viz-plabel').forEach((el) => el.remove())
        world.querySelectorAll('.ak-viz-handle, .ak-viz-anchor').forEach((el) => el.remove())
    }

    function setDirty(value) {
        dirty = value
        if (saveBtn) saveBtn.disabled = !value
    }

    // Aplica o tema (Original/Casual/Corporativo/Tech) ao canvas AO VIVO —
    // não só na hora de exportar: o `data-viz-preset` fica no `world`, no
    // `edges` (a <svg>) E no `viewport` (fundo do canvas, incluindo a
    // trama de pontos — `viewport` nunca é capturado no export, que usa
    // `EXPORT_PRESETS[...].bg` direto como `backgroundColor` do `toCanvas()`,
    // mas ELE é o que o usuário vê enquanto edita, então precisa da MESMA
    // troca de cor aqui) o tempo todo enquanto esse tema estiver ativo —
    // assim as MESMAS regras CSS usadas pelo export (bloco/aresta/pill, ver o
    // <style> deste componente) já pintam a edição normal também, nada
    // precisa ser duplicado entre "vendo" e "exportando". `markDirty` é
    // `false` só ao carregar (aplicando o tema já salvo em `viz_layout.theme`
    // — mudar isso não é uma edição nova) e `true` numa escolha de verdade do
    // usuário (habilita o "Salvar", mesmo padrão de mover um bloco).
    function applyTheme(theme, { markDirty: shouldMarkDirty = true } = {}) {
        currentTheme = EXPORT_PRESETS[theme] ? theme : 'original'
        if (currentTheme === 'original') {
            delete world.dataset.vizPreset
            delete edges.dataset.vizPreset
            delete viewport.dataset.vizPreset
        } else {
            world.dataset.vizPreset = currentTheme
            edges.dataset.vizPreset = currentTheme
            viewport.dataset.vizPreset = currentTheme
        }
        if (themeSelect && themeSelect.value !== currentTheme) themeSelect.value = currentTheme
        if (shouldMarkDirty && editable) setDirty(true)
    }

    function showEmpty(name) {
        empty.style.display = ''
        refreshEditableUI()
        presentToggleBtn?.classList.add('!hidden') // sem chain carregada não há o que apresentar
        exportToggleBtn?.classList.add('!hidden') // idem — nada pra exportar
        themeSelect?.closest('[data-viz-theme-wrap]')?.classList.add('!hidden')
        currentName = name || ''
        if (emptyTitle) emptyTitle.textContent = name || 'Nenhuma integração selecionada'
        if (emptyHint) {
            emptyHint.textContent = name
                ? 'Esta integração ainda não tem uma cadeia definida.'
                : 'Escolha uma na lista para ver o diagrama.'
        }
    }

    function render(graph, name, slugArg) {
        // Trocar a integração selecionada re-renderiza esta MESMA instância
        // montada (`clearWorld()` logo abaixo destrói todo nó/aresta) — sem
        // sair da apresentação primeiro, o rAF de `presentTick()` continuaria
        // rodando contra elementos desanexados.
        if (presenting) exitPresentation()
        selectNode(null)
        closeComment()
        closeAddEditor()
        closeLaneToolbar()
        clearWorld()
        graphRef = graph
        slug = slugArg || ''
        editable = !!graph?.editable
        saveUrl = graph?.saveUrl ?? null

        if (!graph || !Array.isArray(graph.nodes) || graph.nodes.length === 0) {
            showEmpty(name)
            return
        }
        empty.style.display = 'none'
        currentName = name || ''
        refreshEditableUI()
        presentToggleBtn?.classList.remove('!hidden')
        exportToggleBtn?.classList.remove('!hidden')
        themeSelect?.closest('[data-viz-theme-wrap]')?.classList.remove('!hidden')

        graph.nodes.forEach((data, i) => {
            const el = document.createElement('div')
            el.className = 'ak-viz-node'
            paintNode(el, data)

            el.addEventListener('pointerdown', (e) => startNodePointer(e, i))
            el.addEventListener('dblclick', () => startInlineLabelEdit(i))
            world.appendChild(el)
            nodes.push({ ...data, el, w: 0, h: 0, x: 0, y: 0, color: null, textColor: null, font: 'sans', fontSize: 'sm', imageBorderColor: null })
        })
        nodes.forEach((n) => {
            n.w = n.el.offsetWidth
            n.h = n.el.offsetHeight
        })

        // Same reasoning as `appendNode()`: an image node's <img> loads
        // asynchronously, so it can still be 0×0 above — remeasure once it
        // actually loads and redo the layout that was computed from the
        // wrong size (only when no saved layout owns the positions —
        // `usedCustomLayout`, set right below; read here lazily since this
        // listener only fires later, well after that assignment runs).
        nodes.forEach((n) => {
            if (n.kind !== 'image') return
            const img = n.el.querySelector('img')
            if (!img || img.complete) return
            img.addEventListener('load', () => {
                n.w = n.el.offsetWidth
                n.h = n.el.offsetHeight
                if (!usedCustomLayout) {
                    layoutDefault()
                    nodes.forEach((m) => {
                        m.el.style.left = m.x + 'px'
                        m.el.style.top = m.y + 'px'
                    })
                }
                // Nunca redesenha em cima de uma apresentação em andamento —
                // draw() reconstrói todo <path class="ak-viz-edge">, o que
                // invalidaria os `pathEl`/`length` já cacheados pelas
                // bolinhas em voo (`startPresentAnimation()`). A imagem só
                // fica com a âncora levemente desatualizada nesse cenário
                // raro (carregamento lento + entrar na apresentação antes
                // dela terminar), o que é aceitável.
                if (!presenting) draw()
            }, { once: true })
        })

        layoutDefault()
        // Uma âncora visual por ligação (`graph.edges`), não por par consecutivo
        // de nós — a chain é um grafo livre, o número de edges é independente
        // do número de nós.
        edgeAnchors = Array.from({ length: (graph.edges || []).length }, () => ({ from: 'r', to: 'l', dashed: false }))

        const layoutToApply = savedLayouts.get(slug) ?? graph.layout
        // Mesma condição que `applyLayout()` usa por dentro pra saber se ela
        // vai SOBRESCREVER `layoutDefault()` com posições salvas — o
        // `ResizeObserver` de "hidden tab" mais abaixo usa esta MESMA
        // variável pra saber se pode reflowar (`layoutDefault()` de novo,
        // sem layout salvo em jogo) ou só remedir w/h (posições vieram do
        // `viz_layout`, não são dele pra mexer).
        usedCustomLayout = Array.isArray(layoutToApply?.nodes) && layoutToApply.nodes.length === nodes.length
        applyLayout(layoutToApply)
        // `logoOnly` só chega depois de `applyLayout()` (vem do `viz_layout`
        // salvo), mas `paintNode()` já rodou pra cada nó lá em cima, sem
        // saber disso ainda — repinta agora quem precisa, remedindo w/h
        // (o conteúdo trocou de cartão pra imagem solta).
        nodes.forEach((n) => {
            if (!n.logoOnly) return
            paintNode(n.el, n)
            n.w = n.el.offsetWidth
            n.h = n.el.offsetHeight
        })
        nodes.forEach((n) => {
            n.el.style.left = n.x + 'px'
            n.el.style.top = n.y + 'px'
            applyNodeStyle(n)
        })

        toolbarStyle?.classList.toggle('!hidden', !editable)
        toolbarRow2?.classList.toggle('!hidden', !editable)

        draw()
        setDirty(false)
        fit()
    }

    // Cor de fundo / cor de texto / fonte de um bloco — só sobrescreve o
    // padrão do tema (CSS) quando o usuário escolheu algo; `textColor` nulo
    // recalcula automaticamente pelo contraste com `color` (mesma regra do
    // mapa mental de referência).
    function applyNodeStyle(n) {
        n.el.style.background = n.color || ''
        n.el.style.color = n.textColor || (n.color ? textColorFor(n.color) : '')
        n.el.style.fontFamily = FONTS[n.font] || FONTS.sans
        n.el.style.fontSize = FONT_SIZES[n.fontSize] || FONT_SIZES.sm
        n.el.classList.toggle('is-dashed', !!n.dashed)
        // Borda leve opcional — só imagem (`viz_layout.nodes[i].imageBorderColor`);
        // guardado por `kind` pra nunca escrever um `border` inline nos
        // outros tipos, cujo contorno é só CSS de classe (`.is-dashed` etc.).
        if (n.kind === 'image') n.el.style.border = n.imageBorderColor ? `1.5px solid ${n.imageBorderColor}` : ''
    }

    // Layout padrão esquerda→direita, centros na linha y=0.
    function layoutDefault() {
        let x = 0
        nodes.forEach((n) => {
            n.x = x
            n.y = -n.h / 2
            x += n.w + LEVEL_GAP
        })
    }

    // "Organizar": só reposiciona os blocos e reseta as âncoras das setas
    // para o padrão — não toca em rótulos/topologia. `dashed` de cada aresta
    // é preservado (só from/to voltam ao padrão).
    function organize() {
        if (!nodes.length) return
        layoutDefault()
        edgeAnchors = edgeAnchors.map((a) => ({ from: 'r', to: 'l', dashed: !!a.dashed }))
        nodes.forEach((n) => {
            n.el.style.left = n.x + 'px'
            n.el.style.top = n.y + 'px'
        })
        draw()
        setDirty(true)
        fit()
    }

    // Aplica um layout salvo (posições + âncoras + comentários + raias), se
    // compatível com a cadeia atual. `lanes` já foi resetado por
    // `clearWorld()` no início de `render()` — aqui só sobrescreve quando o
    // layout salvo realmente traz algo, e sempre termina redesenhando as
    // raias (vazio ou não).
    function applyLayout(layout) {
        applyTheme(typeof layout?.theme === 'string' ? layout.theme : 'original', { markDirty: false })
        if (!layout) {
            rebuildLanes()
            rebuildNotes()
            return
        }
        if (Array.isArray(layout.nodes) && layout.nodes.length === nodes.length) {
            nodes.forEach((n, i) => {
                const p = layout.nodes[i]
                if (p && Number.isFinite(p.x) && Number.isFinite(p.y)) {
                    n.x = p.x
                    n.y = p.y
                }
                if (isHex(p?.color)) n.color = p.color
                if (isHex(p?.textColor)) n.textColor = p.textColor
                if (p && FONTS[p.font]) n.font = p.font
                if (p && FONT_SIZES[p.fontSize]) n.fontSize = p.fontSize
                if (p && typeof p.dashed === 'boolean') n.dashed = p.dashed
                if (isHex(p?.imageBorderColor)) n.imageBorderColor = p.imageBorderColor
                if (p && typeof p.logoOnly === 'boolean') n.logoOnly = p.logoOnly
            })
        }
        if (Array.isArray(layout.edges) && layout.edges.length === edgeAnchors.length) {
            layout.edges.forEach((e, i) => {
                if (e && ANCHORS[e.from] && ANCHORS[e.to]) edgeAnchors[i] = { from: e.from, to: e.to, dashed: !!e.dashed }
            })
        }
        if (Array.isArray(layout.comments) && layout.comments.length === nodes.length) {
            layout.comments.forEach((c, i) => {
                if (typeof c === 'string' && c.trim()) {
                    nodes[i].comment = c
                    nodes[i].el.classList.add('has-comment')
                }
            })
        }
        if (Array.isArray(layout.lanes)) {
            const clampSize = (v, fallback) => (Number.isFinite(v) ? Math.round(Math.max(LANE_MIN_SIZE, Math.min(LANE_MAX_SIZE, v))) : fallback)
            const clampOpacity = (v) => (Number.isFinite(v) ? Math.max(LANE_OPACITY_MIN, Math.min(LANE_OPACITY_MAX, v)) : LANE_STYLE_DEFAULTS.opacity)
            lanes = layout.lanes
                .filter((l) => l && typeof l.label === 'string')
                .map((l) => ({
                    // Backfill: uma raia salva antes de um destes campos
                    // existir não traz a chave — `LANE_STYLE_DEFAULTS` cobre
                    // o buraco, e as validações abaixo tratam qualquer valor
                    // presente mas fora do esperado (enum errado, tipo
                    // errado) do mesmo jeito, caindo no default.
                    ...LANE_STYLE_DEFAULTS,
                    label: l.label,
                    color: isHex(l.color) ? l.color : LANE_COLORS[0],
                    // Ausente (não uma chave presente com `null`) de propósito
                    // — é o sinal que `laneHeaderColor()` usa pra saber que o
                    // cabeçalho ainda está no automático (escurecido de
                    // `color`) em vez de uma escolha explícita.
                    ...(isHex(l.headerColor) ? { headerColor: l.headerColor } : {}),
                    x: Number.isFinite(l.x) ? l.x : 0,
                    y: Number.isFinite(l.y) ? l.y : 0,
                    width: clampSize(l.width, LANE_DEFAULT_WIDTH),
                    height: clampSize(l.height, LANE_DEFAULT_HEIGHT),
                    rounded: typeof l.rounded === 'boolean' ? l.rounded : LANE_STYLE_DEFAULTS.rounded,
                    dashed: typeof l.dashed === 'boolean' ? l.dashed : LANE_STYLE_DEFAULTS.dashed,
                    opacity: clampOpacity(l.opacity),
                    orientation: l.orientation === 'vertical' ? 'vertical' : LANE_STYLE_DEFAULTS.orientation,
                    showTitle: typeof l.showTitle === 'boolean' ? l.showTitle : LANE_STYLE_DEFAULTS.showTitle,
                    fontSize: LANE_FONT_SIZES[l.fontSize] ? l.fontSize : LANE_STYLE_DEFAULTS.fontSize,
                }))
        }
        if (Array.isArray(layout.notes)) {
            notes = layout.notes
                .filter((n) => n && Number.isFinite(n.x) && Number.isFinite(n.y))
                .map((n) => ({ x: n.x, y: n.y, text: typeof n.text === 'string' ? n.text : '' }))
        }
        rebuildLanes()
        rebuildNotes()
    }

    // Bounding box (espaço do mundo) de todos os nós — usado por `fit()`.
    // `null` quando não há nó nenhum. Raias NÃO entram nesta conta — só
    // `fit()` centraliza/enquadra os BLOCOS; uma raia vazia arrastada bem
    // longe deles não deveria "puxar" o enquadramento atrás de si.
    function nodesBBox() {
        if (!nodes.length) return null
        let minX = Infinity
        let minY = Infinity
        let maxX = -Infinity
        let maxY = -Infinity
        nodes.forEach((n) => {
            minX = Math.min(minX, n.x)
            minY = Math.min(minY, n.y)
            maxX = Math.max(maxX, n.x + n.w)
            maxY = Math.max(maxY, n.y + n.h)
        })
        return { minX, minY, maxX, maxY }
    }

    // União de `nodesBBox()` com as raias E as anotações — usada SÓ pela
    // exportação (`captureDiagramCanvas()`), nunca por `fit()`: uma raia
    // redimensionada maior que o cluster de blocos atual (comum — o usuário
    // costuma deixar "espaço pra crescer"), ou um post-it solto longe de
    // qualquer bloco, devem entrar no recorte exportado mesmo sem nó nenhum
    // ali, senão aparecem cortados na imagem final. O tamanho de uma
    // anotação não é persistido (só `x`/`y` — ver `rebuildNotes()`), então é
    // medido direto do DOM aqui, com o mesmo fallback de `rebuildNotes()`
    // caso o elemento ainda não tenha sido montado.
    function contentBBox() {
        const nb = nodesBBox()
        if (!nb && !lanes.length && !notes.length) return null
        let { minX, minY, maxX, maxY } = nb || { minX: Infinity, minY: Infinity, maxX: -Infinity, maxY: -Infinity }
        lanes.forEach((l) => {
            minX = Math.min(minX, l.x)
            minY = Math.min(minY, l.y)
            maxX = Math.max(maxX, l.x + l.width)
            maxY = Math.max(maxY, l.y + l.height)
        })
        notes.forEach((note, i) => {
            const w = noteEls[i]?.wrap.offsetWidth || NOTE_DEFAULT_WIDTH
            const h = noteEls[i]?.wrap.offsetHeight || NOTE_MIN_HEIGHT
            minX = Math.min(minX, note.x)
            minY = Math.min(minY, note.y)
            maxX = Math.max(maxX, note.x + w)
            maxY = Math.max(maxY, note.y + h)
        })
        return { minX, minY, maxX, maxY }
    }

    // ── raias — retângulos de fundo livres, puramente visuais ──────
    // Reconstrói do zero os `<div>` das raias a partir de `lanes` (chamado
    // sempre que a lista muda: adicionar/remover/recolorir/renomear/mover/
    // redimensionar) — a contagem é pequena, então recriar tudo é mais
    // simples do que remendar incrementalmente. Cada raia é UMA raia (área
    // colorida + etiqueta + 3 alças de redimensionamento, todas filhas do
    // mesmo `wrap`) filha de `world` — espaço de MUNDO, exatamente como um
    // bloco: `x`/`y`/`width`/`height` viram `left`/`top`/`width`/`height` em
    // CSS direto, sem conversão nenhuma, e pan/zoom as movem/escalam de
    // graça via a transform de `world` (nada a recalcular em `applyView()`).
    // Isso só é possível porque uma raia deixou de ser obrigatoriamente
    // 100% da largura do viewport — a versão anterior (raia = faixa
    // horizontal sempre full-width, empilhada com as vizinhas) vivia em
    // espaço de TELA de propósito, pra a largura ficar presa ao viewport
    // independente do zoom; um retângulo livre não tem mais esse motivo.
    //
    // Tudo entra ANTES dos nós em `world` (`prepend`) — ordem de documento,
    // não z-index negativo (a lição de sempre: nem `.ak-viz-viewport` nem
    // `[data-integration-viz]` estabelecem um contexto de empilhamento
    // próprio, então um z-index negativo escaparia e pintaria atrás do
    // `bg-surface` do componente inteiro em vez de só atrás dos nós) —
    // assim um bloco por cima de uma raia sempre fica visível/clicável;
    // só a área da raia NÃO coberta por nenhum bloco reage ao arraste do
    // próprio corpo (mover) ou das alças (redimensionar).
    function rebuildLanes() {
        laneEls.forEach((entry) => entry.wrap.remove())
        laneEls = lanes.map((lane, i) => {
            const wrap = document.createElement('div')
            wrap.className = 'ak-viz-lane'
            wrap.classList.toggle('is-vertical', lane.orientation === 'vertical')
            wrap.classList.toggle('is-rounded', !!lane.rounded)
            wrap.style.left = lane.x + 'px'
            wrap.style.top = lane.y + 'px'
            wrap.style.width = lane.width + 'px'
            wrap.style.height = lane.height + 'px'
            // Preenchimento sutil de propósito (opacidade controlável,
            // `laneBackgroundCss()`), mas a BORDA (sólida ou tracejada,
            // `lane.dashed`) precisa se ler como o contorno real do
            // retângulo — e o título, na cor cheia, é o que fica mais
            // vívido de tudo.
            wrap.style.background = laneBackgroundCss(lane)
            wrap.style.borderColor = hexToRgba(lane.color, 0.7)
            wrap.style.borderStyle = lane.dashed ? 'dashed' : 'solid'
            // Arrastar o CORPO inteiro (fora da etiqueta/alças) move a raia
            // (`x`/`y`) — mas só a etiqueta abre o toolbar de cor/nome/remover
            // num clique sem arraste (`onLabel`, ver o `mouseup` global);
            // clicar o resto do corpo sem arrastar não faz nada. Exceção:
            // sem título (`showTitle === false`) não existe faixa separada
            // pra reservar como alvo de seleção — o corpo inteiro assume o
            // papel da etiqueta, mesma distinção clique-vs-arraste de um
            // bloco (`drag.moved`, ver `startNodePointer()`), só que aqui o
            // "vira seleção" de um clique puro fica condicionado a QUAL
            // parte do retângulo começou o gesto.
            wrap.addEventListener('pointerdown', (e) => {
                if (e.button !== 0 || !editable) return
                e.stopPropagation()
                e.preventDefault()
                drag = { type: 'lane-move', index: i, startClientX: e.clientX, startClientY: e.clientY, startX: lane.x, startY: lane.y, moved: false, onLabel: lane.showTitle === false }
            })
            // Sem título, o corpo inteiro assume o papel da etiqueta — inclusive
            // pra renomear (`startInlineLaneLabelEdit()`), mesma distinção
            // `showTitle === false` de tudo o mais nesta função.
            wrap.addEventListener('dblclick', (e) => {
                if (!editable || lane.showTitle !== false) return
                e.stopPropagation()
                startInlineLaneLabelEdit(i)
            })

            // Etiqueta: faixa de altura/largura cheia com o TÍTULO — na
            // borda esquerda com texto vertical (orientação horizontal, a
            // faixa clássica) ou no topo com texto padrão esquerda→direita
            // (orientação vertical, `.is-vertical` acima) — cor
            // independente da do corpo (`laneHeaderColor()`), com a cor do
            // texto escolhida pelo contraste (`textColorFor()`) em vez de
            // branco fixo, já que agora o cabeçalho pode ser claro (branco/
            // bege). Tem `pointer-events: auto` própria (editável) pra virar
            // o único alvo que abre o toolbar num clique — precisa do seu
            // próprio `mousedown` (com `stopPropagation`) em vez de deixar
            // borbulhar pro `wrap`, senão o `onLabel` acima sempre veria
            // `false`. Sem título (`showTitle === false`) ela some da tela e
            // o `wrap` acima assume a seleção — não removida do DOM pra
            // `rebuildLanes()` continuar simples de reconstruir do zero.
            const label = document.createElement('span')
            label.className = 'ak-viz-lane-label'
            const headerColor = laneHeaderColor(lane)
            label.style.background = headerColor
            label.style.color = textColorFor(headerColor)
            label.style.fontSize = LANE_FONT_SIZES[lane.fontSize] || LANE_FONT_SIZES.sm
            label.style.display = lane.showTitle === false ? 'none' : ''
            label.textContent = lane.label
            label.addEventListener('pointerdown', (e) => {
                if (e.button !== 0 || !editable) return
                e.stopPropagation()
                e.preventDefault()
                drag = { type: 'lane-move', index: i, startClientX: e.clientX, startClientY: e.clientY, startX: lane.x, startY: lane.y, moved: false, onLabel: true }
            })
            // Renomear direto no cabeçalho — duplo clique troca o `<span>`
            // estático por um `<input>` sobreposto, mesma ideia de
            // `startInlineLabelEdit()` no bloco (ver a função mais abaixo).
            label.addEventListener('dblclick', (e) => {
                if (!editable) return
                e.stopPropagation()
                startInlineLaneLabelEdit(i)
            })
            wrap.appendChild(label)

            // 3 alças de redimensionamento — direita (só largura), embaixo
            // (só altura) e o canto (ambas de uma vez), mesmo padrão de
            // qualquer editor de retângulos (Figma, Miro, Excalidraw). Só
            // interativas quando editável, mesmo CSS attribute do label
            // acima. O canto entra por último (depois de posicionado pelo
            // CSS) só pra ganhar da alça de baixo/direita no pixel exato
            // onde as três se encontram.
            const handles = {}
            ;['e', 's', 'se'].forEach((dir) => {
                const handle = document.createElement('div')
                handle.className = `ak-viz-lane-resize ak-viz-lane-resize-${dir}`
                handle.addEventListener('pointerdown', (e) => {
                    if (e.button !== 0 || !editable) return
                    e.stopPropagation()
                    e.preventDefault()
                    drag = {
                        type: 'lane-resize',
                        index: i,
                        dir,
                        startClientX: e.clientX,
                        startClientY: e.clientY,
                        startW: lane.width,
                        startH: lane.height,
                    }
                    handle.classList.add('is-resizing')
                })
                handles[dir] = handle
                wrap.appendChild(handle)
            })

            return { wrap, label, handles }
        })
        if (laneEls.length) world.prepend(...laneEls.map((entry) => entry.wrap))
    }

    function removeLane(index) {
        lanes.splice(index, 1)
        closeLaneToolbar()
        rebuildLanes()
        setDirty(true)
    }

    // Nasce centrada no meio do viewport ATUAL (não num canto fixo do
    // mundo) — mesma ideia de `appendNode()` nascer perto do que já existe:
    // o usuário provavelmente quer a raia nova perto do que está olhando
    // agora, não em (0,0). Sem painel/diálogo no caminho: clicar o botão
    // "Raias" da topbar já cria e seleciona a raia (`selectLane()` abre o
    // toolbar na hora, pronta pra renomear).
    function addLane() {
        if (!editable || !graphRef) return
        const vpRect = viewport.getBoundingClientRect()
        const center = screenToWorld(vpRect.left + vpRect.width / 2, vpRect.top + vpRect.height / 2)
        lanes.push({
            ...LANE_STYLE_DEFAULTS,
            label: `Raia ${lanes.length + 1}`,
            color: LANE_COLORS[lanes.length % LANE_COLORS.length],
            x: Math.round(center.x - LANE_DEFAULT_WIDTH / 2),
            y: Math.round(center.y - LANE_DEFAULT_HEIGHT / 2),
            width: LANE_DEFAULT_WIDTH,
            height: LANE_DEFAULT_HEIGHT,
        })
        rebuildLanes()
        setDirty(true)
        selectLane(lanes.length - 1)
    }

    // ── anotações "post-it" — texto livre multilinha, puramente visual ──
    // Mesmo espírito das raias (filha de `world`, em espaço de MUNDO, pan/
    // zoom de graça via a transform de `world`), mas BEM mais simples de
    // propósito ("anotação básica"): sem toolbar próprio, sem cor
    // configurável (sempre o amarelo do post-it), sem redimensionar — só
    // posição (arrastando a faixinha do topo) e o texto em si
    // (`contenteditable`, cresce sozinho com o conteúdo). Ao contrário das
    // raias, entram DEPOIS dos nós em `world` (`append`, não `prepend`) — um
    // post-it é colado por CIMA do diagrama, não atrás dele.
    function rebuildNotes() {
        noteEls.forEach((entry) => entry.wrap.remove())
        function autosize(body) {
            body.style.height = 'auto'
            body.style.height = body.scrollHeight + 'px'
        }
        noteEls = notes.map((note, i) => {
            const wrap = document.createElement('div')
            wrap.className = 'ak-viz-note'
            wrap.style.left = note.x + 'px'
            wrap.style.top = note.y + 'px'
            // Leve rotação alternada por índice — o ar "colado à mão" de um
            // post-it de verdade, sem virar caricatura (mesma dose "sóbria
            // com alma" do resto do app). Puramente decorativa, não
            // persistida — cada carregamento recalcula pela posição na
            // lista, não por um valor salvo.
            wrap.style.transform = `rotate(${(i % 2 === 0 ? -1 : 1) * (1 + (i % 3) * 0.5)}deg)`

            // Faixinha do topo: única parte que arrasta (mover) e que
            // carrega o botão de remover — o corpo abaixo é todo texto
            // editável, então precisa de uma área "neutra" pra servir de
            // alça, mesmo espírito da etiqueta da raia (`rebuildLanes()`).
            const handle = document.createElement('div')
            handle.className = 'ak-viz-note-handle'
            handle.addEventListener('pointerdown', (e) => {
                if (e.button !== 0 || !editable) return
                e.stopPropagation()
                e.preventDefault()
                drag = { type: 'note-move', index: i, startClientX: e.clientX, startClientY: e.clientY, startX: note.x, startY: note.y, moved: false }
            })

            const removeBtn = document.createElement('button')
            removeBtn.type = 'button'
            removeBtn.className = 'ak-viz-note-remove'
            removeBtn.title = 'Remover anotação'
            removeBtn.setAttribute('aria-label', 'Remover anotação')
            removeBtn.innerHTML = '&times;'
            removeBtn.addEventListener('pointerdown', (e) => e.stopPropagation())
            removeBtn.addEventListener('click', (e) => {
                e.stopPropagation()
                removeNote(i)
            })
            handle.appendChild(removeBtn)
            wrap.appendChild(handle)

            // Corpo: `<textarea>`, não `contenteditable` — sem markdown, sem
            // preview, é uma anotação básica, e cresce sozinho com o texto
            // (`autosize()`), por isso não há `height` persistido, só
            // `x`/`y` (ver `save()`). A escolha de `<textarea>` em vez de
            // `contenteditable` não é estética: um `contenteditable`, ao
            // apertar Enter, insere elementos (`<div>`/`<br>`, dependendo do
            // navegador) e ler `.textContent` de volta ACHATA tudo numa
            // string só, sem quebra de linha nenhuma — inaceitável pra uma
            // anotação que precisa ser multilinha de verdade.
            // `.value` de um `<textarea>` preserva `\n` de graça. `mousedown`
            // para a propagação (sem `preventDefault`, que impediria o
            // cursor de texto de posicionar) pra clicar no texto nunca
            // iniciar um arraste do canvas.
            const body = document.createElement('textarea')
            body.className = 'ak-viz-note-body'
            body.rows = 1
            body.placeholder = 'Escreva aqui…'
            body.value = note.text || ''
            body.readOnly = !editable
            body.addEventListener('pointerdown', (e) => { if (editable) e.stopPropagation() })
            body.addEventListener('input', () => {
                notes[i].text = body.value
                setDirty(true)
                autosize(body)
            })
            wrap.appendChild(body)

            return { wrap, body }
        })
        if (noteEls.length) {
            world.append(...noteEls.map((entry) => entry.wrap))
            noteEls.forEach((entry) => autosize(entry.body))
        }
    }

    function removeNote(index) {
        notes.splice(index, 1)
        rebuildNotes()
        setDirty(true)
    }

    // Nasce centrada no meio do viewport ATUAL, mesma ideia de `addLane()` —
    // e já entra focada pronta pra digitar, já que não existe um toolbar
    // separado que abriria com essa função pra guiar o próximo passo.
    function addNote() {
        if (!editable || !graphRef) return
        const vpRect = viewport.getBoundingClientRect()
        const center = screenToWorld(vpRect.left + vpRect.width / 2, vpRect.top + vpRect.height / 2)
        notes.push({
            x: Math.round(center.x - NOTE_DEFAULT_WIDTH / 2),
            y: Math.round(center.y - NOTE_MIN_HEIGHT / 2),
            text: '',
        })
        rebuildNotes()
        setDirty(true)
        noteEls[notes.length - 1]?.body.focus()
    }

    // ── toolbar da raia selecionada (cor/nome/remover) ──────────────
    // Aberto por um clique (sem arraste) em qualquer raia — mesmo espírito
    // do toolbar contextual de um bloco (`selectNode()`), só que com cor
    // (presets, `buildLaneSwatches()`) + nome (input direto, sem lápis
    // separado) + remover, já que uma raia não tem título/comentário/link
    // pra editar. Mutuamente exclusivo com o toolbar do bloco: `selectNode()`
    // fecha este; este fecha aquele.
    function selectLane(index) {
        if (!editable || !laneEls[index]) return
        selectNode(null)
        closeProtocolEditor()
        closeAddEditor()
        closeLaneToolbar()
        selectedLane = index
        laneEls[index].wrap.classList.add('is-selected')
        buildLaneSwatches()
        buildLaneHeaderSwatches()
        refreshLaneToolbarControls()
        laneToolbar?.classList.remove('hidden')
        laneToolbar?.classList.add('flex')
    }

    // Elemento que serve de âncora pro toolbar/seleção da raia — a etiqueta
    // quando ela existe (`showTitle` !== false), o corpo inteiro quando não
    // (sem faixa dedicada pra ancorar, o próprio `wrap` assume o papel).
    function laneAnchorEl(index) {
        const entry = laneEls[index]
        return lanes[index]?.showTitle === false ? entry.wrap : entry.label
    }

    // ── renomear direto no cabeçalho, duplo clique ──────────────────
    // Sobrepõe um `<input>` flutuante (filho de `stage`, espaço de TELA —
    // mesma convenção de `startInlineProtocolEdit()`) centrado na etiqueta,
    // em vez de trocar o `<span>` estático no lugar como
    // `startInlineLabelEdit()` faz no bloco: a etiqueta tem só 26px de
    // largura/altura (a faixa do swimlane) e, na orientação horizontal, texto
    // vertical (`writing-mode`) — nem o espaço nem a orientação servem pra
    // digitar direto. Sem título (`showTitle === false`), a âncora é o corpo
    // inteiro, potencialmente enorme (até `LANE_MAX_SIZE`), então o centro
    // usado é da interseção com a área VISÍVEL do stage, não do retângulo
    // inteiro — senão o input nasceria fora da tela em uma raia grande.
    function startInlineLaneLabelEdit(index) {
        if (!editable || !lanes[index]) return
        const entry = laneEls[index]
        if (!entry) return
        const lane = lanes[index]
        selectLane(index)

        const anchorEl = lane.showTitle === false ? entry.wrap : entry.label

        const input = document.createElement('input')
        input.type = 'text'
        input.className = 'ak-viz-lane-label-input'
        input.value = lane.label
        input.autocomplete = 'off'
        input.spellcheck = false
        stage.appendChild(input)

        function position() {
            const rect = anchorEl.getBoundingClientRect()
            const stageRect = stage.getBoundingClientRect()
            const visLeft = Math.max(rect.left, stageRect.left)
            const visTop = Math.max(rect.top, stageRect.top)
            const visRight = Math.min(rect.right, stageRect.right)
            const visBottom = Math.min(rect.bottom, stageRect.bottom)
            input.style.left = ((visLeft + visRight) / 2 - stageRect.left) + 'px'
            input.style.top = ((visTop + visBottom) / 2 - stageRect.top) + 'px'
        }
        position()
        input.focus()
        input.select()

        function cleanup() {
            input.removeEventListener('blur', onBlur)
            input.removeEventListener('keydown', onKeydown)
            input.remove()
            inlineLaneLabelReposition = null
        }

        function commit() {
            const newLabel = input.value.trim() || 'Raia'
            cleanup()
            if (newLabel === lane.label) return
            lane.label = newLabel
            entry.label.textContent = newLabel
            setDirty(true)
        }

        function cancel() { cleanup() }

        const onKeydown = (e) => {
            if (e.key === 'Escape') { e.preventDefault(); cancel(); return }
            if (e.key === 'Enter') { e.preventDefault(); commit() }
        }
        const onBlur = () => commit()

        inlineLaneLabelReposition = position
        input.addEventListener('keydown', onKeydown)
        input.addEventListener('blur', onBlur)
    }

    function closeLaneToolbar() {
        if (selectedLane !== null && laneEls[selectedLane]) laneEls[selectedLane].wrap.classList.remove('is-selected')
        selectedLane = null
        if (!laneToolbar || laneToolbar.classList.contains('hidden')) return
        laneToolbar.classList.add('hidden')
        laneToolbar.classList.remove('flex')
    }

    // Aplica cor/texto/tamanho do CABEÇALHO no DOM já montado — chamada
    // sempre que `color` (o automático pode mudar), `headerColor` ou
    // `fontSize` mudam, nunca precisa saber qual dos três foi.
    function applyLaneHeaderStyle(lane, entry) {
        if (!entry) return
        const headerColor = laneHeaderColor(lane)
        entry.label.style.background = headerColor
        entry.label.style.color = textColorFor(headerColor)
        entry.label.style.fontSize = LANE_FONT_SIZES[lane.fontSize] || LANE_FONT_SIZES.sm
    }

    // Presets fixos (`LANE_COLORS`), mesmo padrão de `buildSwatches()`
    // (bloco) — sem cor personalizada aqui de propósito, só as predefinidas.
    // Esta é a cor do CORPO (preenchimento + borda); `buildLaneHeaderSwatches()`
    // logo abaixo é a mesma paleta pro cabeçalho, independente.
    function buildLaneSwatches() {
        if (!laneToolbarSwatches || selectedLane === null) return
        const current = lanes[selectedLane]?.color
        laneToolbarSwatches.innerHTML = ''
        LANE_COLORS.forEach((color) => {
            const sw = document.createElement('button')
            sw.type = 'button'
            sw.className = 'size-[22px] shrink-0 cursor-pointer rounded-md border border-black/10 transition-transform hover:scale-110'
            sw.style.background = color
            sw.title = color
            sw.style.boxShadow = current && current.toLowerCase() === color.toLowerCase()
                ? '0 0 0 2px var(--viz-bg), 0 0 0 3.5px var(--viz-select)'
                : ''
            sw.addEventListener('click', () => setLaneColor(color))
            laneToolbarSwatches.appendChild(sw)
        })
    }

    function setLaneColor(color) {
        if (selectedLane === null || !lanes[selectedLane]) return
        const lane = lanes[selectedLane]
        lane.color = color
        const entry = laneEls[selectedLane]
        if (entry) {
            entry.wrap.style.background = laneBackgroundCss(lane)
            entry.wrap.style.borderColor = hexToRgba(color, 0.7)
            // Só reflete de verdade se o cabeçalho ainda estiver no
            // automático (`laneHeaderColor()` já decide isso sozinho) — uma
            // cor de cabeçalho explícita não muda quando o corpo muda.
            applyLaneHeaderStyle(lane, entry)
        }
        buildLaneSwatches()
        setDirty(true)
    }

    // Mesma paleta/padrão de `buildLaneSwatches()`, pro cabeçalho — cor
    // independente do corpo (`lane.headerColor`, ausente = automático via
    // `laneHeaderColor()`); nenhum swatch aparece "selecionado" enquanto o
    // cabeçalho estiver no automático, o que é o esperado (nenhuma escolha
    // explícita feita ainda).
    function buildLaneHeaderSwatches() {
        if (!laneToolbarHeaderSwatches || selectedLane === null) return
        const current = lanes[selectedLane]?.headerColor
        laneToolbarHeaderSwatches.innerHTML = ''
        LANE_COLORS.forEach((color) => {
            const sw = document.createElement('button')
            sw.type = 'button'
            sw.className = 'size-[22px] shrink-0 cursor-pointer rounded-md border border-black/10 transition-transform hover:scale-110'
            sw.style.background = color
            sw.title = color
            sw.style.boxShadow = current && current.toLowerCase() === color.toLowerCase()
                ? '0 0 0 2px var(--viz-bg), 0 0 0 3.5px var(--viz-select)'
                : ''
            sw.addEventListener('click', () => setLaneHeaderColor(color))
            laneToolbarHeaderSwatches.appendChild(sw)
        })
    }

    function setLaneHeaderColor(color) {
        if (selectedLane === null || !lanes[selectedLane]) return
        const lane = lanes[selectedLane]
        lane.headerColor = color
        applyLaneHeaderStyle(lane, laneEls[selectedLane])
        buildLaneHeaderSwatches()
        setDirty(true)
    }

    function setLaneFontSize(size) {
        if (selectedLane === null || !lanes[selectedLane] || !LANE_FONT_SIZES[size]) return
        const lane = lanes[selectedLane]
        lane.fontSize = size
        applyLaneHeaderStyle(lane, laneEls[selectedLane])
        setDirty(true)
    }

    function setLaneOpacity(rawValue) {
        if (selectedLane === null || !lanes[selectedLane]) return
        const opacity = Math.max(LANE_OPACITY_MIN, Math.min(LANE_OPACITY_MAX, Number(rawValue) || LANE_STYLE_DEFAULTS.opacity))
        lanes[selectedLane].opacity = opacity
        const entry = laneEls[selectedLane]
        if (entry) entry.wrap.style.background = laneBackgroundCss(lanes[selectedLane])
        setDirty(true)
    }

    // Reflete o estado da raia selecionada nos controles do toolbar — chamado
    // sempre que `selectLane()` abre (uma raia pode ter sido editada por
    // outro caminho, ou o toolbar reabrir numa raia diferente) e depois de
    // cada toggle local, mesmo espírito de `refreshToolbarControls()` (bloco).
    function refreshLaneToolbarControls() {
        const lane = lanes[selectedLane]
        if (!lane) return
        if (laneToolbarRoundedBtn) {
            laneToolbarRoundedBtn.classList.toggle('!bg-accent-soft', !!lane.rounded)
            laneToolbarRoundedBtn.setAttribute('aria-pressed', String(!!lane.rounded))
        }
        if (laneToolbarRoundedIcon) {
            laneToolbarRoundedIcon.classList.toggle('rounded-md', !!lane.rounded)
            laneToolbarRoundedIcon.classList.toggle('rounded-none', !lane.rounded)
        }
        if (laneToolbarDashedBtn) {
            laneToolbarDashedBtn.classList.toggle('border-dashed', !!lane.dashed)
            laneToolbarDashedBtn.classList.toggle('!bg-accent-soft', !!lane.dashed)
        }
        laneToolbarOrientationBtns.forEach((btn) => {
            const active = btn.dataset.vizLaneToolbarOrientation === (lane.orientation || LANE_STYLE_DEFAULTS.orientation)
            btn.classList.toggle('!bg-accent-soft', active)
            btn.setAttribute('aria-pressed', String(active))
        })
        if (laneToolbarOpacity) laneToolbarOpacity.value = String(Number.isFinite(lane.opacity) ? lane.opacity : LANE_STYLE_DEFAULTS.opacity)
        if (laneToolbarFontSize) laneToolbarFontSize.value = lane.fontSize || LANE_STYLE_DEFAULTS.fontSize
        if (laneToolbarTitleInput) laneToolbarTitleInput.checked = lane.showTitle !== false
    }

    laneToolbarFontSize?.addEventListener('change', () => setLaneFontSize(laneToolbarFontSize.value))
    // Cantos retos/arredondados — só a raia em si (a etiqueta acompanha via
    // `.is-rounded` no CSS, ver `integration-viz.blade.php`).
    laneToolbarRoundedBtn?.addEventListener('click', () => {
        if (selectedLane === null || !lanes[selectedLane]) return
        lanes[selectedLane].rounded = !lanes[selectedLane].rounded
        laneEls[selectedLane]?.wrap.classList.toggle('is-rounded', lanes[selectedLane].rounded)
        refreshLaneToolbarControls()
        setDirty(true)
    })
    // Borda sólida/tracejada — mesmo botão-espelha-o-estado do toggle do
    // bloco (`toolbarDashedBtn` acima).
    laneToolbarDashedBtn?.addEventListener('click', () => {
        if (selectedLane === null || !lanes[selectedLane]) return
        lanes[selectedLane].dashed = !lanes[selectedLane].dashed
        const entry = laneEls[selectedLane]
        if (entry) entry.wrap.style.borderStyle = lanes[selectedLane].dashed ? 'dashed' : 'solid'
        refreshLaneToolbarControls()
        setDirty(true)
    })
    // Orientação horizontal/vertical — move a etiqueta da borda esquerda
    // (texto vertical) pro topo (texto padrão), `.is-vertical` no CSS.
    laneToolbarOrientationBtns.forEach((btn) => {
        btn.addEventListener('click', () => {
            if (selectedLane === null || !lanes[selectedLane]) return
            const orientation = btn.dataset.vizLaneToolbarOrientation === 'vertical' ? 'vertical' : 'horizontal'
            lanes[selectedLane].orientation = orientation
            laneEls[selectedLane]?.wrap.classList.toggle('is-vertical', orientation === 'vertical')
            refreshLaneToolbarControls()
            setDirty(true)
        })
    })
    laneToolbarOpacity?.addEventListener('input', () => setLaneOpacity(laneToolbarOpacity.value))
    // Título visível/oculto — sem título, o corpo inteiro assume o papel de
    // alvo de seleção (`laneAnchorEl()`, usado por `startInlineLaneLabelEdit()`).
    laneToolbarTitleInput?.addEventListener('change', () => {
        if (selectedLane === null || !lanes[selectedLane]) return
        lanes[selectedLane].showTitle = !!laneToolbarTitleInput.checked
        if (laneEls[selectedLane]) laneEls[selectedLane].label.style.display = lanes[selectedLane].showTitle === false ? 'none' : ''
        setDirty(true)
    })
    laneToolbarRemove?.addEventListener('click', () => {
        if (selectedLane === null) return
        removeLane(selectedLane)
    })

    laneToolbar?.addEventListener('pointerdown', (e) => e.stopPropagation())
    lanesBtn?.addEventListener('click', addLane)
    notesBtn?.addEventListener('click', addNote)

    function draw() {
        clearOverlays()
        // Raias não precisam de nada aqui: são filhas de `world`, então
        // arrastar um bloco (que roda `draw()` a cada `mousemove`) não as
        // afeta em nada — pan/zoom idem, via a própria transform CSS.
        edgeLabelEls = []
        const edgeList = graphRef.edges || []

        edgeList.forEach((edge, i) => {
            // Enquanto essa ligação está sendo arrastada, desenha a ponta
            // solta no nó sob o ponteiro (`drag.targetNode`), não no nó
            // persistido em `edge.from`/`edge.to` — é a pré-visualização da
            // religação, confirmada só no mouseup (`retargetEdge()`).
            const fromIndex = (drag?.type === 'handle' && drag.edge === i && drag.end === 'from') ? drag.targetNode : edge.from
            const toIndex = (drag?.type === 'handle' && drag.edge === i && drag.end === 'to') ? drag.targetNode : edge.to
            const fromNode = nodes[fromIndex]
            const toNode = nodes[toIndex]
            if (!fromNode || !toNode) return

            const anchors = edgeAnchors[i] || { from: 'r', to: 'l', dashed: false }
            const a0 = anchorPoint(fromNode, anchors.from)
            const a3 = anchorPoint(toNode, anchors.to)
            // afasta as pontas da linha do centro do handle, para a seta não invadir o círculo
            const p0 = { x: a0.x + a0.nx * EDGE_GAP, y: a0.y + a0.ny * EDGE_GAP, nx: a0.nx, ny: a0.ny }
            const p3 = { x: a3.x + a3.nx * EDGE_GAP, y: a3.y + a3.ny * EDGE_GAP, nx: a3.nx, ny: a3.ny }
            const dist = Math.hypot(p3.x - p0.x, p3.y - p0.y)
            const d = Math.max(30, dist * 0.4)
            const c1x = p0.x + p0.nx * d
            const c1y = p0.y + p0.ny * d
            const c2x = p3.x + p3.nx * d
            const c2y = p3.y + p3.ny * d

            const path = document.createElementNS(SVG_NS, 'path')
            path.setAttribute('class', 'ak-viz-edge' + (anchors.dashed ? ' is-dashed' : ''))
            path.setAttribute('d', `M ${p0.x} ${p0.y} C ${c1x} ${c1y}, ${c2x} ${c2y}, ${p3.x} ${p3.y}`)
            const arrow = edge.arrow || '->'
            if (arrow === '->' || arrow === '<->') path.setAttribute('marker-end', `url(#${markerEnd.id})`)
            if (arrow === '<-' || arrow === '<->') path.setAttribute('marker-start', `url(#${markerStart.id})`)
            path.dataset.edgeIndex = i // permite re-localizar este <path> por índice — ver startPresentAnimation()
            edges.appendChild(path)

            // Pill de protocolo — sempre visível quando a ligação tem um
            // definido; quando não tem, só desenha uma pill tracejada
            // "+ protocolo" para quem pode editar (viewer não vê nada, igual
            // ao comportamento antigo).
            const proto = edge.protocol
            if (proto || editable) {
                const mx = 0.125 * p0.x + 0.375 * c1x + 0.375 * c2x + 0.125 * p3.x
                const my = 0.125 * p0.y + 0.375 * c1y + 0.375 * c2y + 0.125 * p3.y
                drawProtocolPill(mx, my, i, proto)
            }

            if (editable) {
                drawHandle(a0, i, 'from')
                drawHandle(a3, i, 'to')
            }
        })

        if (drag?.type === 'handle' && nodes[drag.targetNode]) drawAnchorDots(drag.targetNode, edgeAnchors[drag.edge][drag.end])
        // Também desenha a prévia enquanto o quick-add está aberto (soltou a
        // seta em canvas vazio) — não só durante o arraste em si —, pra dar
        // continuidade visual: "esse bloco novo vai ligar ali" continua
        // claro enquanto o usuário escolhe o tipo/Solução no painel.
        if (drag?.type === 'connect' || quickAddOrigin) drawConnectPreview()
        inlineProtocolReposition?.()
    }

    // ── modo apresentação ────────────────────────────────────────────
    // Ativado/desativado por `presentToggleBtn` (bottombar) ou Esc. Desliga
    // toda edição reaproveitando o MESMO portão que já protege cada
    // interação do arquivo (`editable`) — forçar `editable = false` some com
    // drag de nó/raia, portas, alças e pill de protocolo de graça, sem
    // tocar em cada um deles individualmente. `refreshEditableUI()`
    // centraliza os toggles de visibilidade que hoje viviam espalhados em
    // `render()`/`showEmpty()`.
    function refreshEditableUI() {
        root.toggleAttribute('data-editable', editable)
        root.toggleAttribute('data-presenting', presenting)
        saveBtn?.classList.toggle('!hidden', !editable)
        saveSep?.classList.toggle('hidden', !editable)
        addNodeBtn?.classList.toggle('!hidden', !editable)
        lanesBtn?.classList.toggle('!hidden', !editable)
        notesBtn?.classList.toggle('!hidden', !editable)
        presentSpeedWrap?.classList.toggle('hidden', !presenting)
        presentSpeedWrap?.classList.toggle('flex', presenting)
        presentIconStart?.classList.toggle('hidden', presenting)
        presentIconStop?.classList.toggle('hidden', !presenting)
        presentToggleBtn?.setAttribute('title', presenting ? 'Sair da apresentação' : 'Modo apresentação')
    }

    // Uma bolinha por nó revelado — idempotente de propósito: o "sweep" de
    // segurança em `onDotFirstLoopComplete()` chama isto pra TODO nó sem
    // checar antes o que já foi revelado por outra bolinha.
    function revealNode(index) {
        if (index == null || presentRevealedNodes[index]) return
        presentRevealedNodes[index] = true
        if (nodes[index]) nodes[index].el.style.opacity = '1'
    }

    function revealAllNodes() {
        nodes.forEach((n, i) => revealNode(i))
    }

    // Mesma ideia de `revealNode()`, pro `<path class="ak-viz-edge">` e sua
    // pill de protocolo (se tiver uma — `edgeLabelEls[edgeIndex]` só existe
    // quando a ligação tem `protocol` definido, já que durante a
    // apresentação `editable=false` e `draw()` nunca desenha a pill
    // tracejada "+ protocolo" de convite à edição). Uma seta só devia
    // aparecer quando a bolinha COMEÇA a viajar por ela, não quando ela é
    // desenhada pelo `draw()` de sempre — ver chamadas em
    // `startPresentAnimation()`/`presentTick()`.
    function revealEdge(edgeIndex) {
        if (edgeIndex == null || presentRevealedEdges[edgeIndex]) return
        presentRevealedEdges[edgeIndex] = true
        const pathEl = edges.querySelector(`[data-edge-index="${edgeIndex}"]`)
        if (pathEl) pathEl.style.opacity = '1'
        if (edgeLabelEls[edgeIndex]) edgeLabelEls[edgeIndex].style.opacity = '1'
    }

    function revealAllEdges() {
        (graphRef.edges || []).forEach((_, i) => revealEdge(i))
    }

    // Só conta pra fadeIn na PRIMEIRA volta de cada bolinha (`dot.lap === 0`)
    // — voltas seguintes não escondem/revelam nada de novo.
    function onDotArrivedAtSegmentEnd(dot) {
        if (dot.lap > 0) return
        const seg = dot.segments[dot.segIdx]
        const edge = graphRef.edges[seg.edgeIndex]
        revealNode(seg.reversed ? edge.from : edge.to)
    }

    // Nó/aresta fora de qualquer um dos ≤5 caminhos animados (nó isolado já
    // é revelado antes disso, ver `enterPresentation()` — isso aqui é só
    // pra além do teto de ramificações) nunca seria revelado sozinho — uma
    // vez que TODA bolinha ativa já fechou sua própria 1ª volta, revela o
    // que sobrou de uma vez, garantindo que nada fique invisível pra sempre.
    function onDotFirstLoopComplete(dot) {
        if (dot.firstLoopDone) return
        dot.firstLoopDone = true
        presentFirstLoopPending -= 1
        if (presentFirstLoopPending === 0 && !presentFallbackFired) {
            presentFallbackFired = true
            revealAllNodes()
            revealAllEdges()
        }
    }

    // Posiciona o <circle> de uma bolinha no ponto atual do seu segmento —
    // `getPointAtLength()` já devolve coordenadas no mesmo espaço de mundo
    // que qualquer outro filho de `edges`/`world`, sem conversão. `reversed`
    // amostra o <path> de trás pra frente (a ligação é `<-`, ver
    // `computePresentationPaths()`).
    function positionDot(dot) {
        const seg = dot.segments[dot.segIdx]
        const lenAlong = seg.reversed ? (seg.length - dot.segDist) : dot.segDist
        const pt = seg.pathEl.getPointAtLength(Math.max(0, Math.min(seg.length, lenAlong)))
        dot.el.setAttribute('cx', pt.x)
        dot.el.setAttribute('cy', pt.y)
    }

    // Constrói o <circle> de cada bolinha e cacheia o comprimento de cada
    // segmento do seu caminho (`getTotalLength()`, uma vez só) antes de
    // iniciar o loop — reconstruir isso a cada frame seria desperdício, e o
    // <path> de cada aresta não muda enquanto se apresenta (nada dispara
    // `draw()` durante a apresentação, ver `enterPresentation()`/o guard no
    // listener de imagem colada).
    function startPresentAnimation() {
        presentDots = presentPaths.map((path) => {
            const el = document.createElementNS(SVG_NS, 'circle')
            el.setAttribute('class', 'ak-viz-dot')
            el.setAttribute('r', 4)
            el.style.fill = path.color
            el.style.fillOpacity = '0.7' // corpo translúcido — o glow (currentColor) é que fica em destaque
            el.style.color = path.color // currentColor do drop-shadow em `.ak-viz-dot` lê daqui, não de `fill`
            edges.appendChild(el) // irmão dos <path class="ak-viz-edge">, nunca removido por clearOverlays()
            // A 1ª aresta de cada caminho já foi revelada (instantâneo, sem
            // fade) em `enterPresentation()`, ANTES do reflow forçado do
            // reset dos nós isolados — não repetir a chamada aqui, que já é
            // tarde o suficiente (depois daquele reflow) para acabar
            // animando por acidente em vez de aparecer na hora.
            return {
                el,
                segments: path.edges.map(({ edgeIndex, reversed }) => {
                    const pathEl = edges.querySelector(`[data-edge-index="${edgeIndex}"]`)
                    return { edgeIndex, reversed, pathEl, length: pathEl.getTotalLength() }
                }),
                segIdx: 0,
                segDist: 0,
                lap: 0,
                firstLoopDone: false,
            }
        })

        presentFirstLoopPending = presentDots.length
        presentFallbackFired = false
        if (!presentDots.length) { revealAllNodes(); revealAllEdges(); return } // nada pra animar — não deixa o resto escondido pra sempre

        presentDots.forEach((dot) => positionDot(dot))
        presentLastTs = null
        presentRafId = requestAnimationFrame(presentTick)
    }

    function presentTick(ts) {
        if (!presenting) return
        if (presentLastTs === null) presentLastTs = ts
        // Clamp: uma aba em segundo plano pausa o rAF; ao voltar o foco, o
        // primeiro `ts` pode vir com um salto enorme — sem isto a bolinha
        // "teleportaria" por várias voltas de uma vez.
        const dt = Math.min((ts - presentLastTs) / 1000, 0.1)
        presentLastTs = ts
        const step = PRESENT_BASE_SPEED * presentSpeedMultiplier * dt

        presentDots.forEach((dot) => {
            let remaining = step
            while (remaining > 0) {
                const seg = dot.segments[dot.segIdx]
                const segRemaining = seg.length - dot.segDist
                if (remaining < segRemaining) {
                    dot.segDist += remaining
                    remaining = 0
                } else {
                    remaining -= segRemaining
                    dot.segDist = 0
                    onDotArrivedAtSegmentEnd(dot)
                    dot.segIdx += 1
                    if (dot.segIdx >= dot.segments.length) {
                        dot.segIdx = 0
                        if (dot.lap === 0) onDotFirstLoopComplete(dot)
                        dot.lap += 1
                    }
                    revealEdge(dot.segments[dot.segIdx].edgeIndex) // a bolinha está começando a viajar por essa aresta agora — idempotente, então voltas seguintes são no-op
                }
            }
            positionDot(dot)
        })

        presentRafId = requestAnimationFrame(presentTick)
    }

    function stopPresentAnimation() {
        if (presentRafId !== null) cancelAnimationFrame(presentRafId)
        presentRafId = null
        presentLastTs = null
        presentDots.forEach((d) => d.el.remove())
        presentDots = []
    }

    function enterPresentation() {
        if (presenting || !graphRef || !nodes.length) return
        selectNode(null)
        closeComment()
        closeAddEditor()
        closeLaneToolbar()

        presenting = true
        savedEditableBeforePresenting = editable
        editable = false
        refreshEditableUI()
        draw() // reconstrói arestas/pills/alças já sem editable — tira listener de pill obsoleto

        presentSpeedMultiplier = 1
        if (presentSpeedSelect) presentSpeedSelect.value = '1'

        presentPaths = computePresentationPaths(graphRef)
        presentRevealedNodes = nodes.map(() => false)
        presentRevealedEdges = (graphRef.edges || []).map(() => false)
        presentFallbackFired = false
        nodes.forEach((n) => { n.el.style.opacity = '0' })
        // Toda seta (e sua pill de protocolo, se tiver) começa invisível —
        // só aparece quando uma bolinha começa a viajar por ela de verdade
        // (`revealEdge()`, chamado de `startPresentAnimation()`/`presentTick()`),
        // não simplesmente por já existir no grafo.
        edges.querySelectorAll('.ak-viz-edge').forEach((el) => { el.style.opacity = '0' })
        edgeLabelEls.forEach((el) => { if (el) el.style.opacity = '0' })
        presentPaths.forEach((p) => revealNode(p.startNode)) // nó de partida aparece na hora, nunca é "alcançado"
        presentPaths.forEach((p) => revealEdge(p.edges[0].edgeIndex)) // idem pra 1ª aresta de cada caminho — precisa estar ANTES do reflow forçado do isolado abaixo, senão esse reflow "comita" o opacity:0 acima como checkpoint de verdade e a revelação MAIS TARDE (em startPresentAnimation()) passa a animar por acidente

        // Isolado (grau zero) não tem bolinha que um dia o alcance — não faz
        // sentido ele esperar o sweep de fim-de-1ª-volta. MAS revelar rápido
        // demais depois do opacity='0' acima INTERROMPE a mesma transição a
        // meio caminho — e como a curva `ease` sai quase parada, interrompê-
        // la a poucos ms do início (testado com 1 e com 2 `requestAnimationFrame`
        // seguidos: nenhum dos dois deu tempo real suficiente) devolve o
        // valor pra perto de onde já estava, sem fade visível nenhum. Em vez
        // de brigar com uma transição em andamento, zera com `transition:
        // none` + reflow forçado (sem NENHUMA transição rodando) e só then
        // devolve o `transition` — a troca pra '1' que vem depois dispara
        // uma transição limpa e completa de 0.5s a partir de um 0 de
        // verdade, igual à de qualquer nó revelado por uma bolinha de fato.
        const isolated = computeIsolatedNodes(graphRef)
        if (isolated.length) {
            const isolatedEls = isolated.map((i) => nodes[i]?.el).filter(Boolean)
            isolatedEls.forEach((el) => { el.style.transition = 'none'; el.style.opacity = '0' })
            void root.offsetHeight // commita o "0 sem transição" acima antes de reativar
            isolatedEls.forEach((el) => { el.style.transition = '' })
            isolated.forEach((i) => revealNode(i))
        }

        startPresentAnimation()
    }

    function exitPresentation() {
        if (!presenting) return
        stopPresentAnimation()
        presenting = false
        editable = savedEditableBeforePresenting
        // Tira [data-presenting] ANTES do reset de opacidade — a transição
        // de fadeIn só existe sob esse atributo (ver CSS), então o snap de
        // volta pra 100% visível fica instantâneo, sem re-animar ao contrário.
        refreshEditableUI()
        nodes.forEach((n) => { n.el.style.opacity = '' })
        draw() // reconstrói arestas/pills do zero, todas com opacity padrão (visível) — nada a resetar nelas aqui
    }

    // ── Exportar (PNG / GIF) ────────────────────────────────────────────
    // Recorta `#world` ao redor de `contentBBox()`, nunca ao formato do
    // viewport aberto no navegador — ver o parágrafo no topo do .blade para
    // o raciocínio completo. `toCanvas()` clona `world` (nunca toca o DOM
    // real), então NADA disto pisca na tela do usuário — a única exceção real
    // é a troca de estado ao entrar/sair do Modo apresentação em si, que já
    // muda a tela de propósito (mesmo comportamento de sempre).
    // `fontEmbedCSS`, when given, skips `toCanvas()`'s own font detection
    // (`getFontEmbedCSS()` already ran once — see `exportVideo()`). Node
    // labels are set in 'Space Grotesk' (`.ak-viz-node`'s own rule) — a REAL
    // loaded webfont, not a `system-ui` fallback — so skipping font embed
    // entirely (this function used to pass `skipFonts: true`) silently
    // substituted a wider fallback typeface in the exported PNG/GIF, enough
    // to wrap "SAP S/4HANA" onto 2 lines where the live page shows 1 (real
    // bug, reported 2026-08-03). Detecting fonts from scratch on every one of
    // a GIF's ~40 frames is too slow to just always do it live, though —
    // hence precomputing once and passing it back in for every frame after.
    //
    // No `preset`/theme parameter here on purpose: `applyTheme()` already
    // keeps `world`/`edges`'s `data-viz-preset` attribute in sync with
    // `currentTheme` continuously (it's a live, standing canvas state now,
    // not something toggled on only for the instant of a capture) — this
    // function just captures whatever the live DOM already shows, same as
    // any other export.
    async function captureDiagramCanvas(longSide = EXPORT_LONG_SIDE, fontEmbedCSS = null) {
        const bbox = contentBBox()
        if (!bbox) return null
        const cw = bbox.maxX - bbox.minX + EXPORT_PAD * 2
        const ch = bbox.maxY - bbox.minY + EXPORT_PAD * 2
        const wide = cw >= ch
        const targetW = Math.max(1, Math.round(wide ? longSide : (longSide * cw) / ch))
        const targetH = Math.max(1, Math.round(wide ? (longSide * ch) / cw : longSide))
        const scale = targetW / cw
        const tx = Math.round((EXPORT_PAD - bbox.minX) * scale)
        const ty = Math.round((EXPORT_PAD - bbox.minY) * scale)
        const conf = EXPORT_PRESETS[currentTheme] || EXPORT_PRESETS.original

        return toCanvas(world, {
            width: targetW,
            height: targetH,
            pixelRatio: 1,
            backgroundColor: conf.bg,
            imagePlaceholder: EXPORT_IMAGE_PLACEHOLDER,
            ...(fontEmbedCSS ? { fontEmbedCSS } : {}),
            style: { transform: `translate(${tx}px, ${ty}px) scale(${scale})`, transformOrigin: '0 0' },
        })
    }

    function exportFileBase() {
        return (slug || 'diagrama').replace(/[^a-z0-9-]+/gi, '-')
    }

    function downloadBlob(blob, filename) {
        const url = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = url
        a.download = filename
        document.body.appendChild(a)
        a.click()
        a.remove()
        setTimeout(() => URL.revokeObjectURL(url), 4000)
    }

    async function exportImage() {
        if (!graphRef || !nodes.length) { Toast.show('Nada para exportar ainda.', 'warning'); return }
        setButtonLoading(exportPngBtn, true)
        if (exportGifBtn) exportGifBtn.disabled = true
        try {
            const canvas = await captureDiagramCanvas()
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'))
            if (!blob) throw new Error('toBlob returned null')
            downloadBlob(blob, `diagrama-${exportFileBase()}.png`)
            Toast.show('Imagem exportada.')
        } catch (err) {
            console.error('exportImage failed', err)
            Toast.show('Não foi possível gerar a imagem.', 'error')
        } finally {
            setButtonLoading(exportPngBtn, false)
            if (exportGifBtn) exportGifBtn.disabled = false
        }
    }

    // Roda a própria animação do Modo apresentação (entra nela se ainda não
    // estiver, e só sai de novo ao final se foi esta função que entrou —
    // nunca interrompe uma apresentação que o usuário já tinha aberto por
    // conta própria) e vai tirando fotos reais de `captureDiagramCanvas()`
    // ao longo do caminho — o atraso de cada frame no GIF final é o tempo
    // real decorrido entre uma foto e a próxima (`now - lastTs`), não um
    // valor fixo: cada captura é um clone+serialize+rasterize completo do
    // DOM, então o tempo por frame varia, mas a VELOCIDADE de reprodução do
    // GIF continua fiel ao que realmente aconteceu.
    async function exportVideo() {
        if (!graphRef || !nodes.length) { Toast.show('Nada para exportar ainda.', 'warning'); return }
        setButtonLoading(exportGifBtn, true)
        if (exportPngBtn) exportPngBtn.disabled = true
        if (exportStatus) { exportStatus.textContent = 'Gravando apresentação…'; exportStatus.classList.remove('hidden') }

        const wasPresenting = presenting
        if (!wasPresenting) enterPresentation()

        try {
            // Detecting/embedding fonts from scratch (`toCanvas()`'s default
            // behavior, needed so 'Space Grotesk' node labels don't silently
            // fall back to a wider typeface — see captureDiagramCanvas()'s
            // comment) is real work: scans every stylesheet on the page for
            // @font-face rules, then fetches each font file. Fine once; far
            // too slow repeated ~40 times over one GIF. `getFontEmbedCSS()`
            // does that work exactly once, up front, and every frame below
            // reuses the same already-embedded CSS string instead of redoing it.
            const fontEmbedCSS = await getFontEmbedCSS(world)

            const first = await captureDiagramCanvas(EXPORT_GIF_LONG_SIDE, fontEmbedCSS)
            if (!first) return
            const gif = new GIF({
                workers: 2,
                quality: 15, // um pouco mais rápido pra codificar que o padrão (10) — mais frames pesa mais aqui embaixo
                workerScript: await resolveGifWorkerUrl(),
                width: first.width,
                height: first.height,
                background: (EXPORT_PRESETS[currentTheme] || EXPORT_PRESETS.original).bg,
            })

            // Sem espera artificial entre frames — encadeia uma captura direto
            // atrás da outra; o próprio tempo real de captura (bem maior que
            // qualquer espera que valeria a pena impor) já é o que vira o
            // atraso de cada frame no GIF final.
            let lastTs = performance.now()
            gif.addFrame(first, { delay: 80, copy: true }) // só o 1º frame não tem um "tempo decorrido" real anterior pra usar

            const start = lastTs
            while (performance.now() - start < EXPORT_GIF_SECONDS * 1000) {
                const canvas = await captureDiagramCanvas(EXPORT_GIF_LONG_SIDE, fontEmbedCSS)
                if (!canvas) break
                const now = performance.now()
                gif.addFrame(canvas, { delay: now - lastTs, copy: true })
                lastTs = now
            }

            const blob = await new Promise((resolve, reject) => {
                gif.once('finished', resolve)
                gif.once('abort', () => reject(new Error('gif encoding aborted')))
                gif.render()
            })
            downloadBlob(blob, `diagrama-${exportFileBase()}.gif`)
            Toast.show('Vídeo (GIF) exportado.')
        } catch (err) {
            console.error('exportVideo failed', err)
            Toast.show('Não foi possível gerar o vídeo.', 'error')
        } finally {
            if (!wasPresenting) exitPresentation()
            setButtonLoading(exportGifBtn, false)
            if (exportPngBtn) exportPngBtn.disabled = false
            if (exportStatus) { exportStatus.textContent = ''; exportStatus.classList.add('hidden') }
        }
    }

    // `proto` é `{value,label}` (passo com protocolo) ou `null` (sem
    // protocolo ainda — só chega aqui quando `editable`, ver `draw()`).
    // Clicável só quando `editable`: abre o editor de protocolo do segmento
    // (`selectEdge()`), mesmo espírito do lápis de título do nó.
    function drawProtocolPill(mx, my, edgeIndex, proto) {
        const isEmpty = !proto
        const text = proto ? proto.label : '+ protocolo'
        const w = text.length * 6.6 + 14

        const g = document.createElementNS(SVG_NS, 'g')
        g.setAttribute('class', 'ak-viz-plabel' + (isEmpty ? ' is-empty' : '') + (editable ? ' is-editable' : ''))

        const rect = document.createElementNS(SVG_NS, 'rect')
        rect.setAttribute('class', 'ak-viz-plabel-box')
        rect.setAttribute('x', mx - w / 2)
        rect.setAttribute('y', my - 9)
        rect.setAttribute('width', w)
        rect.setAttribute('height', 18)
        rect.setAttribute('rx', 5)

        const label = document.createElementNS(SVG_NS, 'text')
        label.setAttribute('class', 'ak-viz-plabel-text')
        label.setAttribute('x', mx)
        label.setAttribute('y', my + 1)
        label.textContent = text
        // Redesenhado (`draw()`) enquanto este MESMO segmento está em edição
        // inline (`startInlineProtocolEdit()`) — o `<input>` flutuante já
        // cobre esta área, então o texto estático fica invisível por baixo
        // dele em vez de aparecer duplicado.
        if (inlineProtocolEditIndex === edgeIndex) label.style.opacity = '0'

        g.appendChild(rect)
        g.appendChild(label)

        if (editable) {
            g.addEventListener('pointerdown', (e) => e.stopPropagation())
            g.addEventListener('click', (e) => {
                e.stopPropagation()
                selectEdge(edgeIndex)
            })
            // Duplo clique edita o protocolo DIRETO no rótulo — mesmo padrão
            // de `startInlineLabelEdit()` no texto do bloco.
            g.addEventListener('dblclick', (e) => {
                e.stopPropagation()
                startInlineProtocolEdit(edgeIndex)
            })
        }

        edgeLabelEls[edgeIndex] = g
        edges.appendChild(g)
    }

    function drawHandle(point, edgeIndex, end) {
        const h = document.createElement('div')
        h.className = 'ak-viz-handle'
        h.title = 'Arraste para reposicionar a ponta da seta — solte sobre outro bloco pra religar'
        h.style.left = point.x + 'px'
        h.style.top = point.y + 'px'
        if (drag?.type === 'handle' && drag.edge === edgeIndex && drag.end === end) h.classList.add('is-dragging')
        h.addEventListener('pointerdown', (e) => startHandleDrag(e, edgeIndex, end))
        world.appendChild(h)
    }

    function drawAnchorDots(nodeIndex, activeKey) {
        const node = nodes[nodeIndex]
        ANCHOR_KEYS.forEach((key) => {
            const p = anchorPoint(node, key)
            const dot = document.createElement('div')
            dot.className = 'ak-viz-anchor' + (key === activeKey ? ' is-near' : '')
            dot.style.left = p.x + 'px'
            dot.style.top = p.y + 'px'
            world.appendChild(dot)
        })
    }

    // ── seleção + toolbar contextual ───────────────────────────────
    function selectNode(index) {
        closeProtocolEditor()
        closeAddEditor()
        closeLaneToolbar()
        if (selectedIndex !== null && nodes[selectedIndex]) nodes[selectedIndex].el.classList.remove('is-selected')
        selectedIndex = index

        if (index !== null && nodes[index]) {
            nodes[index].el.classList.add('is-selected')
            toolbar?.classList.remove('hidden')
            toolbar?.classList.add('flex')
            // Borda leve com cor: só faz sentido numa imagem colada (as
            // outras formas já têm seu próprio preenchimento/forma).
            toolbarImageBorderWrap?.classList.toggle('hidden', !editable || nodes[index].kind !== 'image')
            toolbarImageBorderWrap?.classList.toggle('flex', editable && nodes[index].kind === 'image')
            // "Somente logo": só faz sentido num bloco de sistema com
            // Solução cadastrada E logo — texto livre, decisão/ator e um
            // sistema sem logo não têm imagem nenhuma pra mostrar sozinha.
            {
                const canLogoOnly = nodes[index].kind === 'system' && !!nodes[index].solution && !!nodes[index].logo
                toolbarLogoOnlyWrap?.classList.toggle('hidden', !editable || !canLogoOnly)
                toolbarLogoOnlyWrap?.classList.toggle('flex', editable && canLogoOnly)
            }
            // Tipo do bloco: fora do nó raiz (índice 0) e nunca numa imagem
            // colada — não tem tipo/Solução/texto pra trocar, só a imagem
            // (ver `ChainNodeKind::pickable()`).
            {
                const kindEditable = editable && index !== 0 && nodes[index].kind !== 'image'
                toolbarKindRow?.classList.toggle('hidden', !kindEditable)
                toolbarKindRow?.classList.toggle('flex', kindEditable)
                if (kindEditable) refreshKindRow(index)
                // "Renomear" abre exatamente o mesmo editor inline do duplo
                // clique, cujo guard (`startInlineLabelEdit()`) é esta mesma
                // condição — daí compartilharem o booleano em vez de repetir a
                // regra e poderem divergir depois.
                toolbarRenameBtn?.classList.toggle('!hidden', !kindEditable)
            }
            // A lixeira segue a mesma regra do tipo: o nó raiz não sai (o
            // servidor recusa índice 0 de qualquer forma).
            toolbarRemoveBtn?.classList.toggle('!hidden', !editable || index === 0)
            toolbarRemoveSep?.classList.toggle('hidden', !editable || index === 0)
            if (editable) {
                buildSwatches()
                refreshToolbarControls()
            }
        } else {
            toolbar?.classList.add('hidden')
            toolbar?.classList.remove('flex')
        }
    }

    // ── cor do bloco / cor do texto / fonte — só o bloco selecionado ──
    function buildSwatches() {
        if (!toolbarSwatches) return
        const current = nodes[selectedIndex]?.color
        toolbarSwatches.innerHTML = ''
        PALETTE.forEach((color) => {
            const sw = document.createElement('button')
            sw.type = 'button'
            sw.className = 'size-[22px] shrink-0 cursor-pointer rounded-md border border-black/10 transition-transform hover:scale-110'
            sw.style.background = color
            sw.title = color
            sw.style.boxShadow = current && current.toLowerCase() === color.toLowerCase()
                ? '0 0 0 2px var(--viz-bg), 0 0 0 3.5px var(--viz-select)'
                : ''
            sw.addEventListener('click', () => setNodeColor(color))
            toolbarSwatches.appendChild(sw)
        })
    }

    function refreshToolbarControls() {
        const n = nodes[selectedIndex]
        if (!n) return
        if (toolbarCustomColor) toolbarCustomColor.value = isHex(n.color) ? n.color : '#4A90D9'
        const effectiveTextColor = n.textColor || (n.color ? textColorFor(n.color) : '#1A1A2E')
        if (toolbarTextColor) toolbarTextColor.value = isHex(effectiveTextColor) ? effectiveTextColor : '#1A1A2E'
        if (toolbarTextColorWrap) toolbarTextColorWrap.style.color = effectiveTextColor
        if (toolbarFont) toolbarFont.value = n.font || 'sans'
        if (toolbarFontSize) toolbarFontSize.value = n.fontSize || 'sm'
        if (toolbarDashedBtn) {
            toolbarDashedBtn.classList.toggle('border-dashed', !!n.dashed)
            toolbarDashedBtn.classList.toggle('!bg-accent-soft', !!n.dashed)
        }
        // A cor do input reflete a borda atual, ou branco (o padrão sugerido
        // quando o usuário ainda não ligou a borda) — nunca o preto que um
        // <input type="color"> assume sozinho sem um value explícito.
        if (toolbarImageBorderColor) toolbarImageBorderColor.value = isHex(n.imageBorderColor) ? n.imageBorderColor : '#FFFFFF'
        if (toolbarImageBorderToggle) {
            toolbarImageBorderToggle.classList.toggle('!bg-accent-soft', !!n.imageBorderColor)
            toolbarImageBorderToggle.setAttribute('aria-pressed', String(!!n.imageBorderColor))
        }
        if (toolbarLogoOnlyToggle) {
            toolbarLogoOnlyToggle.classList.toggle('!bg-accent-soft', !!n.logoOnly)
            toolbarLogoOnlyToggle.setAttribute('aria-pressed', String(!!n.logoOnly))
        }
    }

    // Borda tracejada do bloco selecionado — puramente visual
    // (`viz_layout.nodes[i].dashed`), independente de cor/fonte/forma.
    toolbarDashedBtn?.addEventListener('click', () => {
        if (!editable || selectedIndex === null || !nodes[selectedIndex]) return
        nodes[selectedIndex].dashed = !nodes[selectedIndex].dashed
        applyNodeStyle(nodes[selectedIndex])
        refreshToolbarControls()
        setDirty(true)
    })

    // Borda leve da imagem — liga/desliga (branco por padrão na primeira
    // vez); só chega a fazer algo quando o bloco selecionado é uma imagem
    // (o próprio wrap já fica escondido pros demais tipos, mas a guarda
    // aqui evita qualquer clique perdido enquanto o painel troca de bloco).
    toolbarImageBorderToggle?.addEventListener('click', () => {
        if (!editable || selectedIndex === null || !nodes[selectedIndex] || nodes[selectedIndex].kind !== 'image') return
        const n = nodes[selectedIndex]
        n.imageBorderColor = n.imageBorderColor ? null : (toolbarImageBorderColor?.value || '#FFFFFF')
        applyNodeStyle(n)
        refreshToolbarControls()
        setDirty(true)
    })
    // Trocar a cor sempre implica "ligada" — não existe um estado
    // "desligada mas com uma cor guardada" pro usuário confundir.
    toolbarImageBorderColor?.addEventListener('input', (e) => {
        if (!editable || selectedIndex === null || !nodes[selectedIndex] || nodes[selectedIndex].kind !== 'image') return
        nodes[selectedIndex].imageBorderColor = e.target.value
        applyNodeStyle(nodes[selectedIndex])
        refreshToolbarControls()
        setDirty(true)
    })

    function setNodeColor(color) {
        if (!editable || selectedIndex === null || !nodes[selectedIndex]) return
        nodes[selectedIndex].color = color
        applyNodeStyle(nodes[selectedIndex])
        buildSwatches()
        refreshToolbarControls()
        setDirty(true)
    }

    function setNodeTextColor(color) {
        if (!editable || selectedIndex === null || !nodes[selectedIndex]) return
        nodes[selectedIndex].textColor = color
        applyNodeStyle(nodes[selectedIndex])
        refreshToolbarControls()
        setDirty(true)
    }

    function setNodeFont(font) {
        if (!editable || selectedIndex === null || !nodes[selectedIndex] || !FONTS[font]) return
        nodes[selectedIndex].font = font
        applyNodeStyle(nodes[selectedIndex])
        setDirty(true)
    }

    function setNodeFontSize(size) {
        if (!editable || selectedIndex === null || !nodes[selectedIndex] || !FONT_SIZES[size]) return
        nodes[selectedIndex].fontSize = size
        applyNodeStyle(nodes[selectedIndex])
        setDirty(true)
    }

    toolbarCustomColor?.addEventListener('input', (e) => setNodeColor(e.target.value))
    toolbarTextColor?.addEventListener('input', (e) => setNodeTextColor(e.target.value))
    toolbarFont?.addEventListener('change', (e) => setNodeFont(e.target.value))
    toolbarFontSize?.addEventListener('change', (e) => setNodeFontSize(e.target.value))

    // "Somente logo": troca estruturalmente o conteúdo do bloco (repintar via
    // `paintNode()`, não só um estilo inline) — por isso remede w/h e
    // redesenha as setas na sequência, mesmo padrão de `applyNodeData()`.
    toolbarLogoOnlyToggle?.addEventListener('click', () => {
        if (!editable || selectedIndex === null || !nodes[selectedIndex]) return
        const n = nodes[selectedIndex]
        if (n.kind !== 'system' || !n.solution || !n.logo) return
        n.logoOnly = !n.logoOnly
        paintNode(n.el, n)
        n.w = n.el.offsetWidth
        n.h = n.el.offsetHeight
        applyNodeStyle(n)
        refreshToolbarControls()
        draw()
        setDirty(true)
    })

    // ── "Adicionar bloco": um ícone por tipo, cria na hora ──────────────
    // Uma única linha horizontal de ícones (`getNodeKindsList()`, mesma lista
    // de `refreshKindRow()` mas sem seleção persistente — cada clique é uma
    // ação nova, não um toggle) — clicar um já cria o bloco
    // (`createNodeFromKind()`), sem Solução/texto livre pra preencher aqui:
    // isso vira `startInlineLabelEdit()` no bloco recém-criado, direto no
    // canvas, mesma UX de renomear um bloco já existente.
    function buildAddKindIcons() {
        if (!addKindIcons) return
        addKindIcons.innerHTML = ''
        getNodeKindsList().forEach((k) => {
            const btn = document.createElement('button')
            btn.type = 'button'
            btn.title = k.label
            btn.className = 'flex size-[30px] shrink-0 items-center justify-center rounded-md border border-line text-ink transition-colors hover:bg-accent-soft/60'
            if (k.icon) {
                const icon = document.createElement('span')
                icon.className = 'flex size-4 shrink-0 items-center justify-center [&>svg]:size-4'
                icon.innerHTML = k.icon
                btn.appendChild(icon)
            }
            btn.addEventListener('click', () => createNodeFromKind(k.value))
            addKindIcons.appendChild(btn)
        })
    }

    // POST direto — sem Solução/texto livre escolhidos ainda, então manda o
    // próprio nome do tipo ("Sistema", "Decisão", …) como texto inicial
    // (`início`/`fim` mandam `null`: o servidor já preenche "Início"/"Fim"
    // sozinho, `ChainNodeKind::defaultLabel()`). `startInlineLabelEdit()` logo
    // em seguida seleciona esse texto inteiro (mesmo `input.select()` de
    // sempre), então o usuário digita por cima sem nem precisar apagar nada —
    // pra um bloco `system`, digitar ali já é a busca de Solução de sempre.
    async function createNodeFromKind(kindValue) {
        if (!editable || !graphRef?.nodeAddUrl) return
        const kind = nodeKind(kindValue)
        const payload = { kind: kind.value, solution_id: null, label: kind.optionalLabel ? null : kind.label }

        // Capturados ANTES do request — `closeAddEditor()` zera
        // `quickAddPos`/`quickAddOrigin`, então lê-los depois seria tarde.
        const pos = quickAddPos
        const origin = quickAddOrigin

        try {
            const res = await fetch(graphRef.nodeAddUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível adicionar o bloco.')

            appendNode(data.node, pos)
            patchRowGraphAppend(slug, data.node, data.summary)
            const newIndex = nodes.length - 1
            closeAddEditor()
            // Veio de soltar uma seta em canvas vazio (`openQuickAddEditor()`)
            // — completa a ligação com o bloco recém-criado, mesmo POST que
            // soltar a seta sobre um bloco já existente usaria.
            if (origin) createEdgeFrom(origin.index, newIndex, origin.side, 'l')
            startInlineLabelEdit(newIndex)
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível adicionar o bloco.', 'error')
        }
    }

    // ── editor do bloco: tipo + select de Soluções cadastradas + texto livre ──
    // Aplica os campos resolvidos que vêm do servidor (mesmo formato de
    // `graph.nodes[i]`) num nó já desenhado, sem precisar redesenhar o grafo
    // inteiro. O tamanho do bloco pode mudar (texto novo) — recalcula w/h e
    // redesenha as arestas na sequência.
    function applyNodeData(index, data) {
        const n = nodes[index]
        if (!n) return
        Object.assign(n, {
            label: data.label,
            kind: data.kind || 'system',
            icon: data.icon ?? null,
            solution: data.solution,
            solutionId: data.solutionId ?? null,
            logo: data.logo,
            comment: data.comment ?? null,
        })
        paintNode(n.el, n)
        n.w = n.el.offsetWidth
        n.h = n.el.offsetHeight
        n.el.style.left = n.x + 'px'
        n.el.style.top = n.y + 'px'
        applyNodeStyle(n)
        draw()
    }

    // Mantém a linha (lista à esquerda) consistente sem precisar re-selecionar
    // a integração: atualiza o cache `data-integration-graph` daquela linha e
    // o texto do resumo, sem substituir o slot inteiro (o que derrubaria o
    // destaque de seleção — ver integration-select.js).
    function patchRowGraph(slugArg, index, nodeData, summary) {
        if (!slugArg) return
        const row = document.querySelector(`[data-ak-integration-select="${CSS.escape(slugArg)}"]`)
        if (!row) return

        const raw = row.getAttribute('data-integration-graph')
        if (raw) {
            try {
                const g = JSON.parse(raw)
                if (g?.nodes?.[index]) {
                    g.nodes[index] = { ...g.nodes[index], ...nodeData }
                    row.setAttribute('data-integration-graph', JSON.stringify(g))
                }
            } catch {
                // cache malformado — ignora, a próxima seleção completa recarrega do servidor
            }
        }
        if (typeof summary === 'string') {
            row.querySelector('[data-ak-integration-summary]')?.replaceChildren(document.createTextNode(summary))
        }
    }

    // Substitui o grafo cacheado da linha por inteiro, em vez de remendar um
    // nó/edge: é o que a exclusão de bloco precisa, já que os índices de TODOS
    // os nós acima do removido mudaram. Mesmo motivo de sempre para não usar
    // `updateSlots()` aqui — trocar o slot inteiro zera o `aria-pressed` e
    // derruba a seleção do usuário (ver `integration-select.js`).
    function patchRowGraphReplace(slugArg, graph, summary) {
        if (!slugArg || !graph) return
        const row = document.querySelector(`[data-ak-integration-select="${CSS.escape(slugArg)}"]`)
        if (!row) return

        row.setAttribute('data-integration-graph', JSON.stringify(graph))
        if (typeof summary === 'string') {
            row.querySelector('[data-ak-integration-summary]')?.replaceChildren(document.createTextNode(summary))
        }
    }

    // Mesma ideia de `patchRowGraph()`, mas para `chain.edges[i]` (protocolo
    // e/ou sentido) — o protocolo não entra no resumo textual da linha
    // (`ChainLabeler::label()` usa o sentido, não o protocolo), só o cache do
    // grafo precisa ser atualizado.
    function patchRowEdge(slugArg, index, protocolData, arrow) {
        if (!slugArg) return
        const row = document.querySelector(`[data-ak-integration-select="${CSS.escape(slugArg)}"]`)
        if (!row) return

        const raw = row.getAttribute('data-integration-graph')
        if (!raw) return
        try {
            const g = JSON.parse(raw)
            if (g?.edges?.[index]) {
                g.edges[index].protocol = protocolData
                if (arrow) g.edges[index].arrow = arrow
                row.setAttribute('data-integration-graph', JSON.stringify(g))
            }
        } catch {
            // cache malformado — ignora, a próxima seleção completa recarrega do servidor
        }
    }

    // Acrescenta (não substitui) uma ligação nova ao cache (`createEdgeFrom()`),
    // que não mexe em nós, só em `chain.edges`.
    function patchRowGraphAddEdge(slugArg, from, to, arrow, protocolData, summary) {
        if (!slugArg) return
        const row = document.querySelector(`[data-ak-integration-select="${CSS.escape(slugArg)}"]`)
        if (!row) return

        const raw = row.getAttribute('data-integration-graph')
        if (raw) {
            try {
                const g = JSON.parse(raw)
                if (g) {
                    g.edges = g.edges || []
                    g.edges.push({ from, to, arrow, protocol: protocolData })
                    row.setAttribute('data-integration-graph', JSON.stringify(g))
                }
            } catch {
                // cache malformado — ignora, a próxima seleção completa recarrega do servidor
            }
        }
        if (typeof summary === 'string') {
            row.querySelector('[data-ak-integration-summary]')?.replaceChildren(document.createTextNode(summary))
        }
    }

    // Remove uma ligação do cache (`protocolDelete` acima) — os nós não mudam.
    function patchRowGraphRemoveEdge(slugArg, index, summary) {
        if (!slugArg) return
        const row = document.querySelector(`[data-ak-integration-select="${CSS.escape(slugArg)}"]`)
        if (!row) return

        const raw = row.getAttribute('data-integration-graph')
        if (raw) {
            try {
                const g = JSON.parse(raw)
                if (g?.edges && index >= 0 && index < g.edges.length) {
                    g.edges.splice(index, 1)
                    row.setAttribute('data-integration-graph', JSON.stringify(g))
                }
            } catch {
                // cache malformado — ignora, a próxima seleção completa recarrega do servidor
            }
        }
        if (typeof summary === 'string') {
            row.querySelector('[data-ak-integration-summary]')?.replaceChildren(document.createTextNode(summary))
        }
    }

    // Mesma ideia, mas religando uma ponta (`from`/`to`) de uma ligação
    // existente pra outro nó — arrastar o handle da seta até outro bloco
    // (`retargetEdge()`).
    function patchRowGraphEdge(slugArg, edgeIndex, end, newNode, summary) {
        if (!slugArg) return
        const row = document.querySelector(`[data-ak-integration-select="${CSS.escape(slugArg)}"]`)
        if (!row) return

        const raw = row.getAttribute('data-integration-graph')
        if (raw) {
            try {
                const g = JSON.parse(raw)
                if (g?.edges?.[edgeIndex]) {
                    g.edges[edgeIndex][end] = newNode
                    row.setAttribute('data-integration-graph', JSON.stringify(g))
                }
            } catch {
                // cache malformado — ignora, a próxima seleção completa recarrega do servidor
            }
        }
        if (typeof summary === 'string') {
            row.querySelector('[data-ak-integration-summary]')?.replaceChildren(document.createTextNode(summary))
        }
    }

    // Um único PATCH em `graphRef.nodeUpdateUrl` por vez — troca de tipo,
    // troca de Solução e edição inline do rótulo passam todos por aqui.
    // Falha (rede/validação) repinta o bloco a partir do último estado
    // conhecido (`n`, ainda intacto — só o servidor confirma a mudança) em
    // vez de deixar o DOM com o valor não salvo que o usuário via na hora.
    let nodeFieldSaving = false
    async function patchNode(index, payload) {
        const n = nodes[index]
        const url = graphRef?.nodeUpdateUrl?.replace('NODE_INDEX', String(index))
        if (nodeFieldSaving || !n || !url) return

        nodeFieldSaving = true
        try {
            const res = await fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível atualizar o bloco.')

            applyNodeData(index, data.node)
            patchRowGraph(slug, index, data.node, data.summary)
            // Reavalia a toolbar inteira, não só a linha de tipo — trocar de
            // tipo/Solução pode ligar/desligar "somente logo" e a borda leve
            // de imagem, que `selectNode()` já sabe decidir de um jeito só.
            if (selectedIndex === index) selectNode(index)
            window.Toast?.show?.(data.message || 'Bloco atualizado.')
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível atualizar o bloco.', 'error')
            paintNode(n.el, n)
            n.w = n.el.offsetWidth
            n.h = n.el.offsetHeight
            applyNodeStyle(n)
            draw()
        } finally {
            nodeFieldSaving = false
        }
    }

    // Payload de uma troca de TIPO só — preserva o que já existe: um bloco
    // que passa a ser `system` mantém a Solução já ligada (ou, sem Solução,
    // o texto atual como livre); um bloco que deixa de ser `system` carrega
    // o texto resolvido atual (nome da Solução ou texto livre) como novo
    // texto livre, já que decisão/ator/início/fim nunca referenciam Solução.
    function buildKindSwitchPayload(n, newKindValue) {
        const kind = nodeKind(newKindValue)
        if (kind.system) {
            return n.solutionId
                ? { kind: newKindValue, solution_id: n.solutionId, label: null }
                : { kind: newKindValue, solution_id: null, label: n.label || null }
        }
        const label = n.label || null
        if (!label && !kind.optionalLabel) return null
        return { kind: newKindValue, solution_id: null, label }
    }

    function changeNodeKind(newValue) {
        if (nodeFieldSaving || selectedIndex === null || !nodes[selectedIndex]) return
        const n = nodes[selectedIndex]
        if ((n.kind || 'system') === newValue) return
        const payload = buildKindSwitchPayload(n, newValue)
        if (!payload) return
        patchNode(selectedIndex, payload)
    }

    // Segunda linha da toolbar: só os ícones do tipo (`getNodeKindsList()`,
    // mesma lista e mesmo estilo de `buildAddKindIcons()` no painel
    // "Adicionar bloco" — a diferença é que aqui existe uma seleção ATUAL pra
    // destacar, ali cada clique é uma ação nova). A Solução de um bloco
    // `system` não tem controle próprio aqui — liga/troca direto no
    // autocomplete da edição inline (`startInlineLabelEdit()`), que é mais
    // intuitivo que um select separado.
    function refreshKindRow(index) {
        const n = nodes[index]
        if (!toolbarKindIcons || !n) return
        const currentKind = n.kind || 'system'

        toolbarKindIcons.innerHTML = ''
        getNodeKindsList().forEach((k) => {
            const active = k.value === currentKind
            const btn = document.createElement('button')
            btn.type = 'button'
            btn.title = k.label
            btn.setAttribute('aria-pressed', String(active))
            btn.className = 'flex size-[26px] shrink-0 items-center justify-center rounded-md border transition-colors ' +
                (active
                    ? 'border-accent bg-accent-soft text-accent'
                    : 'border-line text-ink hover:bg-accent-soft/60')
            if (k.icon) {
                const icon = document.createElement('span')
                icon.className = 'flex size-4 shrink-0 items-center justify-center [&>svg]:size-4'
                icon.innerHTML = k.icon
                btn.appendChild(icon)
            }
            btn.addEventListener('click', () => changeNodeKind(k.value))
            toolbarKindIcons.appendChild(btn)
        })
    }

    // ── edição inline do rótulo, direto na forma ────────────────────
    // Duplo clique no texto do bloco troca o `<span>` estático (ou
    // `.ak-viz-node-endcap-label`) por um `<input>` no lugar — sem popup
    // separado. Num bloco `system` (`isSystem`), a digitação também filtra
    // `getSolutionsList()` num dropdown ancorado ao próprio bloco
    // (`.ak-viz-inline-suggest`, filho de `n.el` — herda o mesmo `transform`
    // de pan/zoom do canvas de graça, sem matemática de posição própria):
    // escolher uma sugestão (clique, ou Enter com uma sugestão em destaque)
    // liga a Solução; se o texto digitado bater EXATAMENTE (sem diferenciar
    // caixa) com o nome de alguma Solução ao confirmar, liga também, mesmo
    // sem ter clicado numa sugestão; qualquer outro texto vira texto livre —
    // nunca mantém um `solution_id` antigo junto de um texto novo (ver
    // `ChainLabeler::nodeLabel()`: o nome de uma Solução sempre vence sobre
    // o texto livre, então os dois nunca convivem). Decisão/ator/início/fim
    // não têm Solução pra buscar — sem dropdown, só o texto.
    function startInlineLabelEdit(index) {
        const n = nodes[index]
        if (!n || !editable || index === 0 || n.kind === 'image') return
        const textEl = n.el.querySelector('.ak-viz-node-text, .ak-viz-node-endcap-label')
        if (!textEl) return
        selectNode(index)

        const isSystem = (n.kind || 'system') === 'system'
        const input = document.createElement('input')
        input.type = 'text'
        input.className = textEl.className + ' ak-viz-node-text-input'
        input.autocomplete = 'off'
        input.spellcheck = false
        input.value = textEl.textContent
        textEl.replaceWith(input)
        input.focus()
        input.select()

        const suggestBox = isSystem ? document.createElement('div') : null
        if (suggestBox) {
            suggestBox.className = 'ak-viz-inline-suggest hidden'
            n.el.appendChild(suggestBox)
        }
        let matches = []
        let highlighted = -1

        function autosize() {
            input.size = Math.max(4, input.value.length + 1)
        }

        function paintHighlight() {
            Array.from(suggestBox.children).forEach((el, i) => el.classList.toggle('is-active', i === highlighted))
        }

        function renderMatches() {
            if (!suggestBox) return
            const term = input.value.trim().toLowerCase()
            matches = term ? getSolutionsList().filter((s) => s.name.toLowerCase().includes(term)).slice(0, 8) : []
            highlighted = -1
            suggestBox.innerHTML = ''
            suggestBox.classList.toggle('hidden', !matches.length)
            matches.forEach((s, i) => {
                const item = document.createElement('button')
                item.type = 'button'
                item.className = 'ak-viz-inline-suggest-item'
                item.textContent = s.name
                // `mousedown` (não `click`): dispara ANTES do `blur` do
                // input, e `preventDefault()` aqui impede esse blur de
                // sequer ocorrer — sem isso, o input perderia o foco (e
                // chamaria `resolve()` pelo caminho de texto livre) antes
                // do clique na sugestão ser processado.
                item.addEventListener('mousedown', (e) => { e.preventDefault(); resolve(s) })
                suggestBox.appendChild(item)
            })
        }

        function cleanup() {
            input.removeEventListener('input', onInput)
            input.removeEventListener('keydown', onKeydown)
            input.removeEventListener('blur', onBlur)
            suggestBox?.remove()
        }

        // `solution` explícita = veio de um clique/Enter na sugestão. Nula =
        // veio de Enter/blur com só texto digitado — tenta o match exato
        // antes de desistir e virar texto livre.
        function resolve(solution) {
            cleanup()
            if (!solution && isSystem) {
                const typed = input.value.trim().toLowerCase()
                solution = getSolutionsList().find((s) => s.name.toLowerCase() === typed) || null
            }
            if (solution) {
                if (isSystem && n.solutionId === solution.id) { paintNode(n.el, n); applyNodeStyle(n); return }
                patchNode(index, { kind: 'system', solution_id: solution.id, label: null })
                return
            }
            const typed = input.value.trim()
            if (typed === (n.label || '')) { paintNode(n.el, n); applyNodeStyle(n); return }
            commitInlineLabel(index, typed)
        }

        function cancel() {
            cleanup()
            paintNode(n.el, n)
            applyNodeStyle(n)
        }

        const onInput = () => { autosize(); renderMatches() }
        const onKeydown = (e) => {
            if (e.key === 'Escape') { e.preventDefault(); cancel(); return }
            if (e.key === 'Enter') {
                e.preventDefault()
                resolve(highlighted >= 0 ? matches[highlighted] : null)
                return
            }
            if (suggestBox && matches.length && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                e.preventDefault()
                highlighted = e.key === 'ArrowDown'
                    ? Math.min(highlighted + 1, matches.length - 1)
                    : Math.max(highlighted - 1, 0)
                paintHighlight()
            }
        }
        const onBlur = () => resolve(highlighted >= 0 ? matches[highlighted] : null)

        autosize()
        renderMatches()
        input.addEventListener('input', onInput)
        input.addEventListener('keydown', onKeydown)
        input.addEventListener('blur', onBlur)
    }

    // Caminho de "texto livre" da edição inline (`startInlineLabelEdit()`)
    // — sempre com `solution_id: null`: mesmo num bloco que ANTES estava
    // ligado a uma Solução, confirmar aqui é o usuário dizendo "não é mais
    // essa Solução", nunca "mantenha a Solução e troque só o texto" (que,
    // pela regra do `ChainLabeler`, o servidor ignoraria de qualquer jeito).
    function commitInlineLabel(index, newLabel) {
        const n = nodes[index]
        if (!n) return
        const kind = nodeKind(n.kind || 'system')
        if (!newLabel && !kind.optionalLabel) {
            window.Toast?.show?.('Informe o texto do bloco.', 'warning')
            paintNode(n.el, n)
            applyNodeStyle(n)
            return
        }
        patchNode(index, { kind: n.kind || 'system', solution_id: null, label: newLabel || null })
    }

    // ── adicionar bloco (puro, sem ligação) ────────────────────────
    // Painel fixo no canto do canvas (estilo excalidraw.com — o mesmo canto
    // que a toolbar do bloco/raia/protocolo usa, mutuamente exclusivo com
    // elas), com os mesmos campos do editor do bloco (tipo + Solução/texto
    // livre) e nada mais: sem seta, sem protocolo. O bloco nasce solto e o
    // usuário liga depois, arrastando uma seta da porta de qualquer bloco até
    // ele.
    function openAddEditor() {
        if (!editable || !graphRef) return
        selectNode(null)
        closeProtocolEditor()
        quickAddOrigin = null
        quickAddPos = null

        addEditor?.classList.remove('hidden')
        addEditor?.classList.add('flex')
        buildAddKindIcons()
    }

    // MESMO painel, aberto ao soltar uma seta puxada de uma porta (`drag.type
    // === 'connect'`) sobre canvas vazio, em vez do botão "+" — ver o
    // `mouseup` de `drag.type === 'connect'` mais abaixo. `fromIndex`/
    // `fromSide` é a porta de origem (pra ligar depois de criar, ver
    // `createNodeFromKind()` acima); `wx`/`wy` é o ponto de MUNDO onde soltar,
    // pra o bloco novo nascer exatamente ali (não à direita do último) — o
    // painel em si abre sempre no mesmo canto fixo, não no ponto do drop.
    function openQuickAddEditor(fromIndex, fromSide, wx, wy) {
        if (!editable || !graphRef) return
        selectNode(null)
        closeProtocolEditor()
        quickAddOrigin = { index: fromIndex, side: fromSide }
        quickAddPos = { x: wx, y: wy }

        addEditor?.classList.remove('hidden')
        addEditor?.classList.add('flex')
        buildAddKindIcons()
    }

    function closeAddEditor() {
        // A prévia tracejada da ligação pendente (`drawConnectPreview()`)
        // só existe enquanto `quickAddOrigin` está preenchido — sem este
        // redesenho, cancelar o quick-add deixaria a linha tracejada
        // pendurada na tela até o próximo `draw()` por outro motivo.
        const hadQuickAdd = !!quickAddOrigin
        quickAddOrigin = null
        quickAddPos = null
        if (hadQuickAdd) draw()
        if (!addEditor || addEditor.classList.contains('hidden')) return
        addEditor.classList.add('hidden')
        addEditor.classList.remove('flex')
    }

    addNodeBtn?.addEventListener('click', () => {
        if (addEditor?.classList.contains('hidden')) openAddEditor()
        else closeAddEditor()
    })
    addEditor?.addEventListener('pointerdown', (e) => e.stopPropagation())

    // Desenha o bloco novo e o seleciona — sem redesenhar o grafo inteiro.
    // Nasce SEM LIGAÇÃO nenhuma: quem liga é o arraste da porta (ou o "modo
    // ligar", ou religar uma seta existente até ele) — EXCETO quando vem de
    // `openQuickAddEditor()` (soltar uma seta em canvas vazio), que liga logo
    // em seguida (ver `createNodeFromKind()` acima). Fica dirty (a posição ainda não está
    // salva em `viz_layout`), mesmo espírito de `organize()`. `pos` (ponto de
    // MUNDO) centra o bloco ali — vem preenchido só nesse fluxo de soltar a
    // seta; sem ele, nasce à direita do último bloco (mesmo espaçamento do
    // layout padrão), o comportamento de sempre do "+" da topbar.
    function appendNode(data, pos) {
        const index = nodes.length
        const el = document.createElement('div')
        el.className = 'ak-viz-node'
        paintNode(el, data)
        el.addEventListener('pointerdown', (e) => startNodePointer(e, index))
        el.addEventListener('dblclick', () => startInlineLabelEdit(index))
        world.appendChild(el)

        const prev = nodes[index - 1]
        const entry = { ...data, el, w: 0, h: 0, x: 0, y: 0, color: null, textColor: null, font: 'sans', fontSize: 'sm', imageBorderColor: null }
        nodes.push(entry)
        entry.w = el.offsetWidth
        entry.h = el.offsetHeight
        if (pos) {
            entry.x = pos.x - entry.w / 2
            entry.y = pos.y - entry.h / 2
        } else {
            entry.x = prev ? prev.x + prev.w + LEVEL_GAP : 0
            entry.y = prev ? prev.y + prev.h / 2 - entry.h / 2 : 0
        }
        el.style.left = entry.x + 'px'
        el.style.top = entry.y + 'px'
        applyNodeStyle(entry)

        if (graphRef) {
            graphRef.nodes = graphRef.nodes || []
            graphRef.nodes.push(data)
        }

        // A pasted image's <img> loads asynchronously (a real request to
        // /files/{id}) — `entry.w`/`h` above can still be 0×0 at this point,
        // so `anchorPoint()` divides every side down to the same point and
        // any arrow dragged to/from this node right after pasting resolves
        // to the same degenerate anchor no matter which side was actually
        // pulled from. Remeasure once it actually loads and redraw —
        // recentering on `pos` too, since it was centered using the wrong
        // (zero) size the first time.
        const img = el.querySelector('img')
        if (img && !img.complete) {
            img.addEventListener('load', () => {
                const newW = el.offsetWidth
                const newH = el.offsetHeight
                if (pos) {
                    entry.x -= (newW - entry.w) / 2
                    entry.y -= (newH - entry.h) / 2
                    el.style.left = entry.x + 'px'
                    el.style.top = entry.y + 'px'
                }
                entry.w = newW
                entry.h = newH
                if (!presenting) draw() // ver o mesmo guard/motivo no listener de imagem em render()
            }, { once: true })
        }

        draw()
        setDirty(true)
        selectNode(index)
        fit()
    }

    // Mesma ideia de `patchRowGraph()`, mas acrescentando (não substituindo)
    // um nó — mantém a linha (lista à esquerda) consistente sem precisar
    // re-selecionar a integração. Não mexe em `edges`: o bloco nasce solto.
    function patchRowGraphAppend(slugArg, nodeData, summary) {
        if (!slugArg) return
        const row = document.querySelector(`[data-ak-integration-select="${CSS.escape(slugArg)}"]`)
        if (!row) return

        const raw = row.getAttribute('data-integration-graph')
        if (raw) {
            try {
                const g = JSON.parse(raw)
                if (g) {
                    g.nodes = g.nodes || []
                    g.nodes.push(nodeData)
                    row.setAttribute('data-integration-graph', JSON.stringify(g))
                }
            } catch {
                // cache malformado — ignora, a próxima seleção completa recarrega do servidor
            }
        }
        if (typeof summary === 'string') {
            row.querySelector('[data-ak-integration-summary]')?.replaceChildren(document.createTextNode(summary))
        }
    }

    // ── ligação: sentido + protocolo do enum Protocol ───────────────────
    // Painel fixo no canto do canvas (`selectEdge()`/`openProtocolEditor()`),
    // mesmo estilo do toolbar do bloco/raia — nunca ancorado à pill do
    // segmento — sentido e tracejado aplicam-se IMEDIATAMENTE (sem "Salvar"/
    // "Cancelar"), mesmo espírito do toolbar do bloco e do toolbar da raia.
    // O protocolo em si não tem campo aqui: é editado direto no rótulo da
    // seta, no canvas (`startInlineProtocolEdit()`, mais abaixo — duplo
    // clique na pill, mesmo padrão de `startInlineLabelEdit()` no texto do
    // bloco). Ao contrário do nó, não existe segmento "raiz" protegido —
    // qualquer aresta pode ter seu protocolo/sentido editados, inclusive as
    // que ainda não têm um protocolo (pill tracejada "+ protocolo", desenhada
    // em `drawProtocolPill()`).

    // ── sentido da ligação: dois botões-toggle independentes ───────────
    // `left`/`right` espelham se cada cabeça de seta está ativa —
    // `refreshArrowButtons()` só pinta o estado atual, `setArrowUI()` o
    // recebe pronto (abrindo o editor), `toggleArrowSide()` responde ao
    // clique E já dispara o PATCH. `currentArrowValue()` é a única leitura
    // que `patchEdgeFields()`/`createEdgeFrom()` fazem — nunca leem
    // `arrowState` diretamente.
    function refreshArrowButtons() {
        ;[[protocolArrowLeft, arrowState.left], [protocolArrowRight, arrowState.right]].forEach(([btn, active]) => {
            if (!btn) return
            btn.classList.toggle('border-accent', active)
            btn.classList.toggle('bg-accent-soft', active)
            btn.classList.toggle('text-accent', active)
            btn.classList.toggle('border-line', !active)
            btn.classList.toggle('text-ink', !active)
            btn.setAttribute('aria-pressed', String(active))
        })
    }

    function setArrowUI(arrow) {
        arrowState = { left: arrow === '<-' || arrow === '<->', right: arrow === '->' || arrow === '<->' }
        refreshArrowButtons()
    }

    // Ignora o clique que desligaria a última cabeça ativa — '->'/'<-'/'<->'
    // são os únicos sentidos válidos, não existe "sem cabeça nenhuma" pro
    // servidor guardar. Aplica na hora (PATCH) — pinta o novo estado
    // otimisticamente e desfaz (`onError`) se o servidor recusar.
    function toggleArrowSide(side) {
        if (selectedEdge === null) return
        const next = { ...arrowState, [side]: !arrowState[side] }
        if (!next.left && !next.right) {
            // Silently refusing this click would just look like a broken
            // button — say why nothing moved, same spirit as the other
            // blocked-action warnings in this file (e.g. `commitInlineLabel()`).
            window.Toast?.show?.('Pelo menos um sentido precisa ficar ativo.', 'warning')
            return
        }
        const prev = arrowState
        arrowState = next
        refreshArrowButtons()
        const index = selectedEdge
        const protocol = graphRef?.edges?.[index]?.protocol?.value ?? null
        patchEdgeFields(index, { protocol, arrow: currentArrowValue() }, () => {
            arrowState = prev
            refreshArrowButtons()
        })
    }

    function currentArrowValue() {
        if (arrowState.left && arrowState.right) return '<->'
        return arrowState.left ? '<-' : '->'
    }

    protocolArrowLeft?.addEventListener('click', () => toggleArrowSide('left'))
    protocolArrowRight?.addEventListener('click', () => toggleArrowSide('right'))

    // Tracejado da seta — só `viz_layout` (nunca `chain`), mesmo padrão do
    // `toolbarDashedBtn` do bloco: aplica local + `setDirty(true)`, sem PATCH
    // próprio — entra no ar só quando "Salvar" (layout) rodar. A borda do
    // próprio botão (sólida/tracejada) reflete o estado atual — sem
    // checkbox.
    function refreshProtocolDashedButton(index) {
        if (!protocolDashedBtn) return
        const dashed = !!edgeAnchors[index]?.dashed
        protocolDashedBtn.classList.toggle('border-dashed', dashed)
        protocolDashedBtn.classList.toggle('!bg-accent-soft', dashed)
    }

    protocolDashedBtn?.addEventListener('click', () => {
        if (!editable || selectedEdge === null || !edgeAnchors[selectedEdge]) return
        edgeAnchors[selectedEdge].dashed = !edgeAnchors[selectedEdge].dashed
        draw()
        refreshProtocolDashedButton(selectedEdge)
        setDirty(true)
    })

    function selectEdge(index) {
        if (!editable) return
        selectNode(null) // exclusão mútua com seleção de nó/editor de título
        selectedEdge = index
        openProtocolEditor(index)
    }

    function openProtocolEditor(index) {
        if (!protocolEditor || !graphRef) return
        const edge = graphRef.edges?.[index]

        setArrowUI(edge?.arrow || '->')
        refreshProtocolDashedButton(index)

        protocolEditor.classList.remove('hidden')
        protocolEditor.classList.add('flex')
    }

    function closeProtocolEditor() {
        closeInlineProtocolEdit()
        if (!protocolEditor || protocolEditor.classList.contains('hidden')) return
        protocolEditor.classList.add('hidden')
        protocolEditor.classList.remove('flex')
        selectedEdge = null
    }

    protocolEditor?.addEventListener('pointerdown', (e) => e.stopPropagation())

    // Um único PATCH em `graphRef.edgeUpdateUrl` por vez — sentido (toggle)
    // e protocolo (edição inline no rótulo) passam os dois por aqui, cada um
    // aplicando na hora, sem um "Salvar" separado (mesmo espírito de
    // `patchNode()` pro bloco). `onError`, se passado, desfaz a mudança já
    // pintada otimisticamente na UI antes do PATCH voltar.
    let edgeFieldSaving = false
    async function patchEdgeFields(index, payload, onError = null) {
        const url = graphRef?.edgeUpdateUrl?.replace('EDGE_INDEX', String(index))
        if (edgeFieldSaving || !graphRef || !url) return

        edgeFieldSaving = true
        try {
            const res = await fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível atualizar a ligação.')

            if (graphRef.edges?.[index]) {
                graphRef.edges[index].protocol = data.protocol
                graphRef.edges[index].arrow = data.arrow
            }
            patchRowEdge(slug, index, data.protocol, data.arrow)
            draw()
            setDirty(true)
            window.Toast?.show?.(data.message || 'Ligação atualizada.')
            if (selectedEdge === index) setArrowUI(data.arrow)
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível atualizar a ligação.', 'error')
            onError?.()
            draw()
        } finally {
            edgeFieldSaving = false
        }
    }

    // ── edição inline do protocolo, direto no rótulo da seta ────────────
    // Duplo clique na pill (`drawProtocolPill()`) troca o texto SVG estático
    // por um `<input>` flutuante sobreposto a ela — mesma ideia de
    // `startInlineLabelEdit()` no bloco, adaptada porque a pill vive dentro
    // de `<svg data-viz-edges>` (um filho HTML comum não entra num `<g>` sem
    // um `<foreignObject>`): o input e sua lista de sugestões são filhos de
    // `stage` (espaço de TELA), reancorados a cada `applyView()`/`draw()`
    // via `inlineProtocolReposition` — mesma convenção da toolbar/editor de
    // protocolo. Sugestões vêm de `getProtocolsList()` — texto livre,
    // qualquer coisa digitada é aceita, a lista é só sugestão.
    function startInlineProtocolEdit(index) {
        if (!editable || !graphRef || !graphRef.edges?.[index]) return
        closeInlineProtocolEdit()
        selectEdge(index) // mantém o painel de sentido/tracejado/desligar aberto também

        inlineProtocolEditIndex = index
        draw() // esconde o texto estático desta pill (ver `drawProtocolPill()`)

        const input = document.createElement('input')
        input.type = 'text'
        input.autocomplete = 'off'
        input.spellcheck = false
        input.className = 'ak-viz-plabel-input'
        input.value = graphRef.edges[index]?.protocol?.value ?? ''
        stage.appendChild(input)

        const suggestBox = document.createElement('div')
        suggestBox.className = 'ak-viz-plabel-suggest hidden'
        stage.appendChild(suggestBox)

        let matches = []
        let highlighted = -1

        function position() {
            const g = edgeLabelEls[index]
            if (!g) return
            const rect = g.getBoundingClientRect()
            const stageRect = stage.getBoundingClientRect()
            const left = rect.left - stageRect.left
            const top = rect.top - stageRect.top
            input.style.left = left + 'px'
            input.style.top = top + 'px'
            input.style.width = rect.width + 'px'
            input.style.height = rect.height + 'px'
            suggestBox.style.left = left + 'px'
            suggestBox.style.top = (top + rect.height + 4) + 'px'
        }

        function paintHighlight() {
            Array.from(suggestBox.children).forEach((el, i) => el.classList.toggle('is-active', i === highlighted))
        }

        function renderMatches() {
            const term = input.value.trim().toLowerCase()
            const all = getProtocolsList()
            matches = (term ? all.filter((p) => p.label.toLowerCase().includes(term)) : all).slice(0, 8)
            highlighted = -1
            suggestBox.innerHTML = ''
            suggestBox.classList.toggle('hidden', !matches.length)
            matches.forEach((p, i) => {
                const item = document.createElement('button')
                item.type = 'button'
                item.className = 'ak-viz-plabel-suggest-item'
                item.textContent = p.label
                // `mousedown` (não `click`) + `preventDefault()`, mesmo
                // motivo de `startInlineLabelEdit()`: dispara antes do
                // `blur` do input, que senão já teria resolvido via texto
                // livre antes do clique na sugestão ser processado.
                item.addEventListener('mousedown', (e) => { e.preventDefault(); resolve(p.label) })
                suggestBox.appendChild(item)
            })
        }

        // Só desmonta o `<input>`/sugestões e restaura o texto estático da
        // pill (sem um `draw()` completo) — `patchEdgeFields()`, se chamado
        // por `resolve()`, faz seu PRÓPRIO `draw()` quando o servidor
        // confirmar, com o valor já atualizado.
        function cleanup() {
            input.removeEventListener('input', onInput)
            input.removeEventListener('keydown', onKeydown)
            input.removeEventListener('blur', onBlur)
            input.remove()
            suggestBox.remove()
            inlineProtocolInput = null
            inlineProtocolReposition = null
            inlineProtocolEditIndex = null
            const text = edgeLabelEls[index]?.querySelector('.ak-viz-plabel-text')
            if (text) text.style.opacity = ''
        }

        function resolve(text) {
            const typed = (text ?? input.value).trim()
            cleanup()
            const current = graphRef.edges?.[index]?.protocol?.value ?? ''
            if (typed === current) return
            const arrow = graphRef.edges?.[index]?.arrow || '->'
            patchEdgeFields(index, { protocol: typed || null, arrow })
        }

        function cancel() {
            cleanup()
        }

        const onInput = () => renderMatches()
        const onKeydown = (e) => {
            if (e.key === 'Escape') { e.preventDefault(); cancel(); return }
            if (e.key === 'Enter') {
                e.preventDefault()
                resolve(highlighted >= 0 ? matches[highlighted].label : null)
                return
            }
            if (matches.length && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                e.preventDefault()
                highlighted = e.key === 'ArrowDown'
                    ? Math.min(highlighted + 1, matches.length - 1)
                    : Math.max(highlighted - 1, 0)
                paintHighlight()
            }
        }
        const onBlur = () => resolve(highlighted >= 0 ? matches[highlighted].label : null)

        inlineProtocolInput = input
        inlineProtocolReposition = position
        position()
        renderMatches()
        input.addEventListener('input', onInput)
        input.addEventListener('keydown', onKeydown)
        input.addEventListener('blur', onBlur)
        input.focus()
        input.select()
    }

    // Fecha uma edição inline em andamento (se houver), forçando o `blur` do
    // input — reaproveita o mesmo caminho de confirmação/PATCH do Enter/blur
    // natural (`resolve()` acima) em vez de duplicar a lógica aqui.
    function closeInlineProtocolEdit() {
        inlineProtocolInput?.blur()
    }

    // Acrescenta ao grafo local uma ligação recém-criada (`createEdgeFrom()`),
    // NO ÍNDICE QUE O SERVIDOR deu a ela (`data.index`). Todo o resto do
    // editor de ligação (protocolo, religar, desligar) endereça edge POR
    // ÍNDICE, então inferir o índice pela ordem de inserção local desalinha
    // tudo silenciosamente quando dois POSTs estão em voo e as respostas
    // chegam fora de ordem. O `creatingEdge` das chamadas impede esse
    // cenário; a checagem de comprimento aqui é a asserção disso — e cobre
    // também o caso de outra pessoa ter mexido na mesma integração enquanto
    // esta aba estava aberta. Preferimos não desenhar (e pedir reload) a
    // desenhar com índice errado.
    function appendEdgeLocally(data, fromSide, toSide, dashed = false) {
        graphRef.edges = graphRef.edges || []

        if (data.index !== graphRef.edges.length) {
            window.Toast?.show?.('A ligação foi criada, mas este desenho está defasado — recarregue a página.', 'warning')
            return false
        }

        graphRef.edges.push({ from: data.from, to: data.to, arrow: data.arrow, protocol: data.protocol })
        edgeAnchors.push({ from: fromSide, to: toSide, dashed })
        return true
    }

    // Remove a ligação em edição — DELETE em
    // `graphRef.edgeRemoveUrl`. Os nós continuam existindo; se essa era a
    // única ligação de um bloco, ele passa a aparecer isolado no grafo.
    protocolDelete?.addEventListener('click', async () => {
        if (selectedEdge === null) return
        if (!window.confirm('Desligar esta ligação? Os blocos continuam existindo.')) return

        const index = selectedEdge
        const url = graphRef?.edgeRemoveUrl?.replace('EDGE_INDEX', String(index))
        if (!url) return

        protocolDelete.disabled = true
        try {
            const res = await fetch(url, {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível desligar a ligação.')

            graphRef.edges?.splice(index, 1)
            edgeAnchors.splice(index, 1)
            patchRowGraphRemoveEdge(slug, index, data.summary)
            closeProtocolEditor()
            draw()
            window.Toast?.show?.(data.message || 'Ligação removida.')
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível desligar a ligação.', 'error')
        } finally {
            protocolDelete.disabled = false
        }
    })

    // ── excluir bloco ──────────────────────────────────────────────
    // Diferente de tudo o mais que edita a chain, aqui NÃO existe patch local
    // possível: tirar um nó reindexa `chain.nodes`, e com ela todo `from`/`to`
    // de `chain.edges` acima do índice removido — mais as posições, comentários
    // e âncoras em `viz_layout`. O servidor faz esse reindex e devolve o GRAFO
    // INTEIRO já resolvido (mesma forma do `data-integration-graph` inicial),
    // então o caminho honesto é redesenhar com `render()` em vez de tentar
    // remendar os arrays locais. Ver `SolutionIntegrationController::removeNode()`.
    toolbarRemoveBtn?.addEventListener('click', async () => {
        if (!editable || selectedIndex === null || selectedIndex === 0) return

        const index = selectedIndex
        const label = nodes[index]?.label || 'este bloco'
        // Quantas ligações vão embora junto — o usuário decide sabendo disso.
        const linked = (graphRef?.edges || []).filter((e) => e.from === index || e.to === index).length
        const warning = linked
            ? `\n\n${linked} ${linked === 1 ? 'ligação será removida' : 'ligações serão removidas'} junto.`
            : ''
        if (!window.confirm(`Excluir "${label}"?${warning}`)) return

        const url = graphRef?.nodeRemoveUrl?.replace('NODE_INDEX', String(index))
        if (!url) return

        toolbarRemoveBtn.disabled = true
        try {
            const res = await fetch(url, {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível excluir o bloco.')

            // O layout salvo na sessão está indexado pela contagem ANTIGA de
            // nós; deixá-lo no cache faria `render()` reaplicá-lo por cima do
            // layout já reindexado que veio do servidor.
            savedLayouts.delete(slug)
            patchRowGraphReplace(slug, data.graph, data.summary)
            render(data.graph, currentName, slug)
            window.Toast?.show?.(data.message || 'Bloco excluído.')
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível excluir o bloco.', 'error')
        } finally {
            toolbarRemoveBtn.disabled = false
        }
    })

    // ── sidebar de comentário (markdown) ───────────────────────────
    function isSidebarOpen() {
        return !!sidebar && !sidebar.classList.contains('translate-x-full')
    }

    // Desloca o rodapé de zoom para não ficar por baixo da sidebar aberta
    // (mesmo ajuste que o mapa mental de referência faz com #zoomctl).
    function positionBottomBar() {
        if (!bottomBar) return
        const shift = isSidebarOpen() ? sidebar.offsetWidth / 2 : 0
        bottomBar.style.transform = shift ? `translateX(calc(-50% - ${shift}px))` : ''
    }

    function openComment(index) {
        const n = nodes[index]
        if (!n || !sidebar) return
        commentIndex = index
        if (sidebarNode) sidebarNode.textContent = n.label ?? ''
        if (sidebarInput) {
            sidebarInput.value = n.comment || ''
            sidebarInput.readOnly = !editable
        }
        renderCommentPreview()
        sidebar.classList.remove('translate-x-full')
        positionBottomBar()
    }

    function closeComment() {
        if (!sidebar) return
        sidebar.classList.add('translate-x-full')
        commentIndex = null
        positionBottomBar()
    }

    function renderCommentPreview() {
        if (sidebarPreview) sidebarPreview.innerHTML = renderMarkdown(sidebarInput?.value ?? '')
    }

    sidebarInput?.addEventListener('input', () => {
        renderCommentPreview()
        if (commentIndex !== null && nodes[commentIndex] && editable) {
            const value = sidebarInput.value
            nodes[commentIndex].comment = value
            nodes[commentIndex].el.classList.toggle('has-comment', !!value.trim())
            setDirty(true)
        }
    })
    sidebar?.addEventListener('pointerdown', (e) => e.stopPropagation())
    sidebarClose?.addEventListener('click', closeComment)

    toolbar?.addEventListener('pointerdown', (e) => e.stopPropagation())
    toolbarComment?.addEventListener('click', () => { if (selectedIndex !== null) openComment(selectedIndex) })
    toolbarRenameBtn?.addEventListener('click', () => { if (selectedIndex !== null) startInlineLabelEdit(selectedIndex) })

    // ── puxar uma seta de uma porta do bloco (ligação nova) ────────
    // Disponível em TODOS os blocos, inclusive o raiz: a ligação não existe
    // ainda enquanto o mouse está pressionado — só a prévia tracejada
    // (`drawConnectPreview()`). Soltar sobre outro bloco cria a ligação
    // (`createEdgeFrom()`); soltar fora de qualquer bloco, ou sobre o próprio
    // bloco de origem, cancela sem efeito nenhum.
    function startPortDrag(e, index, side) {
        if (e.button !== 0) return // same guard as startHandleDrag (the caller has one too)
        const w = screenToWorld(e.clientX, e.clientY)
        selectNode(null)
        drag = { type: 'connect', from: index, side, wx: w.x, wy: w.y, targetNode: null, toSide: 'l' }
        draw()
    }

    // Destaca o bloco sob o ponteiro durante o arraste (o destino da ligação).
    function setLinkTarget(index) {
        nodes.forEach((n, i) => n.el.classList.toggle('is-link-target', i === index))
    }

    // Fonte da prévia: o arraste em si (`drag.type === 'connect'`) OU, depois
    // de soltar em canvas vazio, o quick-add ainda aberto (`quickAddOrigin`/
    // `quickAddPos` — `targetNode` sempre `null` aí, já que não há bloco
    // nenhum sob o ponto onde a seta foi solta).
    function drawConnectPreview() {
        const src = drag?.type === 'connect'
            ? drag
            : { from: quickAddOrigin.index, side: quickAddOrigin.side, wx: quickAddPos.x, wy: quickAddPos.y, targetNode: null, toSide: 'l' }
        const from = nodes[src.from]
        if (!from) return

        const a0 = anchorPoint(from, src.side)
        const p0 = { x: a0.x + a0.nx * EDGE_GAP, y: a0.y + a0.ny * EDGE_GAP }
        let p1 = { x: src.wx, y: src.wy }
        // Sobre um bloco: a prévia gruda na âncora onde a seta vai nascer, não
        // no ponteiro — é exatamente o que será salvo em `viz_layout`.
        if (src.targetNode !== null && nodes[src.targetNode]) {
            const a1 = anchorPoint(nodes[src.targetNode], src.toSide)
            p1 = { x: a1.x + a1.nx * EDGE_GAP, y: a1.y + a1.ny * EDGE_GAP }
        }

        const path = document.createElementNS(SVG_NS, 'path')
        path.setAttribute('class', 'ak-viz-edge is-preview')
        path.setAttribute('d', `M ${p0.x} ${p0.y} L ${p1.x} ${p1.y}`)
        path.setAttribute('marker-end', `url(#${markerEnd.id})`)
        edges.appendChild(path)
    }

    // POST da ligação nova, já com `->` e sem protocolo — sem diálogo no
    // caminho: sentido e protocolo se ajustam depois na pill da seta. A
    // ligação entra no grafo local só depois do OK do servidor (ver
    // `appendEdgeLocally()`). As âncoras vêm do próprio gesto (a porta de
    // origem e o lado onde foi solta), e como âncora é visual
    // (`viz_layout`), isso deixa o layout pendente de salvar.
    async function createEdgeFrom(from, to, fromSide, toSide) {
        // Um POST de ligação por vez: o gesto é rápido o bastante pra dois
        // arrastes se sobreporem, e é a ordem das RESPOSTAS que define o índice
        // local da edge (ver `appendEdgeLocally()`).
        if (!graphRef?.edgeAddUrl || creatingEdge) return
        creatingEdge = true

        try {
            const res = await fetch(graphRef.edgeAddUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ from, to, arrow: '->', protocol: null }),
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível criar a ligação.')

            if (appendEdgeLocally(data, fromSide, toSide)) {
                patchRowGraphAddEdge(slug, data.from, data.to, data.arrow, data.protocol, data.summary)
                draw()
                setDirty(true)
                window.Toast?.show?.(data.message || 'Ligação criada.')
                // Abre na hora o menu compacto (ícones de sentido/tracejado/
                // desligar) da ligação recém-criada — o protocolo em si se
                // define depois, direto no rótulo (ver o comentário do
                // painel no blade).
                selectEdge(data.index)
            }
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível criar a ligação.', 'error')
        } finally {
            creatingEdge = false
        }
    }

    // ── arrastar ponta de seta (reposicionar âncora OU religar pra outro bloco) ──
    function startHandleDrag(e, edgeIndex, end) {
        if (e.button !== 0) return
        e.stopPropagation()
        e.preventDefault()
        const edge = graphRef.edges[edgeIndex]
        const origNode = end === 'from' ? edge.from : edge.to
        const otherNode = end === 'from' ? edge.to : edge.from
        drag = { type: 'handle', edge: edgeIndex, end, origNode, otherNode, targetNode: origNode }
        draw()
    }

    // Nó cujo retângulo contém o ponto do mundo dado, ou null (nenhum) — usado
    // durante o arraste de handle pra saber se o ponteiro está sobre um bloco
    // diferente do nó original daquela ponta (religa) ou não (só muda a âncora).
    function nodeAtPoint(wx, wy) {
        for (let i = 0; i < nodes.length; i++) {
            const n = nodes[i]
            if (wx >= n.x && wx <= n.x + n.w && wy >= n.y && wy <= n.y + n.h) return i
        }
        return null
    }

    // PATCH que religa a ponta `end` da ligação `edgeIndex` pro nó `newNode`.
    // Aplicado OTIMISTA em `graphRef.edges` antes deste fetch (no mouseup, ver
    // abaixo) — evita a ligação "voltar" visualmente enquanto o request está
    // em voo; aqui só confirma no cache da linha (lista à esquerda) ou
    // desfaz a aplicação otimista se o servidor rejeitar.
    async function retargetEdge(edgeIndex, end, newNode, origNode) {
        const url = graphRef?.edgeRetargetUrl?.replace('EDGE_INDEX', String(edgeIndex))
        if (!url) return

        try {
            const res = await fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ end, node: newNode }),
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível religar o bloco.')

            patchRowGraphEdge(slug, edgeIndex, end, newNode, data.summary)
            window.Toast?.show?.(data.message || 'Ligação atualizada.')
        } catch (err) {
            if (graphRef.edges?.[edgeIndex]) graphRef.edges[edgeIndex][end] = origNode
            if (edgeAnchors[edgeIndex]) edgeAnchors[edgeIndex][end] = end === 'from' ? 'r' : 'l'
            draw()
            window.Toast?.show?.(err.message || 'Não foi possível religar o bloco.', 'error')
        }
    }

    // ── clique/arrastar bloco ───────────────────────────────────────
    // Sempre intercepta (mesmo sem `editable`), para que um clique sem
    // arraste selecione o nó em vez de subir para o pan do canvas. Só
    // reposiciona o bloco de fato quando `editable`.
    function startNodePointer(e, index) {
        if (e.button !== 0) return
        e.stopPropagation()
        e.preventDefault()
        // Porta de ligação: em vez de mover o bloco, começa a puxar uma seta
        // dele até outro bloco (`startPortDrag()`). As portas só existem
        // quando editável (CSS), mas a checagem também está aqui.
        const port = editable ? e.target.closest?.('[data-viz-port]') : null
        if (port) {
            startPortDrag(e, index, port.getAttribute('data-viz-port'))
            return
        }
        const w = screenToWorld(e.clientX, e.clientY)
        drag = { type: 'node', index, startWX: w.x, startWY: w.y, origX: nodes[index].x, origY: nodes[index].y, moved: false }
        if (editable) nodes[index].el.classList.add('is-dragging')
    }

    function nearestAnchor(node, wx, wy) {
        let best = 'r'
        let bestDist = Infinity
        ANCHOR_KEYS.forEach((key) => {
            const p = anchorPoint(node, key)
            const dd = (p.x - wx) ** 2 + (p.y - wy) ** 2
            if (dd < bestDist) {
                bestDist = dd
                best = key
            }
        })
        return best
    }

    function fit() {
        const bbox = nodesBBox()
        if (!bbox) {
            applyView()
            return
        }
        const vw = viewport.clientWidth
        const vh = viewport.clientHeight
        const cw = bbox.maxX - bbox.minX + FIT_PAD * 2
        const ch = bbox.maxY - bbox.minY + FIT_PAD * 2
        view.scale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, Math.min(vw / cw, vh / ch)))
        view.x = (vw - (bbox.maxX + bbox.minX) * view.scale) / 2
        view.y = (vh - (bbox.maxY + bbox.minY) * view.scale) / 2
        applyView()
    }

    function zoomAt(factor, clientX, clientY) {
        const r = viewport.getBoundingClientRect()
        const px = (clientX ?? r.left + r.width / 2) - r.left
        const py = (clientY ?? r.top + r.height / 2) - r.top
        const wx = (px - view.x) / view.scale
        const wy = (py - view.y) / view.scale
        view.scale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, view.scale * factor))
        view.x = px - wx * view.scale
        view.y = py - wy * view.scale
        applyView()
    }

    async function save() {
        if (!editable || !saveUrl || !dirty) return
        const payload = {
            nodes: nodes.map((n) => ({
                x: Math.round(n.x),
                y: Math.round(n.y),
                color: n.color || null,
                textColor: n.textColor || null,
                font: n.font || 'sans',
                fontSize: n.fontSize || 'sm',
                dashed: !!n.dashed,
                imageBorderColor: n.imageBorderColor || null,
                logoOnly: !!n.logoOnly,
            })),
            edges: edgeAnchors.map((a) => ({ from: a.from, to: a.to, dashed: !!a.dashed })),
            comments: nodes.map((n) => n.comment || null),
            // `rounded`/`dashed`/`opacity`/`orientation`/`showTitle`/`fontSize`
            // were silently missing from this payload before — editable live
            // in the lane toolbar and validated server-side, but never
            // actually reaching "Salvar" (a viewer reloading the page always
            // saw them reset to default). Sending the full style now that
            // `headerColor`/`fontSize` need it too.
            lanes: lanes.map((l) => ({
                label: l.label,
                color: l.color,
                headerColor: l.headerColor || null,
                x: l.x,
                y: l.y,
                width: l.width,
                height: l.height,
                rounded: !!l.rounded,
                dashed: !!l.dashed,
                opacity: l.opacity,
                orientation: l.orientation || 'horizontal',
                showTitle: l.showTitle !== false,
                fontSize: l.fontSize || 'sm',
            })),
            notes: notes.map((n) => ({ x: Math.round(n.x), y: Math.round(n.y), text: n.text || '' })),
            theme: currentTheme,
        }
        saveBtn.disabled = true
        if (saveLabel) saveLabel.textContent = 'Salvando…'
        try {
            const res = await fetch(saveUrl, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            })
            if (!res.ok) throw new Error('save failed')
            savedLayouts.set(slug, payload)
            if (saveLabel) saveLabel.textContent = 'Salvo'
            setDirty(false)
            window.Toast?.show?.('Layout salvo.')
            setTimeout(() => { if (saveLabel && !dirty) saveLabel.textContent = 'Salvar' }, 1500)
        } catch {
            if (saveLabel) saveLabel.textContent = 'Salvar'
            saveBtn.disabled = false
            window.Toast?.show?.('Não foi possível salvar o layout.', 'error')
        }
    }

    // ── pan (canvas) + zoom (roda) ────────────────────────────────
    let panning = false
    let sx = 0
    let sy = 0
    let ox = 0
    let oy = 0

    // Última posição conhecida do cursor sobre o canvas — usada pelos botões
    // +/- da topbar para ancorar o zoom nela (em vez do centro do viewport,
    // ver `zoomAt()` abaixo), igual à roda do mouse já faz. `null` até o
    // cursor entrar no canvas pela primeira vez (`zoomAt` cai pro centro
    // nesse caso, via `clientX ?? ...`). Não é limpo ao sair do canvas de
    // propósito: o usuário tipicamente move o mouse PARA o botão +/- (fora
    // do viewport) antes de clicar, então "esquecer" a última posição ali
    // dentro derrotaria o propósito — like todo editor de canvas (Figma,
    // Miro), os botões de zoom devem continuar ancorados em torno de onde o
    // usuário estava olhando, não pular pro centro só porque o mouse saiu.
    let lastPointerX = null
    let lastPointerY = null
    viewport.addEventListener('pointermove', (e) => {
        lastPointerX = e.clientX
        lastPointerY = e.clientY
    })

    function startPanning(e) {
        selectNode(null)
        panning = true
        sx = e.clientX
        sy = e.clientY
        ox = view.x
        oy = view.y
        viewport.classList.add('is-panning')
    }

    // Ctrl+clique força o pan mesmo com o ponteiro em cima de um bloco/porta/
    // raia/alça/pill de protocolo — todos vivem dentro de `viewport` (via
    // `world`), então um listener de CAPTURA aqui roda antes do próprio
    // `mousedown` desses elementos (que começaria um arraste/seleção em vez
    // de mover o canvas), e `stopPropagation()` nesta fase impede esses
    // listeners de sequer rodar. Sem isto, mover o canvas exige acertar um
    // pedaço vazio do fundo — impossível quando a cadeia enche o viewport.
    viewport.addEventListener('pointerdown', (e) => {
        if (e.button !== 0 || !e.ctrlKey || drag) return
        e.stopPropagation()
        e.preventDefault()
        startPanning(e)
    }, true)

    viewport.addEventListener('pointerdown', (e) => {
        if (e.button !== 0 || drag) return
        startPanning(e)
    })
    window.addEventListener('pointermove', (e) => {
        if (drag?.type === 'node') {
            const w = screenToWorld(e.clientX, e.clientY)
            const dx = w.x - drag.startWX
            const dy = w.y - drag.startWY
            if (Math.abs(dx) > MOVE_TOLERANCE || Math.abs(dy) > MOVE_TOLERANCE) drag.moved = true
            if (editable) {
                const n = nodes[drag.index]
                n.x = drag.origX + dx
                n.y = drag.origY + dy
                n.el.style.left = n.x + 'px'
                n.el.style.top = n.y + 'px'
                draw()
            }
            return
        }
        if (drag?.type === 'connect') {
            const w = screenToWorld(e.clientX, e.clientY)
            drag.wx = w.x
            drag.wy = w.y
            const hover = nodeAtPoint(w.x, w.y)
            // Só outro bloco vale como destino — um bloco não pode se ligar a
            // ele mesmo (o servidor também recusa, ver `to.different`).
            drag.targetNode = (hover !== null && hover !== drag.from) ? hover : null
            drag.toSide = drag.targetNode !== null ? nearestAnchor(nodes[drag.targetNode], w.x, w.y) : 'l'
            setLinkTarget(drag.targetNode)
            draw()
            return
        }
        if (drag?.type === 'handle') {
            const w = screenToWorld(e.clientX, e.clientY)
            // Sobre outro bloco (que não a ponta oposta da mesma ligação —
            // isso seria um bloco ligado a ele mesmo): prévia de religação
            // pra esse bloco. Fora de qualquer bloco, ou sobre a ponta
            // oposta: volta pro nó original, só a âncora muda.
            const hover = nodeAtPoint(w.x, w.y)
            drag.targetNode = (hover !== null && hover !== drag.otherNode) ? hover : drag.origNode
            edgeAnchors[drag.edge][drag.end] = nearestAnchor(nodes[drag.targetNode], w.x, w.y)
            draw()
            return
        }
        if (drag?.type === 'lane-resize') {
            // Delta em TELA convertido pra MUNDO (`/ view.scale`) — mesmo
            // raciocínio de `screenToWorld()`: arrastar 10px de tela deve
            // mudar o tamanho por menos "mundo" quanto mais zoom, senão a
            // raia cresceria/encolheria rápido demais em zooms altos. `dir`
            // decide quais eixos mudam: 'e' só largura, 's' só altura, 'se'
            // ambos (mesma raia, um único drag).
            const lane = lanes[drag.index]
            const entry = laneEls[drag.index]
            if (!lane || !entry) return
            if (drag.dir.includes('e')) {
                const dx = (e.clientX - drag.startClientX) / view.scale
                lane.width = Math.round(Math.max(LANE_MIN_SIZE, Math.min(LANE_MAX_SIZE, drag.startW + dx)))
                entry.wrap.style.width = lane.width + 'px'
            }
            if (drag.dir.includes('s')) {
                const dy = (e.clientY - drag.startClientY) / view.scale
                lane.height = Math.round(Math.max(LANE_MIN_SIZE, Math.min(LANE_MAX_SIZE, drag.startH + dy)))
                entry.wrap.style.height = lane.height + 'px'
            }
            return
        }
        if (drag?.type === 'lane-move') {
            const lane = lanes[drag.index]
            const entry = laneEls[drag.index]
            if (!lane || !entry) return
            const dx = (e.clientX - drag.startClientX) / view.scale
            const dy = (e.clientY - drag.startClientY) / view.scale
            // Mesma distinção clique-vs-arraste de um bloco (`MOVE_TOLERANCE`,
            // ver `startNodePointer()`) — decide no `mouseup` se isto vira
            // "selecionar a raia" (abre o toolbar) ou "confirma o arraste".
            if (Math.abs(dx) > MOVE_TOLERANCE || Math.abs(dy) > MOVE_TOLERANCE) drag.moved = true
            lane.x = Math.round(drag.startX + dx)
            lane.y = Math.round(drag.startY + dy)
            entry.wrap.style.left = lane.x + 'px'
            entry.wrap.style.top = lane.y + 'px'
            return
        }
        if (drag?.type === 'note-move') {
            const note = notes[drag.index]
            const entry = noteEls[drag.index]
            if (!note || !entry) return
            const dx = (e.clientX - drag.startClientX) / view.scale
            const dy = (e.clientY - drag.startClientY) / view.scale
            if (Math.abs(dx) > MOVE_TOLERANCE || Math.abs(dy) > MOVE_TOLERANCE) drag.moved = true
            note.x = Math.round(drag.startX + dx)
            note.y = Math.round(drag.startY + dy)
            entry.wrap.style.left = note.x + 'px'
            entry.wrap.style.top = note.y + 'px'
            return
        }
        if (!panning) return
        view.x = ox + (e.clientX - sx)
        view.y = oy + (e.clientY - sy)
        applyView()
    })
    /**
     * Fim de um gesto. `cancelled` distingue `pointercancel` de `pointerup`:
     * um ponteiro de TOQUE pode ser cancelado pelo navegador (gesto do
     * sistema, um segundo dedo, o elemento saindo do DOM) sem nunca disparar
     * `pointerup` — e como todo arraste vive no objeto `drag` até um evento de
     * término limpá-lo, ignorar isso deixaria o canvas travado no meio de um
     * arraste, sem saída além de recarregar a página.
     *
     * Num cancelamento só as AÇÕES são abandonadas — completar uma ligação,
     * religar a ponta de uma seta, selecionar por clique-sem-arraste. O que já
     * mudou de posição na tela (bloco/raia/anotação) é mantido e marcado como
     * sujo: o gesto foi interrompido, mas o usuário está vendo o resultado
     * dele, então desfazer silenciosamente seria mais surpreendente do que
     * preservar.
     */
    function endPointer(cancelled = false) {
        if (drag) {
            if (drag.type === 'node') {
                nodes[drag.index]?.el.classList.remove('is-dragging')
                if (!drag.moved) { if (! cancelled) selectNode(drag.index) }
                else if (editable) setDirty(true)
            } else if (drag.type === 'handle') {
                if (cancelled) {
                    // `draw()` no fim redesenha a partir de `graphRef`, então a
                    // ponta arrastada volta sozinha pro lugar de origem.
                } else if (drag.targetNode !== drag.origNode) {
                    // Aplica otimista antes do PATCH — ver `retargetEdge()`.
                    graphRef.edges[drag.edge][drag.end] = drag.targetNode
                    retargetEdge(drag.edge, drag.end, drag.targetNode, drag.origNode)
                } else {
                    setDirty(true)
                }
            } else if (drag.type === 'connect') {
                // Soltou sobre outro bloco: cria a ligação. Sobre canvas
                // vazio: abre o "Adicionar bloco" — um clique no ícone do tipo
                // já cria o bloco NAQUELE ponto e completa a ligação (ver
                // `openQuickAddEditor()`/`createNodeFromKind()`) — puxar uma
                // seta pro vazio e soltar é como se ganha um bloco novo já
                // ligado, ao invés de simplesmente cancelar.
                setLinkTarget(null)
                if (cancelled) {
                    // Abandona: nem cria a ligação, nem abre o "Adicionar bloco".
                } else if (drag.targetNode !== null) createEdgeFrom(drag.from, drag.targetNode, drag.side, drag.toSide)
                else openQuickAddEditor(drag.from, drag.side, drag.wx, drag.wy)
            } else if (drag.type === 'lane-resize') {
                laneEls[drag.index]?.handles[drag.dir]?.classList.remove('is-resizing')
                setDirty(true)
            } else if (drag.type === 'lane-move') {
                // Clique sem arraste NA ETIQUETA: seleciona a raia (abre o
                // toolbar de cor/nome/remover/estilo). Clique sem arraste no
                // resto do corpo: não faz nada — só a etiqueta é alvo de
                // seleção, EXCETO quando a raia não tem título
                // (`showTitle === false`, `onLabel` já nasce `true` pro
                // corpo inteiro em `rebuildLanes()`), já que aí não existe
                // uma faixa separada pra reservar. Arraste de fato (de
                // qualquer parte do corpo): só confirma a posição nova,
                // mesma distinção de `drag.type === 'node'` acima.
                if (!drag.moved) { if (drag.onLabel && ! cancelled) selectLane(drag.index) }
                else setDirty(true)
            } else if (drag.type === 'note-move') {
                // Sem toolbar/seleção pra abrir — uma anotação não tem nada
                // além de posição e texto (o texto já se edita direto no
                // corpo, sempre). Um clique sem arraste na faixinha não faz
                // nada; só um arraste de fato marca a posição como suja.
                if (drag.moved) setDirty(true)
            }
            drag = null
            draw()
        }
        panning = false
        viewport.classList.remove('is-panning')
    }
    window.addEventListener('pointerup', () => endPointer(false))
    window.addEventListener('pointercancel', () => endPointer(true))

    viewport.addEventListener('wheel', (e) => {
        e.preventDefault()
        zoomAt(e.deltaY < 0 ? 1.08 : 1 / 1.08, e.clientX, e.clientY)
    }, { passive: false })

    // O pan por toque com um dedo já vem de graça dos listeners de PONTEIRO
    // acima (`pointerdown`/`pointermove`/`pointerup` disparam para toque,
    // caneta e mouse igualmente, e `.ak-viz-viewport` tem `touch-action: none`,
    // então o navegador não rouba o gesto pra rolar a página).
    //
    // Existia aqui um trio `touchstart`/`touchmove`/`touchend` dedicado a esse
    // pan. Ele foi REMOVIDO, não portado: eventos de toque são um fluxo
    // separado dos de mouse, então um toque num bloco borbulhava pro viewport
    // sem passar pelo `stopPropagation()` do bloco (que só existia no
    // `mousedown`) — arrastar um bloco no touch movia o CANVAS em vez do
    // bloco. Mantê-lo ao lado dos listeners de ponteiro só trocaria esse bug
    // por outro: os dois fluxos disparariam no mesmo gesto e o pan andaria
    // junto com o arraste do bloco.

    // ── controles ────────────────────────────────────────────────
    // Ancorado na última posição do cursor sobre o canvas (`lastPointerX/Y`),
    // não no centro do viewport — clicar + repetidamente antes empurrava
    // qualquer bloco longe do centro (ex.: o mais à esquerda de uma chain
    // comprida) rumo à borda da tela a cada clique, mesmo com o zoom
    // matematicamente correto (verificado: a fórmula batia exatamente com
    // "zoom ancorado no centro do viewport" a cada passo) — só não era o que
    // o usuário esperava ao focar visualmente num bloco específico antes de
    // ampliar.
    root.querySelector('[data-viz-zoom-in]')?.addEventListener('click', () => zoomAt(1.12, lastPointerX, lastPointerY))
    root.querySelector('[data-viz-zoom-out]')?.addEventListener('click', () => zoomAt(1 / 1.12, lastPointerX, lastPointerY))
    root.querySelector('[data-viz-fit]')?.addEventListener('click', fit)
    organizeBtn?.addEventListener('click', organize)
    saveBtn?.addEventListener('click', save)

    presentToggleBtn?.addEventListener('click', () => { presenting ? exitPresentation() : enterPresentation() })
    presentSpeedSelect?.addEventListener('change', () => {
        const v = Number(presentSpeedSelect.value)
        if (v > 0) presentSpeedMultiplier = v
    })
    exportPngBtn?.addEventListener('click', exportImage)
    exportGifBtn?.addEventListener('click', exportVideo)
    themeSelect?.addEventListener('change', () => applyTheme(themeSelect.value))

    // ── tela cheia do navegador (botão do rodapé) ──
    const fsOpen = root.querySelector('[data-viz-fs-open]')
    const fsClose = root.querySelector('[data-viz-fs-close]')
    function toggleFullscreen() {
        if (document.fullscreenElement === root) document.exitFullscreen?.()
        else root.requestFullscreen?.()
    }
    root.querySelector('[data-viz-fullscreen]')?.addEventListener('click', toggleFullscreen)
    document.addEventListener('fullscreenchange', () => {
        const isFs = document.fullscreenElement === root
        fsOpen?.classList.toggle('hidden', isFs)
        fsClose?.classList.toggle('hidden', !isFs)
        requestAnimationFrame(() => requestAnimationFrame(() => fit()))
    })

    window.addEventListener('resize', () => {
        if (nodes.length) fit()
        positionBottomBar()
    })

    // `render()` measures every node's w/h via offsetWidth/offsetHeight right
    // after creating its <div> (see below) — but this canvas lives inside the
    // unified Documentação/Diagrama tabs, and integration-select.js
    // auto-selects the (only) integration on page load via a microtask
    // REGARDLESS of which tab is active. When "Documentação" is the one
    // shown first, `render()` runs while this whole root sits under a
    // `hidden` (display:none) tab panel, so every node measures 0×0 and that
    // measurement is never retaken — anchorPoint()/nodeAtPoint() then divide
    // every side (`node.w * fx`, `node.h * fy`) down to 0, so EVERY arrow
    // renders at each node's raw top-left corner instead of its real side,
    // and dragging an edge's handle can never detect hovering a block (its
    // w×h hit-box is degenerate). Worse, when there's no saved `viz_layout`
    // yet, `layoutDefault()` also ran on that same stale 0×0 — every block a
    // flat `LEVEL_GAP` (90px) apart from its neighbor's LEFT edge, regardless
    // of the real (nonzero) width it turns out to have — so once the tab
    // becomes visible and blocks paint at their real size, adjacent ones
    // visibly overlap (confirmed with a scripted 2-node chain: the second
    // block rendered fully inside the first one's box). A ResizeObserver on
    // the viewport reliably fires the moment the tab panel is unhidden
    // (content box goes from 0×0 to its real size, unlike `window`'s
    // 'resize', which never fires for a display:none→block toggle) —
    // re-measure then, redo `layoutDefault()` too (only when no saved layout
    // owns the positions — `usedCustomLayout`, set by the `render()` that
    // just ran on the stale zero) so the overlap is fixed instead of just the
    // anchor math, and redraw/refit so a stale zero is never load-bearing again.
    const viewportResizeObserver = new ResizeObserver((entries) => {
        const entry = entries[entries.length - 1]
        if (!entry || entry.contentRect.width === 0 || entry.contentRect.height === 0 || !nodes.length) return
        const wasZeroSized = nodes.every((n) => n.w === 0 && n.h === 0)
        nodes.forEach((n) => {
            n.w = n.el.offsetWidth
            n.h = n.el.offsetHeight
        })
        if (wasZeroSized) {
            if (!usedCustomLayout) layoutDefault()
            nodes.forEach((n) => {
                n.el.style.left = n.x + 'px'
                n.el.style.top = n.y + 'px'
            })
        }
        draw()
        if (wasZeroSized) fit()
    })
    viewportResizeObserver.observe(viewport)

    // Esc fecha o toolbar de uma raia selecionada, fecha a sidebar de
    // comentário, ou (fallback) fecha qualquer outro popover ainda aberto —
    // `selectNode(null)` é a mesma chamada que o mousedown no canvas vazio já
    // faz, e internamente fecha o editor de título, o editor de protocolo, o
    // painel "Adicionar bloco" e a toolbar do bloco selecionado, então Esc
    // passa a fechar tudo que clicar fora já fechava.
    window.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return
        if (presenting) { exitPresentation(); return }
        if (selectedLane !== null) { closeLaneToolbar(); return }
        if (isSidebarOpen()) { closeComment(); return }
        selectNode(null)
    })

    // Ctrl+V (ou Cmd+V) cola uma imagem direto no canvas — vira um bloco
    // `image` como qualquer outro (porta, seta, comentário), a única forma de
    // criar um (ver `ChainNodeKind::pickable()`). `paste` é um evento de
    // documento (o canvas em si não é um campo de texto), então só reage
    // quando nenhum campo de texto VISÍVEL está focado — `offsetParent` (não
    // só a tag) descarta um input de um painel que acabou de fechar
    // (`display:none`) mas continua sendo `document.activeElement`, o que do
    // contrário engoliria o Ctrl+V como se ainda estivesse em edição.
    document.addEventListener('paste', (e) => {
        if (!editable || !graphRef?.imageAddUrl) return
        const active = document.activeElement
        const typing = active && (active.tagName === 'TEXTAREA' || active.tagName === 'INPUT' || active.isContentEditable) && active.offsetParent !== null
        if (typing) return

        const items = e.clipboardData?.items
        if (!items) return
        const imageItem = Array.from(items).find((item) => item.kind === 'file' && item.type.startsWith('image/'))
        if (!imageItem) return

        e.preventDefault()
        const file = imageItem.getAsFile()
        if (file) handlePasteImage(file)
    })

    // Só uma de cada vez — colar de novo antes da primeira terminar de subir
    // seria descartado silenciosamente (ver `pastingImage`) em vez de
    // disparar dois POSTs concorrentes que voltariam em ordem imprevisível.
    async function handlePasteImage(file) {
        if (pastingImage) return
        pastingImage = true
        try {
            const formData = new FormData()
            formData.append('image', file)
            const res = await fetch(graphRef.imageAddUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível colar a imagem.')

            // Nasce perto de onde o usuário está olhando (último ponto do
            // ponteiro sobre o canvas), como um bloco solto no vazio faria ao
            // arrastar uma seta pra lá — sem isso, cai no padrão de
            // `appendNode()` (à direita do último bloco).
            const pos = (lastPointerX !== null && lastPointerY !== null) ? screenToWorld(lastPointerX, lastPointerY) : null
            appendNode(data.node, pos)
            patchRowGraphAppend(slug, data.node, data.summary)
            window.Toast?.show?.(data.message || 'Imagem adicionada.')
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível colar a imagem.', 'error')
        } finally {
            pastingImage = false
        }
    }

    root.__akVizRender = render
    setDirty(false)
    applyView()
}
