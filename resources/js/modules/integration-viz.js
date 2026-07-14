// Visualização gráfica da integração selecionada (seção F3 do detalhe da
// solução). Desenha a cadeia (`chain`) escolhida na lista à esquerda como um
// grafo — nós (soluções ou texto livre) ligados por setas cujo sentido segue o
// segmento (`->` ida, `<-` volta, `<->` ambos) e cujo rótulo é o protocolo.
//
// Nós em <div> com sombra + arestas em SVG dentro de um #world com transform.
// Os dados vêm resolvidos no `data-integration-graph` de cada linha
// (`integration-select.js` emite `ak:integration-selected`). Nós que
// referenciam uma Solução também trazem `logo`, `environment` e `cloud`
// (rótulo + SVG de ícone já renderizado no servidor) — exibidos como avatar
// e chips discretos em cima do bloco.
//
// Clicar (sem arrastar) um nó seleciona e ancora uma toolbar contextual
// (título / comentário / abrir solução) — funciona com ou sem `editable`.
// Edição de posição (quando `editable`): arraste um bloco para reposicioná-lo;
// arraste o handle de uma ponta de seta para grudá-la numa das 8 âncoras do nó
// (4 principais + 2 no topo + 2 na base). "Organizar" recalcula o layout
// padrão esquerda→direita. O botão "Salvar" persiste layout + âncoras +
// comentários (`viz_layout`) no servidor — é só apresentação, não mexe na
// topologia.
//
// O lápis da toolbar (indisponível no nó raiz, índice 0) abre um editor
// inline de título — select de Soluções cadastradas + opção de texto livre.
// Ao salvar, isso SIM mexe na `chain` (fonte de verdade) via PATCH em
// `graph.nodeUpdateUrl`, então o
// servidor pode rederivar participants/source/target/direction — a resposta
// já vem no formato resolvido (ver `Solutions\IntegrationsMap::resolveNode`),
// aplicado ao nó local sem precisar redesenhar o grafo inteiro.
//
// A pill de protocolo em cima de cada seta segue o mesmo espírito: clicável
// quando `editable` (inclusive a pill tracejada "+ protocolo" de um passo sem
// protocolo ainda), abre um editor próprio (`selectEdge()`/`openProtocolEditor()`,
// ancorado à pill — não a um nó, por isso não mora dentro da toolbar do
// bloco) com um select de sentido (`->`/`<-`/`<->`) + um select do enum
// `Protocol`, e um botão "Desligar" que remove só a ligação (os blocos
// continuam existindo — é assim que um bloco pode acabar sem nenhuma
// interligação). PATCH em `graph.edgeUpdateUrl` (sentido+protocolo) ou
// DELETE em `graph.edgeRemoveUrl` (desligar); não há ligação "raiz"
// protegida aqui, qualquer edge pode ser editada/removida.
//
// O botão "+" da topbar (`openAddEditor()`) acrescenta um bloco novo ao FINAL
// da cadeia — mesmo par "select de Soluções + texto livre" do editor de
// título, mais a seta/protocolo da nova ligação (ou "Sem conexão", que
// acrescenta o bloco sem ligação nenhuma — nasce isolado). POST em
// `graph.nodeAddUrl`; a resposta traz o nó já resolvido (mesmo formato de
// `resolveNode()`), que `appendNode()` desenha e posiciona à direita do
// último bloco, sem redesenhar o grafo inteiro.
//
// A chain é um GRAFO LIVRE, não uma linha reta, e não exige que todo bloco
// esteja ligado a algo: `graph.edges[i]` traz `{from, to, arrow, protocol}`
// com índices de nó explícitos, e o número de edges é independente do número
// de nós. Duas formas de (re)ligar blocos:
//   1. Arrastar o handle de uma ponta de seta JÁ EXISTENTE para dentro de
//      OUTRO bloco (não só pra outra âncora do mesmo par de nós) religa
//      aquela ligação pra esse bloco — `nodeAtPoint()` decide, durante o
//      arraste, se o ponteiro está sobre um nó diferente do nó original
//      daquela ponta; ao soltar, `retargetEdge()` faz o PATCH em
//      `graph.edgeRetargetUrl` (aplicado otimista antes da resposta, pra não
//      "voltar" visualmente enquanto o request está em voo — reverte em caso
//      de erro). Um bloco não pode se ligar a ele mesmo: soltar sobre a
//      ponta oposta da MESMA ligação é ignorado, mantendo o nó original.
//   2. "Modo ligar" (`data-viz-toolbar-link` na toolbar do bloco, ver
//      `startLinking()`): clique num bloco ativa o modo (cursor crosshair +
//      hint no topo do canvas), clique em outro bloco qualquer abre
//      `openConnectEditor()` (mesmo painel do protocolo, em modo "create"),
//      que ao salvar faz POST em `graph.edgeAddUrl` — cria uma ligação NOVA
//      do zero entre os dois, sem depender de nenhuma ligação existente pra
//      arrastar. É o que permite ligar dois blocos que nunca estiveram
//      conectados (ou reconectar um bloco isolado) sem passar por
//      `addNode()`. Esc, o botão do hint, ou clicar no fundo do canvas
//      cancelam o modo.
// Entre as duas formas de religar + "Sem conexão" no painel de adicionar +
// "Desligar" no editor de ligação, a topologia é um grafo livre de verdade —
// sem precisar de uma ferramenta separada de "desenhar ligação do zero" nem
// de forçar todo bloco a estar conectado.
//
// O lápis da TOPBAR (`data-viz-meta-edit`, distinto do lápis de título do
// bloco) edita nome/status da integração selecionada — o único metadado que
// não mora num nó/aresta da chain, então não mexe nela (PATCH em
// `graph.metaUpdateUrl`, sem SyncIntegrationFromChain). Criar uma Integration
// nova é o form "Nova" da lista à esquerda (`integrations-map.blade.php`),
// que já entrega a chain com só o nó raiz.

const SVG_NS = 'http://www.w3.org/2000/svg'
const MIN_SCALE = 0.3
const MAX_SCALE = 2.2
const LEVEL_GAP = 90 // espaço horizontal entre nós consecutivos
const FIT_PAD = 60
const EDGE_GAP = 8   // afastamento da linha em relação ao centro do handle (evita invadir o círculo)
const MOVE_TOLERANCE = 3 // distância (px, espaço do mundo) para distinguir clique de arraste
const PANEL_GAP = 14 // folga entre a toolbar/editor de protocolo e o bloco/aresta ancorados

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

// Paleta de cor de bloco (mesma lógica do mapa mental de referência: presets
// + cor personalizada) e famílias de fonte selecionáveis por bloco.
const PALETTE = ['#C9D4F7', '#4A90D9', '#EBF4FC', '#1A1A2E', '#7FCFC0', '#9BD9A8', '#F6CE7E', '#F2A6A6', '#CBD5E1']
const FONTS = {
    sans: "'Space Grotesk', 'Inter', system-ui, sans-serif",
    serif: "Georgia, 'Times New Roman', serif",
    mono: "ui-monospace, 'SF Mono', Menlo, Consolas, monospace",
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

// Chip discreto de atributo (hospedagem/cloud) em cima do bloco: ícone (SVG já
// renderizado no servidor — ver `IntegrationsMap::attributeBadge()`, nome vem
// validado contra o set heroicons) + rótulo. O rótulo usa `textContent`
// (nunca `innerHTML`) porque, ao contrário do ícone, não passa por validação.
function buildAttrChip(attr) {
    const chip = document.createElement('span')
    chip.className = 'ak-viz-node-attr'
    if (attr.icon) {
        const icon = document.createElement('span')
        icon.className = 'ak-viz-node-attr-icon'
        icon.innerHTML = attr.icon
        chip.appendChild(icon)
    }
    const label = document.createElement('span')
    label.textContent = attr.label
    chip.appendChild(label)
    return chip
}

// Avatar do bloco: logo da solução, ou (sem logo) um badge com a inicial do
// nome — mesmo fallback do catálogo (`x-ui.logo`), refeito aqui em DOM puro
// porque os nós do data-viz não passam por Blade.
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

// (Re)desenha o conteúdo de um bloco a partir dos dados resolvidos do nó —
// usado tanto ao montar o grafo inteiro (`render()`) quanto após editar o
// título de um nó pontualmente (`applyNodeData()`), para as duas rotas nunca
// divergirem na montagem do DOM do bloco.
function paintNode(el, data) {
    el.classList.toggle('is-free', !data.solution)
    el.classList.toggle('has-comment', !!data.comment)
    el.innerHTML = ''

    // Linha discreta em cima do bloco com hospedagem/cloud da solução (ícone +
    // rótulo), só para nós que referenciam uma Solução e têm ao menos um dos
    // dois atributos definido.
    const attrs = [data.environment, data.cloud].filter(Boolean)
    if (data.solution && attrs.length) {
        const attrsRow = document.createElement('div')
        attrsRow.className = 'ak-viz-node-attrs'
        attrs.forEach((attr) => attrsRow.appendChild(buildAttrChip(attr)))
        el.appendChild(attrsRow)
    }

    // Corpo do bloco: avatar (logo da solução, ou inicial do nome quando não
    // há logo) + nome. Nós de texto livre não têm avatar.
    const body = document.createElement('div')
    body.className = 'ak-viz-node-body'
    if (data.solution) body.appendChild(buildAvatar(data))
    const text = document.createElement('span')
    text.className = 'ak-viz-node-text'
    text.textContent = data.label ?? '?'
    body.appendChild(text)
    el.appendChild(body)

    const badge = document.createElement('span')
    badge.className = 'ak-viz-comment-badge'
    el.appendChild(badge)
}

const mounted = new WeakSet()
const roots = new Set()
const savedLayouts = new Map() // slug -> último layout salvo na sessão (mantém consistência sem reload)
let uidCounter = 0
let solutionsListCache = null // [{id,name}] — lido uma vez de [data-ak-solutions] (integrations-map.blade.php)
let protocolsListCache = null // [{value,label}] — lido uma vez de [data-ak-protocols] (integrations-map.blade.php)
let statusesListCache = null // [{value,label}] — lido uma vez de [data-ak-statuses] (integrations-map.blade.php)

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

function getStatusesList() {
    if (statusesListCache) return statusesListCache
    const raw = document.querySelector('[data-ak-statuses]')?.getAttribute('data-ak-statuses')
    try {
        statusesListCache = raw ? JSON.parse(raw) : []
    } catch {
        statusesListCache = []
    }
    return statusesListCache
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

function mdInline(s) {
    const codes = []
    s = s.replace(/`([^`]+)`/g, (m, c) => { codes.push(c); return ' ' + (codes.length - 1) + ' ' })
    s = s.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, (m, a, u) => `<img src="${u}" alt="${a}" style="max-width:100%;border-radius:6px">`)
    s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (m, t, u) => `<a href="${u}" target="_blank" rel="noopener">${t}</a>`)
    s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    s = s.replace(/__([^_]+)__/g, '<strong>$1</strong>')
    s = s.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>')
    s = s.replace(/(^|[^\w])_([^_\n]+)_/g, '$1<em>$2</em>')
    s = s.replace(/~~([^~]+)~~/g, '<del>$1</del>')
    s = s.replace(/ (\d+) /g, (m, i) => `<code>${codes[+i]}</code>`)
    return s
}

function renderMarkdown(src) {
    if (!src || !src.trim()) return '<p class="md-empty">Sem comentário ainda.</p>'
    src = src.replace(/\r\n/g, '\n')
    const blocks = []
    src = src.replace(/```([\s\S]*?)```/g, (m, code) => { blocks.push(code.replace(/^\n/, '').replace(/\n$/, '')); return '' + (blocks.length - 1) + '' })
    const lines = src.split('\n')
    let html = ''
    let i = 0
    const isSpecial = (ln) => /^\d+$/.test(ln) || /^(#{1,6})\s/.test(ln) || /^\s*>/.test(ln) || /^\s*[-*+]\s/.test(ln) || /^\s*\d+\.\s/.test(ln) || /^\s*([-*_])\1\1+\s*$/.test(ln) || /^\s*$/.test(ln)
    while (i < lines.length) {
        const line = lines[i]
        const cm = line.match(/^(\d+)$/)
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
    const topbarTitle = root.querySelector('[data-viz-title]')
    const metaEditBtn = root.querySelector('[data-viz-meta-edit]')
    const metaEditor = root.querySelector('[data-viz-meta-editor]')
    const metaNameInput = root.querySelector('[data-viz-meta-name]')
    const metaStatusSelect = root.querySelector('[data-viz-meta-status]')
    const metaSave = root.querySelector('[data-viz-meta-save]')
    const metaSaveLabel = root.querySelector('[data-viz-meta-save-label]')
    const metaCancel = root.querySelector('[data-viz-meta-cancel]')
    const organizeBtn = root.querySelector('[data-viz-organize]')
    const addNodeBtn = root.querySelector('[data-viz-add-node]')
    const addEditor = root.querySelector('[data-viz-add-editor]')
    const addSelect = root.querySelector('[data-viz-add-select]')
    const addLabelInput = root.querySelector('[data-viz-add-label]')
    const addArrowSelect = root.querySelector('[data-viz-add-arrow]')
    const addProtocolSelect = root.querySelector('[data-viz-add-protocol]')
    const addSave = root.querySelector('[data-viz-add-save]')
    const addSaveLabel = root.querySelector('[data-viz-add-save-label]')
    const addCancel = root.querySelector('[data-viz-add-cancel]')
    const bottomBar = root.querySelector('[data-viz-bottombar]')
    const toolbar = root.querySelector('[data-viz-toolbar]')
    const toolbarStyle = root.querySelector('[data-viz-toolbar-style]')
    const toolbarSwatches = root.querySelector('[data-viz-swatches]')
    const toolbarCustomColor = root.querySelector('[data-viz-custom-color]')
    const toolbarTextColor = root.querySelector('[data-viz-text-color]')
    const toolbarTextColorWrap = root.querySelector('[data-viz-text-color-wrap]')
    const toolbarFont = root.querySelector('[data-viz-font]')
    const toolbarActions = root.querySelector('[data-viz-toolbar-actions]')
    const toolbarTitleBtn = root.querySelector('[data-viz-toolbar-title]')
    const toolbarComment = root.querySelector('[data-viz-toolbar-comment]')
    const toolbarLinkBtn = root.querySelector('[data-viz-toolbar-link]')
    const toolbarOpen = root.querySelector('[data-viz-toolbar-open]')
    const linkHint = root.querySelector('[data-viz-link-hint]')
    const linkCancelBtn = root.querySelector('[data-viz-link-cancel]')
    const titleEditor = root.querySelector('[data-viz-title-editor]')
    const titleSelect = root.querySelector('[data-viz-title-select]')
    const titleLabelInput = root.querySelector('[data-viz-title-label]')
    const titleSave = root.querySelector('[data-viz-title-save]')
    const titleSaveLabel = root.querySelector('[data-viz-title-save-label]')
    const titleCancel = root.querySelector('[data-viz-title-cancel]')
    const protocolEditor = root.querySelector('[data-viz-protocol-editor]')
    const protocolArrowSelect = root.querySelector('[data-viz-protocol-arrow]')
    const protocolSelect = root.querySelector('[data-viz-protocol-select]')
    const protocolDelete = root.querySelector('[data-viz-protocol-delete]')
    const protocolSave = root.querySelector('[data-viz-protocol-save]')
    const protocolSaveLabel = root.querySelector('[data-viz-protocol-save-label]')
    const protocolCancel = root.querySelector('[data-viz-protocol-cancel]')
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
    let edgeAnchors = []    // [{from, to}] por índice de edge (âncora visual — from/to aqui são anchor keys, não nós)
    let slug = ''
    let editable = false
    let saveUrl = null
    let drag = null         // {type:'handle'|'node', ...} — 'handle' carrega edge/end/origNode/otherNode/targetNode
    let dirty = false
    let selectedIndex = null
    let commentIndex = null
    let selectedEdge = null // índice em chain.edges com o editor de protocolo aberto
    let edgeLabelEls = []   // <g> de cada pill de protocolo desenhada no draw() atual — base p/ ancorar o editor
    let linking = null        // índice do nó de origem, enquanto o "modo ligar" está ativo
    let edgeEditorMode = null // 'edit' (ligação existente, ancorado à pill) | 'create' (ligação nova, ancorado ao nó de destino)
    let pendingConnect = null // {from, to} — só em modo 'create', até o POST confirmar

    function applyView() {
        world.style.transform = `translate(${view.x}px,${view.y}px) scale(${view.scale})`
        if (zoomLabel) zoomLabel.textContent = Math.round(view.scale * 100) + '%'
        positionToolbar()
        positionProtocolEditor()
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

    function showEmpty(name) {
        empty.style.display = ''
        saveBtn?.classList.add('!hidden')
        saveSep?.classList.add('hidden')
        addNodeBtn?.classList.add('!hidden')
        metaEditBtn?.classList.add('!hidden')
        if (topbarTitle) topbarTitle.textContent = name || 'Selecione uma integração à esquerda'
        if (emptyTitle) emptyTitle.textContent = name || 'Selecione uma integração à esquerda'
        if (emptyHint) {
            emptyHint.textContent = name
                ? 'Esta integração não tem uma cadeia definida.'
                : 'A visualização gráfica aparecerá aqui.'
        }
    }

    function render(graph, name, slugArg) {
        selectNode(null)
        cancelLinking()
        closeComment()
        closeAddEditor()
        closeMetaEditor()
        clearWorld()
        graphRef = graph
        slug = slugArg || ''
        editable = !!graph?.editable
        saveUrl = graph?.saveUrl ?? null
        root.toggleAttribute('data-editable', editable)

        if (!graph || !Array.isArray(graph.nodes) || graph.nodes.length === 0) {
            showEmpty(name)
            return
        }
        empty.style.display = 'none'
        if (topbarTitle) topbarTitle.textContent = name || ''

        // botão salvar/adicionar bloco/renomear visíveis só quando editável
        saveBtn?.classList.toggle('!hidden', !editable)
        saveSep?.classList.toggle('hidden', !editable)
        addNodeBtn?.classList.toggle('!hidden', !editable)
        metaEditBtn?.classList.toggle('!hidden', !editable)

        graph.nodes.forEach((data, i) => {
            const el = document.createElement('div')
            el.className = 'ak-viz-node'
            paintNode(el, data)

            el.addEventListener('mousedown', (e) => startNodePointer(e, i))
            world.appendChild(el)
            nodes.push({ ...data, el, w: 0, h: 0, x: 0, y: 0, color: null, textColor: null, font: 'sans' })
        })
        nodes.forEach((n) => {
            n.w = n.el.offsetWidth
            n.h = n.el.offsetHeight
        })

        layoutDefault()
        // Uma âncora visual por ligação (`graph.edges`), não por par consecutivo
        // de nós — a chain é um grafo livre, o número de edges é independente
        // do número de nós.
        edgeAnchors = Array.from({ length: (graph.edges || []).length }, () => ({ from: 'r', to: 'l' }))

        applyLayout(savedLayouts.get(slug) ?? graph.layout)
        nodes.forEach((n) => {
            n.el.style.left = n.x + 'px'
            n.el.style.top = n.y + 'px'
            applyNodeStyle(n)
        })

        toolbarStyle?.classList.toggle('!hidden', !editable)

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
    // para o padrão — não toca em rótulos/topologia.
    function organize() {
        if (!nodes.length) return
        layoutDefault()
        edgeAnchors = edgeAnchors.map(() => ({ from: 'r', to: 'l' }))
        nodes.forEach((n) => {
            n.el.style.left = n.x + 'px'
            n.el.style.top = n.y + 'px'
        })
        draw()
        setDirty(true)
        fit()
    }

    // Aplica um layout salvo (posições + âncoras + comentários), se compatível com a cadeia atual.
    function applyLayout(layout) {
        if (!layout) return
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
            })
        }
        if (Array.isArray(layout.edges) && layout.edges.length === edgeAnchors.length) {
            layout.edges.forEach((e, i) => {
                if (e && ANCHORS[e.from] && ANCHORS[e.to]) edgeAnchors[i] = { from: e.from, to: e.to }
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
    }

    function draw() {
        clearOverlays()
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

            const anchors = edgeAnchors[i] || { from: 'r', to: 'l' }
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
            path.setAttribute('class', 'ak-viz-edge')
            path.setAttribute('d', `M ${p0.x} ${p0.y} C ${c1x} ${c1y}, ${c2x} ${c2y}, ${p3.x} ${p3.y}`)
            const arrow = edge.arrow || '->'
            if (arrow === '->' || arrow === '<->') path.setAttribute('marker-end', `url(#${markerEnd.id})`)
            if (arrow === '<-' || arrow === '<->') path.setAttribute('marker-start', `url(#${markerStart.id})`)
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
        positionToolbar()
        positionProtocolEditor()
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

        g.appendChild(rect)
        g.appendChild(label)

        if (editable) {
            g.addEventListener('mousedown', (e) => e.stopPropagation())
            g.addEventListener('click', (e) => {
                e.stopPropagation()
                selectEdge(edgeIndex)
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
        h.addEventListener('mousedown', (e) => startHandleDrag(e, edgeIndex, end))
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
        closeTitleEditor()
        closeProtocolEditor()
        closeAddEditor()
        if (selectedIndex !== null && nodes[selectedIndex]) nodes[selectedIndex].el.classList.remove('is-selected')
        selectedIndex = index

        if (index !== null && nodes[index]) {
            nodes[index].el.classList.add('is-selected')
            toolbar?.classList.remove('hidden')
            toolbar?.classList.add('flex')
            if (toolbarOpen) toolbarOpen.disabled = !nodes[index].url
            // Título só é editável fora do nó raiz (índice 0) — mesma
            // invariante do form completo de cadeia. "Ligar a outro bloco"
            // não tem essa restrição: qualquer bloco (inclusive o raiz) pode
            // originar uma ligação nova.
            toolbarTitleBtn?.classList.toggle('hidden', !editable || index === 0)
            toolbarLinkBtn?.classList.toggle('!hidden', !editable)
            if (editable) {
                buildSwatches()
                refreshToolbarControls()
            }
            positionToolbar()
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
    }

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
        positionToolbar()
        setDirty(true)
    }

    toolbarCustomColor?.addEventListener('input', (e) => setNodeColor(e.target.value))
    toolbarTextColor?.addEventListener('input', (e) => setNodeTextColor(e.target.value))
    toolbarFont?.addEventListener('change', (e) => setNodeFont(e.target.value))

    // ── título do nó: select de Soluções cadastradas + texto livre ─────
    // Aplica os campos resolvidos que vêm do servidor (mesmo formato de
    // `graph.nodes[i]`) num nó já desenhado, sem precisar redesenhar o grafo
    // inteiro. O tamanho do bloco pode mudar (texto novo) — recalcula w/h e
    // redesenha as arestas na sequência.
    function applyNodeData(index, data) {
        const n = nodes[index]
        if (!n) return
        Object.assign(n, {
            label: data.label,
            solution: data.solution,
            solutionId: data.solutionId ?? null,
            url: data.url,
            logo: data.logo,
            environment: data.environment,
            cloud: data.cloud,
            comment: data.comment ?? null,
        })
        paintNode(n.el, n)
        n.w = n.el.offsetWidth
        n.h = n.el.offsetHeight
        n.el.style.left = n.x + 'px'
        n.el.style.top = n.y + 'px'
        applyNodeStyle(n)
        if (toolbarOpen) toolbarOpen.disabled = !n.url
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

    // Acrescenta (não substitui) uma ligação nova ao cache — "modo ligar"
    // (`createEdge()`), que não mexe em nós, só em `chain.edges`.
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

    function openTitleEditor(index) {
        const n = nodes[index]
        if (!n || !editable || index === 0) return

        toolbarStyle?.classList.add('hidden')
        toolbarActions?.classList.add('hidden')
        titleEditor?.classList.remove('hidden')
        titleEditor?.classList.add('flex')

        if (titleSelect) {
            titleSelect.innerHTML = ''
            const placeholder = document.createElement('option')
            placeholder.value = ''
            placeholder.textContent = 'Selecione um sistema…'
            titleSelect.appendChild(placeholder)

            getSolutionsList().forEach((s) => {
                const opt = document.createElement('option')
                opt.value = String(s.id)
                opt.textContent = s.name
                if (n.solutionId && Number(n.solutionId) === Number(s.id)) opt.selected = true
                titleSelect.appendChild(opt)
            })

            const freeOpt = document.createElement('option')
            freeOpt.value = 'free'
            freeOpt.textContent = 'Outro (texto livre)…'
            if (!n.solutionId) freeOpt.selected = true
            titleSelect.appendChild(freeOpt)
        }
        if (titleLabelInput) {
            titleLabelInput.value = n.solutionId ? '' : (n.label ?? '')
            titleLabelInput.classList.toggle('hidden', !!n.solutionId)
        }
        positionToolbar()
    }

    function closeTitleEditor() {
        if (!titleEditor || titleEditor.classList.contains('hidden')) return
        titleEditor.classList.add('hidden')
        titleEditor.classList.remove('flex')
        toolbarStyle?.classList.remove('hidden')
        toolbarActions?.classList.remove('hidden')
        positionToolbar()
    }

    toolbarTitleBtn?.addEventListener('click', () => { if (selectedIndex !== null) openTitleEditor(selectedIndex) })
    titleCancel?.addEventListener('click', closeTitleEditor)
    titleSelect?.addEventListener('change', () => {
        const isFree = titleSelect.value === 'free'
        titleLabelInput?.classList.toggle('hidden', !isFree)
        if (isFree) {
            titleLabelInput.value = ''
            titleLabelInput.focus()
        }
    })

    titleSave?.addEventListener('click', async () => {
        if (selectedIndex === null || !nodes[selectedIndex]) return
        const value = titleSelect?.value ?? ''
        if (!value) {
            window.Toast?.show?.('Escolha um sistema ou informe o texto livre.', 'warning')
            return
        }
        const isFree = value === 'free'
        const label = isFree ? (titleLabelInput?.value ?? '').trim() : null
        if (isFree && !label) {
            window.Toast?.show?.('Informe o texto livre do nó.', 'warning')
            return
        }

        const url = graphRef?.nodeUpdateUrl?.replace('NODE_INDEX', String(selectedIndex))
        if (!url) return

        titleSave.disabled = true
        if (titleSaveLabel) titleSaveLabel.textContent = 'Salvando…'
        try {
            const res = await fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ solution_id: isFree ? null : value, label }),
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível atualizar o título.')

            applyNodeData(selectedIndex, data.node)
            patchRowGraph(slug, selectedIndex, data.node, data.summary)
            window.Toast?.show?.(data.message || 'Título atualizado.')
            closeTitleEditor()
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível atualizar o título.', 'error')
        } finally {
            titleSave.disabled = false
            if (titleSaveLabel) titleSaveLabel.textContent = 'Salvar'
        }
    })

    // ── adicionar bloco ao final da cadeia ─────────────────────────
    // Painel ancorado ao botão "+" da topbar (não a um nó nem a uma aresta),
    // mesmo par "select de Soluções + texto livre" do editor de título, mais
    // seta/protocolo do novo segmento (mesmas 3 opções hardcoded do form
    // completo de cadeia).
    function openAddEditor() {
        if (!editable || !graphRef) return
        selectNode(null)
        closeProtocolEditor()

        addEditor?.classList.remove('hidden')
        addEditor?.classList.add('flex')

        if (addSelect) {
            addSelect.innerHTML = ''
            const placeholder = document.createElement('option')
            placeholder.value = ''
            placeholder.textContent = 'Selecione um sistema…'
            addSelect.appendChild(placeholder)

            getSolutionsList().forEach((s) => {
                const opt = document.createElement('option')
                opt.value = String(s.id)
                opt.textContent = s.name
                addSelect.appendChild(opt)
            })

            const freeOpt = document.createElement('option')
            freeOpt.value = 'free'
            freeOpt.textContent = 'Outro (texto livre)…'
            addSelect.appendChild(freeOpt)
        }
        if (addLabelInput) {
            addLabelInput.value = ''
            addLabelInput.classList.add('hidden')
        }
        if (addArrowSelect) addArrowSelect.value = '->'
        addProtocolSelect?.classList.remove('hidden')
        if (addProtocolSelect) {
            addProtocolSelect.innerHTML = ''
            const noneOpt = document.createElement('option')
            noneOpt.value = ''
            noneOpt.textContent = 'Sem protocolo'
            addProtocolSelect.appendChild(noneOpt)

            getProtocolsList().forEach((p) => {
                const opt = document.createElement('option')
                opt.value = p.value
                opt.textContent = p.label
                addProtocolSelect.appendChild(opt)
            })
        }
        positionAddEditor()
    }

    function closeAddEditor() {
        if (!addEditor || addEditor.classList.contains('hidden')) return
        addEditor.classList.add('hidden')
        addEditor.classList.remove('flex')
    }

    // Ancorado ao botão "+" da topbar — mesma base `stage` (não `root`) da
    // toolbar/editor de protocolo, pelo mesmo motivo (ver `positionToolbar()`).
    function positionAddEditor() {
        if (!addEditor || !addNodeBtn || addEditor.classList.contains('hidden')) return

        const stageRect = stage.getBoundingClientRect()
        const btnRect = addNodeBtn.getBoundingClientRect()
        const btnLeft = btnRect.left - stageRect.left
        const btnTop = btnRect.top - stageRect.top

        const pw = addEditor.offsetWidth
        const sidebarWidth = isSidebarOpen() ? sidebar.offsetWidth : 0
        const maxLeft = stageRect.width - sidebarWidth - pw - 8

        let left = btnLeft + btnRect.width / 2 - pw / 2
        const top = btnTop + btnRect.height + PANEL_GAP
        left = Math.max(8, Math.min(left, Math.max(8, maxLeft)))

        addEditor.style.left = left + 'px'
        addEditor.style.top = top + 'px'
    }

    addNodeBtn?.addEventListener('click', () => {
        if (addEditor?.classList.contains('hidden')) openAddEditor()
        else closeAddEditor()
    })
    addEditor?.addEventListener('mousedown', (e) => e.stopPropagation())
    addCancel?.addEventListener('click', closeAddEditor)
    addSelect?.addEventListener('change', () => {
        const isFree = addSelect.value === 'free'
        addLabelInput?.classList.toggle('hidden', !isFree)
        if (isFree) {
            addLabelInput.value = ''
            addLabelInput.focus()
        }
    })
    // "Sem conexão" (arrow === '') não tem protocolo — esconde o select
    // nesse caso pra não sugerir um campo que não vai ser enviado.
    addArrowSelect?.addEventListener('change', () => {
        addProtocolSelect?.classList.toggle('hidden', !addArrowSelect.value)
    })

    // Desenha e posiciona o bloco novo à direita do nó ao qual se ligou
    // (`from`, mesmo espaçamento do layout padrão), acrescenta a ligação
    // correspondente e seleciona o bloco novo — sem redesenhar o grafo
    // inteiro. Fica dirty (posição ainda não salva em `viz_layout`), mesmo
    // espírito de `organize()`. `from` pode ser `null` ("Sem conexão" no
    // painel "Adicionar bloco") — o bloco nasce isolado, só posicionado à
    // direita do último bloco, sem nenhuma ligação. De qualquer forma, o
    // usuário pode religar uma ligação existente pra este bloco depois
    // (arrastando a seta) ou usar o "modo ligar" pra criar uma nova até ele.
    function appendNode(data, from, arrow, protocol) {
        const index = nodes.length
        const el = document.createElement('div')
        el.className = 'ak-viz-node'
        paintNode(el, data)
        el.addEventListener('mousedown', (e) => startNodePointer(e, index))
        world.appendChild(el)

        const hasEdge = from !== null && from !== undefined
        const prev = hasEdge ? nodes[from] : nodes[index - 1]
        const entry = { ...data, el, w: 0, h: 0, x: 0, y: 0, color: null, textColor: null, font: 'sans' }
        nodes.push(entry)
        entry.w = el.offsetWidth
        entry.h = el.offsetHeight
        entry.x = prev ? prev.x + prev.w + LEVEL_GAP : 0
        entry.y = prev ? prev.y + prev.h / 2 - entry.h / 2 : 0
        el.style.left = entry.x + 'px'
        el.style.top = entry.y + 'px'
        applyNodeStyle(entry)

        if (graphRef) {
            graphRef.nodes = graphRef.nodes || []
            graphRef.nodes.push(data)
        }
        if (hasEdge) {
            edgeAnchors.push({ from: 'r', to: 'l' })
            if (graphRef) {
                graphRef.edges = graphRef.edges || []
                graphRef.edges.push({ from, to: index, arrow, protocol })
            }
        }

        draw()
        setDirty(true)
        selectNode(index)
        fit()
    }

    // Mesma ideia de `patchRowGraph()`, mas acrescentando (não substituindo)
    // um nó (e, quando houver, a ligação dele) — mantém a linha (lista à
    // esquerda) consistente sem precisar re-selecionar a integração. `from`
    // pode ser `null` (bloco isolado, "Sem conexão").
    function patchRowGraphAppend(slugArg, nodeData, from, arrow, protocolData, summary) {
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
                    if (from !== null && from !== undefined) {
                        g.edges = g.edges || []
                        g.edges.push({ from, to: g.nodes.length - 1, arrow, protocol: protocolData })
                    }
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

    addSave?.addEventListener('click', async () => {
        const value = addSelect?.value ?? ''
        if (!value) {
            window.Toast?.show?.('Escolha um sistema ou informe o texto livre.', 'warning')
            return
        }
        const isFree = value === 'free'
        const label = isFree ? (addLabelInput?.value ?? '').trim() : null
        if (isFree && !label) {
            window.Toast?.show?.('Informe o texto livre do bloco.', 'warning')
            return
        }

        const url = graphRef?.nodeAddUrl
        if (!url) return

        addSave.disabled = true
        if (addSaveLabel) addSaveLabel.textContent = 'Adicionando…'
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    solution_id: isFree ? null : value,
                    label,
                    arrow: addArrowSelect?.value || null,
                    protocol: addArrowSelect?.value ? (addProtocolSelect?.value || null) : null,
                }),
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível adicionar o bloco.')

            appendNode(data.node, data.from, data.arrow, data.protocol)
            patchRowGraphAppend(slug, data.node, data.from, data.arrow, data.protocol, data.summary)
            window.Toast?.show?.(data.message || 'Bloco adicionado.')
            closeAddEditor()
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível adicionar o bloco.', 'error')
        } finally {
            addSave.disabled = false
            if (addSaveLabel) addSaveLabel.textContent = 'Adicionar'
        }
    })

    // ── nome/status da integração selecionada ──────────────────────
    // Único metadado que não mora num nó/aresta da chain — ancorado ao lápis
    // da topbar (`data-viz-meta-edit`), mesmo padrão do painel "Adicionar
    // bloco". Não mexe na chain, só em `name`/`status` (PATCH em
    // `graphRef.metaUpdateUrl`, o mesmo endpoint do form "Nova" da lista).
    function openMetaEditor() {
        if (!editable || !graphRef) return
        selectNode(null)
        closeProtocolEditor()
        closeAddEditor()

        metaEditor?.classList.remove('hidden')
        metaEditor?.classList.add('flex')

        if (metaNameInput) metaNameInput.value = topbarTitle?.textContent ?? ''
        if (metaStatusSelect) {
            metaStatusSelect.innerHTML = ''
            getStatusesList().forEach((s) => {
                const opt = document.createElement('option')
                opt.value = s.value
                opt.textContent = s.label
                if (s.value === graphRef.status) opt.selected = true
                metaStatusSelect.appendChild(opt)
            })
        }
        positionMetaEditor()
    }

    function closeMetaEditor() {
        if (!metaEditor || metaEditor.classList.contains('hidden')) return
        metaEditor.classList.add('hidden')
        metaEditor.classList.remove('flex')
    }

    // Ancorado ao lápis da topbar — mesma base `stage` (não `root`) dos
    // outros painéis, pelo mesmo motivo (ver `positionToolbar()`).
    function positionMetaEditor() {
        if (!metaEditor || !metaEditBtn || metaEditor.classList.contains('hidden')) return

        const stageRect = stage.getBoundingClientRect()
        const btnRect = metaEditBtn.getBoundingClientRect()
        const btnLeft = btnRect.left - stageRect.left
        const btnTop = btnRect.top - stageRect.top

        const pw = metaEditor.offsetWidth
        const sidebarWidth = isSidebarOpen() ? sidebar.offsetWidth : 0
        const maxLeft = stageRect.width - sidebarWidth - pw - 8

        let left = btnLeft + btnRect.width / 2 - pw / 2
        const top = btnTop + btnRect.height + PANEL_GAP
        left = Math.max(8, Math.min(left, Math.max(8, maxLeft)))

        metaEditor.style.left = left + 'px'
        metaEditor.style.top = top + 'px'
    }

    metaEditBtn?.addEventListener('click', () => {
        if (metaEditor?.classList.contains('hidden')) openMetaEditor()
        else closeMetaEditor()
    })
    metaEditor?.addEventListener('mousedown', (e) => e.stopPropagation())
    metaCancel?.addEventListener('click', closeMetaEditor)

    // Mantém a linha (lista à esquerda) e a topbar consistentes sem precisar
    // reselecionar a integração — mesma ideia de `patchRowGraph()`.
    function patchRowMeta(slugArg, name, statusLabel) {
        if (topbarTitle) topbarTitle.textContent = name
        if (!slugArg) return
        const row = document.querySelector(`[data-ak-integration-select="${CSS.escape(slugArg)}"]`)
        if (!row) return

        row.setAttribute('data-integration-name', name)
        row.querySelector('[data-ak-integration-name]')?.replaceChildren(document.createTextNode(name))
        if (statusLabel) row.querySelector('[data-ak-integration-status]')?.replaceChildren(document.createTextNode(statusLabel))
    }

    metaSave?.addEventListener('click', async () => {
        const name = (metaNameInput?.value ?? '').trim()
        if (!name) {
            window.Toast?.show?.('Informe o nome da integração.', 'warning')
            return
        }
        const status = metaStatusSelect?.value ?? ''

        const url = graphRef?.metaUpdateUrl
        if (!url) return

        metaSave.disabled = true
        if (metaSaveLabel) metaSaveLabel.textContent = 'Salvando…'
        try {
            const res = await fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ name, status }),
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível atualizar a integração.')

            graphRef.status = status
            const statusLabel = getStatusesList().find((s) => s.value === status)?.label ?? null
            patchRowMeta(slug, name, statusLabel)
            window.Toast?.show?.(data.message || 'Integração atualizada.')
            closeMetaEditor()
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível atualizar a integração.', 'error')
        } finally {
            metaSave.disabled = false
            if (metaSaveLabel) metaSaveLabel.textContent = 'Salvar'
        }
    })

    // ── ligação: select de sentido + protocolo do enum Protocol ─────────
    // Um único painel, dois modos (`edgeEditorMode`): "edit" (ligação já
    // existente, ancorado à pill — `selectEdge()`/`openProtocolEditor()`) e
    // "create" (ligação nova entre dois blocos, ancorado ao bloco de destino
    // — `openConnectEditor()`, ver "modo ligar" mais abaixo). Ao contrário do
    // nó, não existe segmento "raiz" protegido — qualquer aresta pode ter
    // seu protocolo/sentido editados, inclusive as que ainda não têm um
    // protocolo (pill tracejada "+ protocolo", desenhada em `drawProtocolPill()`).
    let protocolAnchorNode = null // índice do nó de destino, só em modo 'create' (âncora do painel em vez da pill)

    function selectEdge(index) {
        if (!editable) return
        selectNode(null) // exclusão mútua com seleção de nó/editor de título
        cancelLinking()
        selectedEdge = index
        openProtocolEditor(index)
    }

    function openProtocolEditor(index) {
        if (!protocolEditor || !graphRef) return
        edgeEditorMode = 'edit'
        pendingConnect = null
        protocolAnchorNode = null
        const edge = graphRef.edges?.[index]
        const current = edge?.protocol ?? null

        if (protocolArrowSelect) protocolArrowSelect.value = edge?.arrow || '->'
        if (protocolSelect) {
            protocolSelect.innerHTML = ''
            const noneOpt = document.createElement('option')
            noneOpt.value = ''
            noneOpt.textContent = 'Sem protocolo'
            if (!current) noneOpt.selected = true
            protocolSelect.appendChild(noneOpt)

            getProtocolsList().forEach((p) => {
                const opt = document.createElement('option')
                opt.value = p.value
                opt.textContent = p.label
                if (current?.value === p.value) opt.selected = true
                protocolSelect.appendChild(opt)
            })
        }
        protocolDelete?.classList.remove('hidden')
        if (protocolSaveLabel) protocolSaveLabel.textContent = 'Salvar'

        protocolEditor.classList.remove('hidden')
        protocolEditor.classList.add('flex')
        positionProtocolEditor()
    }

    // Painel de ligação NOVA entre `fromIndex` e `toIndex` (dois blocos já
    // existentes) — completou o "modo ligar" (ver `startLinking()`).
    // Ancorado ao bloco de destino, não a uma pill (ainda não existe uma).
    function openConnectEditor(fromIndex, toIndex) {
        if (!protocolEditor || !graphRef?.edgeAddUrl) return
        selectNode(null)
        edgeEditorMode = 'create'
        pendingConnect = { from: fromIndex, to: toIndex }
        selectedEdge = null
        protocolAnchorNode = toIndex

        if (protocolArrowSelect) protocolArrowSelect.value = '->'
        if (protocolSelect) {
            protocolSelect.innerHTML = ''
            const noneOpt = document.createElement('option')
            noneOpt.value = ''
            noneOpt.textContent = 'Sem protocolo'
            noneOpt.selected = true
            protocolSelect.appendChild(noneOpt)

            getProtocolsList().forEach((p) => {
                const opt = document.createElement('option')
                opt.value = p.value
                opt.textContent = p.label
                protocolSelect.appendChild(opt)
            })
        }
        protocolDelete?.classList.add('hidden')
        if (protocolSaveLabel) protocolSaveLabel.textContent = 'Ligar'

        protocolEditor.classList.remove('hidden')
        protocolEditor.classList.add('flex')
        positionProtocolEditor()
    }

    function closeProtocolEditor() {
        if (!protocolEditor || protocolEditor.classList.contains('hidden')) return
        protocolEditor.classList.add('hidden')
        protocolEditor.classList.remove('flex')
        selectedEdge = null
        edgeEditorMode = null
        pendingConnect = null
        protocolAnchorNode = null
    }

    // Ancorado à pill do segmento (SVG `<g>` guardado em `edgeLabelEls`) em
    // modo "edit", ou ao bloco de destino em modo "create" — nunca a `root`,
    // mesma base `stage` da toolbar, pelo mesmo motivo (ver `positionToolbar()`).
    function positionProtocolEditor() {
        if (!protocolEditor || protocolEditor.classList.contains('hidden')) return

        let anchorRect = null
        if (protocolAnchorNode !== null && nodes[protocolAnchorNode]) {
            anchorRect = nodes[protocolAnchorNode].el.getBoundingClientRect()
        } else if (selectedEdge !== null && edgeLabelEls[selectedEdge]) {
            anchorRect = edgeLabelEls[selectedEdge].getBoundingClientRect()
        }
        if (!anchorRect) return

        const stageRect = stage.getBoundingClientRect()
        const anchorLeft = anchorRect.left - stageRect.left
        const anchorTop = anchorRect.top - stageRect.top

        const pw = protocolEditor.offsetWidth
        const ph = protocolEditor.offsetHeight
        const sidebarWidth = isSidebarOpen() ? sidebar.offsetWidth : 0
        const maxLeft = stageRect.width - sidebarWidth - pw - 8

        let left = anchorLeft + anchorRect.width / 2 - pw / 2
        let top = anchorTop - ph - PANEL_GAP
        if (top < 8) top = anchorTop + anchorRect.height + PANEL_GAP
        left = Math.max(8, Math.min(left, Math.max(8, maxLeft)))

        protocolEditor.style.left = left + 'px'
        protocolEditor.style.top = top + 'px'
    }

    protocolEditor?.addEventListener('mousedown', (e) => e.stopPropagation())
    protocolCancel?.addEventListener('click', closeProtocolEditor)

    protocolSave?.addEventListener('click', () => {
        if (edgeEditorMode === 'create') createEdge()
        else saveEdgeEdit()
    })

    async function saveEdgeEdit() {
        if (selectedEdge === null) return
        const protocol = protocolSelect?.value ?? ''
        const arrow = protocolArrowSelect?.value || '->'

        const url = graphRef?.edgeUpdateUrl?.replace('EDGE_INDEX', String(selectedEdge))
        if (!url) return

        protocolSave.disabled = true
        if (protocolSaveLabel) protocolSaveLabel.textContent = 'Salvando…'
        try {
            const res = await fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ protocol: protocol || null, arrow }),
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível atualizar a ligação.')

            if (graphRef.edges?.[selectedEdge]) {
                graphRef.edges[selectedEdge].protocol = data.protocol
                graphRef.edges[selectedEdge].arrow = data.arrow
            }
            patchRowEdge(slug, selectedEdge, data.protocol, data.arrow)
            draw()
            window.Toast?.show?.(data.message || 'Ligação atualizada.')
            closeProtocolEditor()
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível atualizar a ligação.', 'error')
        } finally {
            protocolSave.disabled = false
            if (protocolSaveLabel) protocolSaveLabel.textContent = 'Salvar'
        }
    }

    // Cria a ligação nova pendente do "modo ligar" (`pendingConnect`) — POST
    // em `graphRef.edgeAddUrl`, sem tocar em nós. Acrescenta a ligação
    // localmente (mesmo espírito de `appendNode()`), sem redesenhar o grafo
    // inteiro.
    async function createEdge() {
        if (!pendingConnect || !graphRef?.edgeAddUrl) return
        const { from, to } = pendingConnect
        const arrow = protocolArrowSelect?.value || '->'
        const protocol = protocolSelect?.value ?? ''

        protocolSave.disabled = true
        if (protocolSaveLabel) protocolSaveLabel.textContent = 'Ligando…'
        try {
            const res = await fetch(graphRef.edgeAddUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ from, to, arrow, protocol: protocol || null }),
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível criar a ligação.')

            graphRef.edges = graphRef.edges || []
            graphRef.edges.push({ from: data.from, to: data.to, arrow: data.arrow, protocol: data.protocol })
            edgeAnchors.push({ from: 'r', to: 'l' })
            patchRowGraphAddEdge(slug, data.from, data.to, data.arrow, data.protocol, data.summary)
            draw()
            window.Toast?.show?.(data.message || 'Ligação criada.')
            closeProtocolEditor()
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível criar a ligação.', 'error')
        } finally {
            protocolSave.disabled = false
            if (protocolSaveLabel) protocolSaveLabel.textContent = 'Ligar'
        }
    }

    // Remove a ligação em edição (só existe em modo "edit") — DELETE em
    // `graphRef.edgeRemoveUrl`. Os nós continuam existindo; se essa era a
    // única ligação de um bloco, ele passa a aparecer isolado no grafo.
    protocolDelete?.addEventListener('click', async () => {
        if (edgeEditorMode !== 'edit' || selectedEdge === null) return
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

    // Ancora a toolbar acima do nó selecionado (abaixo se não couber),
    // com clamp horizontal — recalculada em pan/zoom/fit/resize/drag/fullscreen.
    //
    // A base das contas é `stage` (o wrapper `[data-viz-stage]`, ancestro
    // posicionado mais próximo da toolbar), NUNCA `root`: `root` também é
    // `position:relative`, mas inclui a topbar acima do canvas. Usar
    // `root.getBoundingClientRect()` somava a altura da topbar por engano a
    // todo cálculo de "top", empurrando a toolbar pra baixo — na prática ela
    // caía em cima do próprio bloco em vez de ficar acima dele (bug relatado:
    // "tampa metade do bloco verticalmente").
    function positionToolbar() {
        if (!toolbar) return
        if (selectedIndex === null || !nodes[selectedIndex]) return

        const stageRect = stage.getBoundingClientRect()
        const nodeRect = nodes[selectedIndex].el.getBoundingClientRect()
        const nodeLeft = nodeRect.left - stageRect.left
        const nodeTop = nodeRect.top - stageRect.top

        const tw = toolbar.offsetWidth
        const th = toolbar.offsetHeight
        const sidebarWidth = isSidebarOpen() ? sidebar.offsetWidth : 0
        const maxLeft = stageRect.width - sidebarWidth - tw - 8

        let left = nodeLeft + nodeRect.width / 2 - tw / 2
        let top = nodeTop - th - PANEL_GAP
        if (top < 8) top = nodeTop + nodeRect.height + PANEL_GAP
        left = Math.max(8, Math.min(left, Math.max(8, maxLeft)))

        toolbar.style.left = left + 'px'
        toolbar.style.top = top + 'px'
    }

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
        positionToolbar()
        positionProtocolEditor()
        positionAddEditor()
        positionMetaEditor()
        positionBottomBar()
    }

    function closeComment() {
        if (!sidebar) return
        sidebar.classList.add('translate-x-full')
        commentIndex = null
        positionToolbar()
        positionProtocolEditor()
        positionAddEditor()
        positionMetaEditor()
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
    sidebar?.addEventListener('mousedown', (e) => e.stopPropagation())
    sidebarClose?.addEventListener('click', closeComment)

    toolbar?.addEventListener('mousedown', (e) => e.stopPropagation())
    toolbarComment?.addEventListener('click', () => { if (selectedIndex !== null) openComment(selectedIndex) })
    toolbarOpen?.addEventListener('click', () => {
        const n = nodes[selectedIndex]
        if (n?.url) window.location.href = n.url
    })

    // ── "modo ligar": clique num bloco ativa, clique em outro bloco
    // qualquer cria uma ligação NOVA entre os dois (ver `openConnectEditor()`
    // acima) — diferente de arrastar o handle de uma ligação já existente
    // (`retargetEdge`), não depende de nenhuma ligação prévia. Cancela com
    // Esc, com o botão do hint, ou clicando no fundo do canvas.
    function startLinking(fromIndex) {
        linking = fromIndex
        selectNode(null)
        viewport.classList.add('is-linking')
        linkHint?.classList.remove('hidden')
        linkHint?.classList.add('flex')
    }

    function cancelLinking() {
        if (linking === null) return
        linking = null
        viewport.classList.remove('is-linking')
        linkHint?.classList.add('hidden')
        linkHint?.classList.remove('flex')
    }

    toolbarLinkBtn?.addEventListener('click', () => { if (selectedIndex !== null) startLinking(selectedIndex) })
    linkHint?.addEventListener('mousedown', (e) => e.stopPropagation())
    linkCancelBtn?.addEventListener('click', cancelLinking)

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
        // "Modo ligar" ativo: este clique completa (ou cancela, se for no
        // mesmo bloco de origem) a ligação, em vez de selecionar/arrastar o
        // bloco normalmente.
        if (linking !== null) {
            const fromIndex = linking
            cancelLinking()
            if (index !== fromIndex) openConnectEditor(fromIndex, index)
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
        if (!nodes.length) {
            applyView()
            return
        }
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
        const vw = viewport.clientWidth
        const vh = viewport.clientHeight
        const cw = maxX - minX + FIT_PAD * 2
        const ch = maxY - minY + FIT_PAD * 2
        view.scale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, Math.min(vw / cw, vh / ch)))
        view.x = (vw - (maxX + minX) * view.scale) / 2
        view.y = (vh - (maxY + minY) * view.scale) / 2
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
            })),
            edges: edgeAnchors.map((a) => ({ from: a.from, to: a.to })),
            comments: nodes.map((n) => n.comment || null),
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

    viewport.addEventListener('mousedown', (e) => {
        if (e.button !== 0 || drag) return
        if (linking !== null) { cancelLinking(); return }
        selectNode(null)
        panning = true
        sx = e.clientX
        sy = e.clientY
        ox = view.x
        oy = view.y
        viewport.classList.add('is-panning')
    })
    window.addEventListener('mousemove', (e) => {
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
        if (!panning) return
        view.x = ox + (e.clientX - sx)
        view.y = oy + (e.clientY - sy)
        applyView()
    })
    window.addEventListener('mouseup', () => {
        if (drag) {
            if (drag.type === 'node') {
                nodes[drag.index]?.el.classList.remove('is-dragging')
                if (!drag.moved) selectNode(drag.index)
                else if (editable) setDirty(true)
            } else if (drag.type === 'handle') {
                if (drag.targetNode !== drag.origNode) {
                    // Aplica otimista antes do PATCH — ver `retargetEdge()`.
                    graphRef.edges[drag.edge][drag.end] = drag.targetNode
                    retargetEdge(drag.edge, drag.end, drag.targetNode, drag.origNode)
                } else {
                    setDirty(true)
                }
            }
            drag = null
            draw()
        }
        panning = false
        viewport.classList.remove('is-panning')
    })

    viewport.addEventListener('wheel', (e) => {
        e.preventDefault()
        zoomAt(e.deltaY < 0 ? 1.08 : 1 / 1.08, e.clientX, e.clientY)
    }, { passive: false })

    // touch: pan com um dedo
    let tpan = false
    let tsx = 0
    let tsy = 0
    let tox = 0
    let toy = 0
    viewport.addEventListener('touchstart', (e) => {
        if (e.touches.length !== 1) return
        tpan = true
        tsx = e.touches[0].clientX
        tsy = e.touches[0].clientY
        tox = view.x
        toy = view.y
    }, { passive: true })
    viewport.addEventListener('touchmove', (e) => {
        if (!tpan || e.touches.length !== 1) return
        view.x = tox + (e.touches[0].clientX - tsx)
        view.y = toy + (e.touches[0].clientY - tsy)
        applyView()
    }, { passive: true })
    viewport.addEventListener('touchend', () => {
        tpan = false
    })

    // ── controles ────────────────────────────────────────────────
    root.querySelector('[data-viz-zoom-in]')?.addEventListener('click', () => zoomAt(1.12))
    root.querySelector('[data-viz-zoom-out]')?.addEventListener('click', () => zoomAt(1 / 1.12))
    root.querySelector('[data-viz-fit]')?.addEventListener('click', fit)
    root.querySelector('[data-viz-fit-top]')?.addEventListener('click', fit)
    organizeBtn?.addEventListener('click', organize)
    saveBtn?.addEventListener('click', save)

    // ── tela cheia do navegador (botão do rodapé + botão da barra do topo) ──
    const fsOpen = root.querySelector('[data-viz-fs-open]')
    const fsClose = root.querySelector('[data-viz-fs-close]')
    const fsOpenTop = root.querySelector('[data-viz-fs-open-top]')
    const fsCloseTop = root.querySelector('[data-viz-fs-close-top]')
    function toggleFullscreen() {
        if (document.fullscreenElement === root) document.exitFullscreen?.()
        else root.requestFullscreen?.()
    }
    root.querySelector('[data-viz-fullscreen]')?.addEventListener('click', toggleFullscreen)
    root.querySelector('[data-viz-fullscreen-top]')?.addEventListener('click', toggleFullscreen)
    document.addEventListener('fullscreenchange', () => {
        const isFs = document.fullscreenElement === root
        fsOpen?.classList.toggle('hidden', isFs)
        fsClose?.classList.toggle('hidden', !isFs)
        fsOpenTop?.classList.toggle('hidden', isFs)
        fsCloseTop?.classList.toggle('hidden', !isFs)
        requestAnimationFrame(() => requestAnimationFrame(() => { fit(); positionAddEditor(); positionMetaEditor() }))
    })

    window.addEventListener('resize', () => {
        if (nodes.length) fit()
        else positionToolbar()
        positionAddEditor()
        positionMetaEditor()
        positionBottomBar()
    })

    // Esc cancela o "modo ligar" (se ativo) ou fecha a sidebar de comentário.
    window.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return
        if (linking !== null) { cancelLinking(); return }
        if (isSidebarOpen()) closeComment()
    })

    root.__akVizRender = render
    setDirty(false)
    applyView()
}
