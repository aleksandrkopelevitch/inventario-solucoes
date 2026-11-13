{{--
    Graphical visualization of the diagram — mounted by
    `Diagrams\Workspace` inside the Diagrama tab of the
    diagram's own unified page (Documentação/Diagrama). JS-driven canvas
    (legitimate exception to "utilities over custom CSS", like flow-canvas):
    absolutely-positioned nodes + SVG edges, with pan/zoom and browser
    fullscreen. Draws the chain (`chain = {nodes, edges}`, each node
    `{solution_id, label, kind}` and each edge `{from, to, arrow, protocol}`
    by node index) of the diagram in scope on this page — a genuinely
    FREE GRAPH, not a straight line, and it doesn't require every block to be
    linked to something: blocks and links are created independently of each
    other. Editable/extensible things in
    place: a NEW block, via the "+" button in the topbar ("Adicionar bloco"
    panel) — a single horizontal row of kind icons
    (`App\Enums\ChainNodeKind`: sistema, decisão, ator, início, fim,
    `chain-viz.js::buildAddKindIcons()`), no label text, `title` as the
    tooltip; clicking one creates a PURE block of that kind IMMEDIATELY
    (`createNodeFromKind()`) — no arrow, no protocol, and no Solution/text
    field in this panel at all — with a placeholder text (the kind's own
    name) that the very next thing, `startInlineLabelEdit()`, opens straight
    away so the real name (or, for `system`, a linked Solution) is typed
    directly on the canvas in the same gesture, not in a form (início/fim
    default their own free text to "Início"/"Fim" if left blank instead); a
    block's kind (except the root, index 0, and never a pasted image), via a
    horizontal row of icon-only buttons — same kinds, no label text, `title`
    attribute as the tooltip — that's the SECOND row of the block's own
    contextual toolbar (`data-viz-toolbar-kind`,
    `chain-viz.js::refreshKindRow()`/`changeNodeKind()`), applying the
    change immediately (PATCH), no separate "Salvar" step; and a block's
    text, by double-clicking it right on the shape
    (`startInlineLabelEdit()`) — on a `system` block this doubles as the
    Solution picker: typing filters `getSolutionsList()` into an inline
    autocomplete dropdown anchored to the block itself, picking a match (or
    typing a name that matches one exactly) links that Solution, anything
    else stays free text; decisão/ator/início/fim have no Solution to search,
    so it's plain text there. A `system` block's displayed name is always
    the linked Solution's, never a leftover free-text label
    (`ChainLabeler::nodeLabel()`) — the autocomplete is what keeps the two
    from ever colliding; a NEW
    link, by dragging an arrow out of any block's connection port (the 4
    small circles that show up on hover) and dropping it on any other block —
    created straight away as `->` with no protocol, refined afterwards on the
    pill — or dropping it on EMPTY canvas, which opens the same "Adicionar
    bloco" icon row (fixed top-left, same as the topbar "+" version); clicking
    a kind creates the block AT THE DROP SPOT and links it to the port it was
    dragged from in one motion, immediately (no separate save step) — only the
    new block's own position follows the drop point, never the panel's;
    direction
    of any link, by clicking the pill above the arrow (including the dashed "+
    protocolo" pill, when it doesn't have one yet) and toggling either arrow
    icon in the small panel that opens — applies immediately, no "Salvar"
    button anywhere in this editor — which also has a "Desligar" button that
    removes only the link, never the blocks; the link's protocol itself, by
    DOUBLE-clicking that same pill, which turns its text directly into an
    editable field right on the canvas (free text, with an autocomplete
    dropdown of `App\Enums\Protocol` values as suggestions only) — same
    pattern as a block's own inline label editing, and again applied the
    instant it's confirmed; and retargeting any link to a different block, by
    dragging the arrow's tip to it. All of these actions touch `chain` (the
    topology's source of truth) and
    re-run SyncDiagramFromChain on the server — they aren't "purely visual"
    tweaks like position/color/comment (those stay only in `viz_layout`).
    This is the app's only topology editor — there's no separate form/modal
    anymore. Data arrives already resolved in each row's
    `data-ak-chain-graph` (see Solutions\Diagrams);
    `chain-viz.js` reads and draws it. Arrows follow the link's
    direction (`->` forward, `<-` backward, `<->` both) and each arrow's
    label is the protocol.

    A fifth adjustment, on the topbar's pencil (not the block's): name/status
    of the selected diagram — the only metadata that doesn't live on a
    node/edge. Creating a new Diagram is the "Nova" form in the list on
    the diagrams index (`diagrams/index.blade.php`), which already delivers the chain
    with only the root node; from there on, it's all done here.

    Two more adjustments are purely visual (`viz_layout`, never `chain`):
    a block's border and an arrow can each be toggled dashed independently
    (an icon toggle button in each one's own contextual panel — never a
    checkbox, see below), and lanes (`data-viz-lanes`)
    — free rectangles drawn behind the canvas, with a title strip filled a
    shade darker than the rest of the rectangle to stand out — mark an area
    of the flow as a background layout aid; a lane never references a node,
    the user just drags blocks into it like anywhere else on the canvas.
    Clicking the topbar's "Raias" button adds one straight away, centered on
    the current viewport, no panel/dialog in between. Position and size are
    edited directly on the canvas: dragging a lane's body (including its
    title strip) moves it, dragging one of its 3 edge/corner handles resizes
    it — right edge width-only, bottom edge height-only, corner both at once.
    A plain click (no drag) specifically on the darker title strip — or
    anywhere on the body when the lane has no title, see below — opens the
    lane's own small toolbar (`data-viz-lane-toolbar`): preset color
    swatches (same palette-of-presets pattern as a block's own toolbar), a
    name field (typing renames it live), a remove button, and a style
    cluster covering everything this feature adds: square/rounded corners,
    solid/dashed border, background fill (solid, or a diagonal/crosshatch
    stripe pattern, all in the lane's own color with an adjustable opacity),
    horizontal orientation (the classic strip described above, title
    running the full height of the left edge) vs. vertical (title strip
    moves to the top edge instead, full width, plain left-to-right text —
    for a flow that reads top-to-bottom rather than left-to-right), and a
    title on/off switch — with no title, the lane keeps rendering as a
    plain tinted/patterned rectangle but the toolbar-opening click target
    moves from the (now absent) strip to the whole body, mutually exclusive
    with a block's toolbar, the same way selecting a block closes this one
    and vice versa.

    A "post-it" annotation (`data-viz-add-note`) is the same idea as a lane,
    stripped down further: free multiline text at a world-space `x`/`y`,
    always the post-it yellow (no color/style choice — this one is
    deliberately BASIC), no toolbar of its own — the note itself IS the
    control: drag its top strip to move it, type directly in its body
    (a `<textarea>` so multiline actually round-trips, growing in height on
    its own as the text does — see `chain-viz.js::rebuildNotes()` for
    why this can't be a `contenteditable`), click the × in that same strip to
    remove it. Clicking the topbar's button adds one straight away, centered
    on the current viewport and already focused, no panel/dialog in between —
    same immediacy as adding a lane. Unlike a lane, a note renders ABOVE
    everything else in `world` (nodes, edges, lanes) — a post-it is stuck
    on top of the diagram, not behind it.

    Presentation mode (`data-viz-present-toggle`, bottom bar) is a separate,
    purely visual viewing mode — never persisted, resets every time it's
    (re-)entered — that animates up to 5 glowing dots traveling along the
    chain's arrows, one per BRANCH of the flow: `chain-viz.js`'s
    `computePresentationPaths()` walks the graph from every root (a node
    with no incoming edge), always following the lowest-index outgoing edge
    to continue the current dot's path, and spawning one new dot per
    additional outgoing edge the first time a branching node is reached by
    any path — capped at 5, with its own cycle guard so a graph that loops
    back on itself (e.g. a retry) just reads as a continuous loop rather
    than an infinite walk at path-build time. Every block AND every arrow
    (plus its protocol pill, if it has one) starts invisible. A block fades
    in the instant a dot's own first lap reaches it; an arrow fades in the
    instant a dot BEGINS travelling it (`revealEdge()`) — the first edge of
    each path is visible right away, same as that path's start block, since
    a dot starts travelling it at t=0. Anything outside any of the ≤5
    animated paths (block or arrow, beyond the branch cap) still fades in —
    once every dot has completed its first lap, whatever's left is revealed
    together, so nothing stays hidden forever. The one exception: a block
    with NO edge at all (`computeIsolatedNodes()`, degree zero either
    direction) has no dot that will ever reach it, so it doesn't wait for
    that end-of-first-lap sweep either — it fades in right as presenting
    starts (a `transition: none` + forced-reflow reset first, so the fade
    isn't cut short mid-way by immediately reverting the same transition
    it's riding — see the comment at that call site).
    A `<select>` next to the toggle button
    (visible only while presenting) sets a manual speed multiplier
    (0.5x–1.5x) shared by every dot, live, without resetting anyone's
    progress. Entering the mode disables every editing/selection
    interaction (reusing the same `editable` gate every drag/click handler
    already checks, forced to `false` for the duration) while leaving
    pan/zoom free — it's available to a read-only viewer too, not just an
    editor. Esc exits it, same as the rest of this canvas's Esc-closes-
    everything convention.

    Export (`data-viz-export-toggle`, same bottom bar) turns the diagram into
    a PNG or an animated GIF for slides/video — entirely client-side, no new
    route/job: `captureDiagramCanvas()` clones the live `#world` (never the
    real DOM — `html-to-image`'s `toCanvas()` works on a detached clone, so
    nothing here ever visibly flickers on the user's own screen) with an
    overridden `transform` that maps `contentBBox()` (nodes ∪ lanes — the
    union matters because a lane resized larger than its blocks is still
    part of what a viewer expects to see exported) onto an output canvas
    sized to THAT box's own aspect ratio. This is the deliberate difference
    from `fit()`: `fit()` letterboxes content into whatever shape the open
    browser window happens to be (correct for editing — the viewport's shape
    isn't the diagram's business), which is exactly what left the blank
    left/right margins in the first exported preview. An export has no
    viewport to fit into — so its canvas dimensions are DERIVED from the
    content instead, and the fit is always exact, never letterboxed.
    `exportVideo()` reuses `enterPresentation()` verbatim (entering it first
    if not already presenting, restoring the prior state after) and takes a
    fresh `captureDiagramCanvas()` snapshot back-to-back, no artificial delay
    between them — each capture (a full DOM clone + serialize + rasterize) is
    already far slower than any inter-frame wait worth imposing, so the wait
    was pure choppiness with no upside; smaller resolution
    (`EXPORT_GIF_LONG_SIDE`, vs. `EXPORT_LONG_SIDE` for the still PNG) is the
    lever that actually buys more frames per second of recording. Every frame
    feeds into `gif.js` (a Web Worker encoder, `workerScript` resolved via
    Vite's `?url` import of `gif.worker.js` — plain UMD module, not built for
    bundling, so it can't take the usual ESM import path) with the REAL
    elapsed time between captures as that frame's delay, so encoding jitter
    changes how many frames make it in, never the GIF's actual playback
    speed. The
    nested `<svg data-viz-edges>` needs its OWN internal `<style>` (right
    after the opening tag, below) duplicating the edge/marker/pill rules —
    see the comment there for why the outer stylesheet doesn't reach it once
    exported.

    "Tema" (bottom bar, `<select data-viz-theme>`) is a LIVE canvas state, not
    an export-only setting — `chain-viz.js::applyTheme()` sets
    `data-viz-preset` on `world` and on the edges `<svg>` and leaves it there
    for as long as that theme is active, persisted in `viz_layout.theme`
    (same "Salvar" gate as moving a block — picking a theme marks the diagram
    dirty, it doesn't save itself). Each theme's colors live in THREE places,
    on purpose, not one: `EXPORT_PRESETS` (`chain-viz.js`) holds only
    the flat canvas `bg` — the one value a detached export clone genuinely
    can't get from CSS (`captureDiagramCanvas()` passes it straight to
    `toCanvas()`'s `backgroundColor`, and canvas fills can't do the gradient
    "corporativo" actually uses live, hence that one being an approximation);
    block border/shadow lives in this component's OUTER `<style>` (nodes are
    plain HTML — computed style survives the export clone same as any other
    property); edge/marker/pill color lives in the edges SVG's OWN internal
    `<style>` (below — nested SVGs get raw-cloned wholesale on export, so
    that stylesheet has to be self-contained). Because the theme is live now,
    `captureDiagramCanvas()` doesn't toggle the attribute itself anymore —
    whatever theme is showing IS what gets captured.

    The last two themes ("Arquitetura", "Blueprint") shorten the first two of
    those three: they redefine the `--viz-*` TOKENS on the viewport instead of
    restating each rule, so the canvas ground, the edge stroke, the marker, the
    pill's hairline, the free block's dashed border and the selection ring all
    follow from five values. They still carry their own literals in the edges
    SVG's internal `<style>`, and they must: that sheet is the only one the
    export clone keeps, and a custom property set on an excluded ancestor
    doesn't survive into it (see the note above that block). A first attempt at this
    "give it a different look" idea
    sent the exported PNG to Gemini for a visual restyle ("Estilizar com
    IA") — removed 2026-08-03 after it reliably garbled small text ("SAP
    S/4HANA" → "SAM4AMA", "AllStrategy" → "AllSnatag"), since a generative
    model redraws pixels instead of applying a stylesheet. Themes
    deliberately touch ONLY color (never font, size, or padding) — the same
    DOM, the same box model, so nothing can wrap differently under any theme
    than the canvas already does today.

    Chrome (top bar, contextual selection toolbar, comment sidebar) in
    Tailwind utilities + `x-forms.button`, following the Leo brand. The
    internal canvas (nodes/edges/handles) keeps the `<style>` block scoped
    with `--viz-*` tokens, now mirroring the navy/blue palette of the
    reference mind-map (the only sanctioned exception to "utilities over
    custom CSS" in this view).
--}}
<div data-ak-chain-viz
    class="ak-viz relative flex min-h-[360px] flex-1 flex-col overflow-hidden bg-surface">

    {{-- Top bar: view actions (center / fullscreen / save). No topology-authoring action lives here — only the topology,
         always the chain, decides nodes and edges.

         It doesn't name the diagram either: the canvas is always mounted
         inside that diagram's own page, whose top bar sits ~40px above
         this one and already carries the name AND the status
         (`Diagrams\Meta`). This bar used to repeat the name, from
         back when the canvas lived beside a rail of several diagrams —
         and after the name became editable up there, the copy down here was
         simply the stale one. --}}
    <div data-viz-topbar class="ak-viz-topbar flex shrink-0 items-center gap-3 border-b border-line bg-surface px-3 py-2">
        {{-- Interaction hint, condensed into a discreet "?" so the authoring
             actions keep their room in this narrow bar. A
             click-triggered popover (`data-ak-toggle`, same pattern as the
             user menu/share dropdowns), not just a hover `title` — a hover
             tooltip never reaches touch users, and Ctrl+V-to-paste-an-image
             has NO other on-screen affordance at all, so it has to be
             discoverable here. `data-viz-hint` itself is presentational only
             — no JS hook in chain-viz.js reads it. --}}
        <div class="relative shrink-0">
            <x-forms.button type="button" variant="ghost" data-viz-hint data-ak-toggle="viz-hint-popover" data-ak-toggle-classes="hidden" data-ak-toggle-blur="true"
                title="Atalhos do canvas" aria-label="Atalhos do canvas"
                class="!rounded-md !p-1.5 !text-faint hover:!bg-accent-soft hover:!text-ink">
                <x-heroicon-o-question-mark-circle class="size-4" />
            </x-forms.button>
            {{-- Two lists, one per pointer kind: the gestures are the same
                 (everything has run on POINTER events since the touch-support
                 pass), the vocabulary and the caveats are not. A touch device
                 has no hover — the connection dot only appears after you tap
                 the block — no mouse wheel and no Ctrl+V; telling somebody on
                 a tablet that "the mouse wheel zooms" is worse than saying
                 nothing. `(pointer: coarse)` is the same query that grows the
                 touch targets in the CSS below. --}}
            <div id="viz-hint-popover" class="hidden absolute right-0 top-full z-30 mt-1.5 w-64 rounded-field border border-line bg-surface p-3 text-xs text-ink shadow-xl">
                <ul class="flex flex-col gap-1.5 [@media(pointer:coarse)]:hidden">
                    <li><strong class="font-semibold">Clique</strong> seleciona um bloco ou uma seta</li>
                    <li><strong class="font-semibold">Arraste</strong> um bloco pra mover</li>
                    <li><strong class="font-semibold">Puxe</strong> a bolinha da borda até outro bloco pra ligar</li>
                    <li><strong class="font-semibold">Roda do mouse</strong> dá zoom</li>
                    <li><strong class="font-semibold">Ctrl+V</strong> cola uma imagem direto no canvas</li>
                    <li><strong class="font-semibold">Ctrl+Z</strong> desfaz e <strong class="font-semibold">Ctrl+Y</strong> refaz (⌘ no Mac)</li>
                    <li><strong class="font-semibold">Arraste</strong> o rótulo de uma seta pra deslizá-lo ao longo dela</li>
                </ul>
                <ul class="hidden flex-col gap-1.5 [@media(pointer:coarse)]:flex">
                    <li><strong class="font-semibold">Toque</strong> seleciona um bloco ou uma seta</li>
                    <li><strong class="font-semibold">Arraste</strong> um bloco pra mover</li>
                    <li><strong class="font-semibold">Toque o bloco</strong> e puxe a bolinha da borda até outro bloco pra ligar</li>
                    <li>Use os botões <strong class="font-semibold">+ / −</strong> pra dar zoom</li>
                    <li>Arraste o fundo pra mover o canvas</li>
                </ul>
            </div>
        </div>

        {{-- Authoring cluster: add block, lane, note, save. Viewport
             controls (zoom / center / fullscreen) live ONLY in the floating
             bottom bar now — they used to be duplicated here.

             The diagram's own name/status used to be edited here too,
             behind a pencil opening a panel over the canvas. They moved to the
             page's top bar (`Diagrams\Meta`, click-to-edit), where
             they're visible from the Documentação tab as well — a status
             nobody can see while writing the doc is a status nobody
             maintains. `ml-auto` is what holds these actions at the right
             edge now that the (flex-1) title is gone from this bar. --}}
        <div class="ml-auto flex shrink-0 items-center gap-1">
            {{-- Add block: always at the END of the chain (root → ... → new)
                 — opens the `data-viz-add-editor` panel (top-left of the
                 canvas; a drop-created block opens it at the drop point
                 instead). Only visible when the diagram is editable
                 (same gate as the Save button). --}}
            <x-forms.button type="button" variant="ghost" data-viz-add-node title="Adicionar bloco"
                class="!hidden !rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                <x-heroicon-o-plus class="size-4" />
            </x-forms.button>
            {{-- Adds a new lane (free background rectangle, `viz_layout.lanes`)
                 straight away — no panel/dialog in between
                 (`chain-viz.js::addLane()`), centered on the current
                 viewport and immediately selected so its toolbar opens ready
                 to rename. Only visible when editable; lanes themselves
                 (once saved) still render for a viewer, same as any other
                 viz_layout content. --}}
            <x-forms.button type="button" variant="ghost" data-viz-lanes title="Adicionar raia"
                class="!hidden !rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                <x-heroicon-o-rectangle-group class="size-4" />
            </x-forms.button>
            {{-- Adds a new "post-it" annotation (free multiline text,
                 `viz_layout.notes`) straight away — no panel/dialog, same
                 spirit as the lane button just above
                 (`chain-viz.js::addNote()`), centered on the current
                 viewport and immediately focused for typing. Only visible
                 when editable; notes themselves (once saved) still render
                 for a viewer, same as any other viz_layout content. --}}
            <x-forms.button type="button" variant="ghost" data-viz-add-note title="Adicionar anotação"
                class="!hidden !rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                <x-heroicon-o-document-text class="size-4" />
            </x-forms.button>
            {{-- Save layout — the JS reveals it (removes `hidden`) only when
                 the diagram is editable, and enables it when there's an
                 unsaved change. --}}
            <span data-viz-save-sep class="mx-0.5 hidden h-5 w-px bg-line"></span>
            <x-forms.button type="button" data-viz-save title="Salvar posição dos blocos, das setas e dos comentários"
                class="!hidden !rounded-md !px-3 !py-1 !text-xs disabled:!opacity-45 disabled:!cursor-not-allowed">
                <span data-viz-save-label>Salvar</span>
            </x-forms.button>
        </div>
    </div>

    {{-- `data-viz-stage`: containing block for the block/lane/protocol/add/
         meta panels below. They're `position:absolute` with a fixed
         `left-3 top-3` (excalidraw.com-style left rail, never JS-computed
         from a click point), and need to resolve against THIS element (the
         nearest positioned ancestor), not against `[data-ak-chain-viz]` —
         which is also `relative`, but includes the topbar above, which would
         push `top-3` down past the topbar's own height instead of pinning it
         to the canvas's own top edge. --}}
    <div data-viz-stage class="relative min-h-0 flex-1">
        <div data-viz-viewport class="ak-viz-viewport">
            <div data-viz-world class="ak-viz-world">
                {{-- The links' pointer targets (`chain-viz.js::drawEdgeHit()`), in a
                     layer of their own rather than inside the edges SVG below.
                     The lanes are PREPENDED before this layer, so a link is
                     above a lane's body and below every block: an arrow
                     crossing a lane is clicked, hovered and selected like any
                     other, instead of the click falling through to the lane and
                     dragging it away (this layer used to sit at `z-index: -1`,
                     behind the lanes, and every arrow inside a lane was out of
                     reach — "a seta está sempre atrás da raia").

                     The 16px targets still cross the thin strips a lane is
                     resized by, and its title strip, wherever a link runs over a
                     lane's border — which is everywhere. Those keep winning: a
                     target's `pointerdown` looks underneath itself and hands the
                     gesture to the lane handle or label it covers
                     (`laneChromeUnder()`). --}}
                <svg data-viz-hits class="ak-viz-hits" xmlns="http://www.w3.org/2000/svg"></svg>
                <svg data-viz-edges class="ak-viz-edges" xmlns="http://www.w3.org/2000/svg">
                    {{-- Duplicates (deliberately) the edge/marker/pill rules from
                         the component's OUTER <style> block below, scoped the
                         same way (`.ak-viz-edges ...`) — needed ONLY for
                         export (`chain-viz.js::captureDiagramCanvas()`).
                         `html-to-image` raw-clones any nested <svg> wholesale
                         (`isSVGElement()` in its clone-node.js short-circuits
                         the per-element computed-style copying it does for
                         everything else), so once this subtree is detached and
                         reparented into an export-only foreignObject, the
                         OUTER stylesheet is simply gone — every edge rendered
                         as a solid black bar (UA default `fill:black` on an
                         unstyled `<path>`) before this existed. An internal
                         `<style>` travels with the raw clone since it's a real
                         child node, not an external rule. Colors are hardcoded
                         (not `var(--viz-line)`) on purpose: that custom
                         property lives on `[data-ak-chain-viz]`, an
                         ancestor excluded from the captured subtree, and
                         `element.style['--x'] = ...` (the only override
                         `html-to-image`'s `style` option can apply) silently
                         doesn't set custom properties in any browser — only
                         `style.setProperty()` does, which the option doesn't
                         use. `--viz-line` has exactly one static value in this
                         component (`#5A6675`, unthemed) so hardcoding it here
                         is safe; if that ever stops being true, this needs a
                         real fix, not another hardcode. Keep this in sync by
                         hand if the outer rules change — there is no build
                         step sharing the two.

                         NEVER write a `<` inside this stylesheet, not even in
                         a CSS comment. An inline SVG's `<style>` is NOT raw
                         text the way an HTML one is: the parser reads its
                         content as MARKUP, so a `<` opens a tag. A comment
                         here that mentioned `<style>` and `<svg>` by name cost
                         us the arrowheads — the parser opened two elements
                         mid-CSS, the real `</style>` closed the one they had
                         nested, and everything after it (rules 9+ AND the
                         whole `<defs>`, markers included) ended up INSIDE the
                         style element, which never renders. The lines still
                         drew, because the outer stylesheet duplicates their
                         rules; the arrowheads had no second home and simply
                         vanished, silently, with valid-looking markup in view
                         source. `ChainVizMarkupTest` guards this now. A Blade
                         comment like this one is safe: it is gone before the
                         browser sees it. --}}
                    <style>
                        .ak-viz-edges path.ak-viz-edge { fill: none; stroke: #5A6675; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
                        .ak-viz-edges path.ak-viz-edge.is-dashed { stroke-dasharray: 7 5; }
                        .ak-viz-edges marker path { fill: #5A6675; }
                        .ak-viz-edges .ak-viz-plabel-box { fill: #fff; stroke: #D5DBE3; stroke-width: 1; }
                        .ak-viz-edges .ak-viz-plabel-text {
                            fill: #3D4855;
                            font-family: 'IBM Plex Sans', system-ui, sans-serif;
                            font-size: 11px;
                            font-weight: 600;
                            text-anchor: middle;
                            dominant-baseline: middle;
                        }
                        .ak-viz-edges .ak-viz-plabel.is-empty .ak-viz-plabel-box { fill: transparent; stroke-dasharray: 3 2; }
                        .ak-viz-edges .ak-viz-plabel.is-empty .ak-viz-plabel-text { fill: #5A6675; }
                        .ak-viz-dot { filter: drop-shadow(0 0 4px currentColor) drop-shadow(0 0 8px currentColor); }

                        /* The selection highlight (`highlightLinkedEdges()`):
                           the chosen block's links light up and the rest fade.
                           It lives HERE, in the internal sheet, because this is
                           where the edge rules live — and because that way the
                           highlight comes out the same in the exported PNG,
                           which raw-clones this subtree. */
                        .ak-viz-edges.has-selection path.ak-viz-edge { opacity: .22; }
                        .ak-viz-edges.has-selection path.ak-viz-edge.is-linked {
                            opacity: 1;
                            stroke-width: 2.6;
                        }
                        .ak-viz-edges.has-selection .ak-viz-plabel { opacity: .3; }
                        .ak-viz-edges.has-selection .ak-viz-plabel.is-linked { opacity: 1; }

                        /* The dashed "+ protocolo" invitation leaves the
                           drawing: the outer sheet brings it back when a link
                           is hovered. This copy exists for the EXPORT, which
                           raw-clones this subtree and carries only this sheet —
                           a PNG should never show an affordance, and it used to
                           show one per link with no protocol. No exception here
                           on purpose: not even the link that happened to be
                           under the pointer at capture time leaks into the
                           image. */
                        .ak-viz-edges.has-selection .ak-viz-plabel.is-empty,
                        .ak-viz-edges .ak-viz-plabel.is-empty { opacity: 0; }

                        {{-- The pointer targets used to need a rule here too: they
                             lived in this SVG, the clone arrives with no outer
                             sheet, and without one they fell back to the UA's
                             `fill: black` and painted a blob along every route.
                             They are in their own layer now and carry
                             `fill="none" stroke="none"` as presentation
                             ATTRIBUTES, which no clone can leave behind — so the
                             trap is closed at the element instead of by a rule
                             that has to be remembered in two stylesheets. --}}

                        {{-- Screenshot style presets ("Estilo do screenshot",
                             bottom bar export menu) — the `data-viz-preset`
                             attribute is set on THIS <svg> element itself right
                             before a capture (`chain-viz.js`'s
                             `EXPORT_PRESETS`/`captureDiagramCanvas()`) and
                             removed right after, so these rules only ever
                             apply during that one capture, never live. Colors
                             here MUST stay in sync with `EXPORT_PRESETS` in
                             the JS (canvas background lives there, passed
                             straight to `toCanvas()` — nothing to duplicate
                             here for that part). Deliberately colors only —
                             see the export-menu comment for why. --}}
                        svg[data-viz-preset="casual"] path.ak-viz-edge { stroke: #F59E0B; }
                        svg[data-viz-preset="casual"] marker path { fill: #F59E0B; }
                        svg[data-viz-preset="casual"] .ak-viz-plabel-box { stroke: #F59E0B; }
                        svg[data-viz-preset="casual"] .ak-viz-plabel.is-empty .ak-viz-plabel-text { fill: #F59E0B; }

                        {{-- "corporativo" ("mais polido", 2026-08-04): a small
                             drop-shadow on the stroke itself — short blur,
                             low opacity, dark-tinted — reads as a raised/
                             lifted line rather than a glow (glow = big blur +
                             saturated color; this is a tight contact shadow,
                             the same visual language as the node shadow
                             below). Kept subtle on purpose. --}}
                        svg[data-viz-preset="corporativo"] path.ak-viz-edge {
                            stroke: #1B4D2E;
                            filter: drop-shadow(0 1px 1.5px rgba(27, 77, 46, .3));
                        }
                        svg[data-viz-preset="corporativo"] marker path { fill: #1B4D2E; }
                        svg[data-viz-preset="corporativo"] .ak-viz-plabel-box {
                            stroke: #1B4D2E;
                            filter: drop-shadow(0 1px 2px rgba(27, 77, 46, .18));
                        }
                        svg[data-viz-preset="corporativo"] .ak-viz-plabel.is-empty .ak-viz-plabel-text { fill: #1B4D2E; }

                        {{-- "tech" (2026-08-04, re-matched to a real dark-UI
                             reference instead of a neon/cyberpunk look): flat
                             and crisp — NO `filter: drop-shadow` glow on
                             anything here. A moderate sky-blue instead of a
                             saturated cyan keeps it reading as "dark UI tool",
                             not "glowing sign". --}}
                        svg[data-viz-preset="tech"] path.ak-viz-edge { stroke: #38BDF8; }
                        svg[data-viz-preset="tech"] marker path { fill: #38BDF8; }
                        svg[data-viz-preset="tech"] .ak-viz-plabel-box { fill: #101E2E; stroke: #38BDF8; }
                        svg[data-viz-preset="tech"] .ak-viz-plabel-text { fill: #BFE3F5; }
                        svg[data-viz-preset="tech"] .ak-viz-plabel.is-empty .ak-viz-plabel-text { fill: #38BDF8; }

                        {{-- "arquitetura" / "blueprint" — the token themes.
                             Their edge and marker colors ARE `--viz-line`, and
                             the outer stylesheet already paints those from the
                             token, so these two rules look redundant while you
                             are editing. They are not: this internal sheet is
                             the only one that survives into the export clone,
                             where the base rule's hardcoded `#5A6675` would
                             otherwise draw a light-canvas arrow across a
                             near-black screenshot. Keep each value equal to
                             the matching `--viz-*` token on the viewport. --}}
                        svg[data-viz-preset="arquitetura"] path.ak-viz-edge { stroke: #64748B; }
                        svg[data-viz-preset="arquitetura"] marker path { fill: #64748B; }
                        svg[data-viz-preset="arquitetura"] .ak-viz-plabel-box { fill: #0F172A; stroke: #64748B; }
                        svg[data-viz-preset="arquitetura"] .ak-viz-plabel-text { fill: #94A3B8; }
                        svg[data-viz-preset="arquitetura"] .ak-viz-plabel.is-empty .ak-viz-plabel-text { fill: #64748B; }

                        svg[data-viz-preset="blueprint"] path.ak-viz-edge { stroke: #6D93A5; }
                        svg[data-viz-preset="blueprint"] marker path { fill: #6D93A5; }
                        svg[data-viz-preset="blueprint"] .ak-viz-plabel-box { fill: #F9FDFF; stroke: #6D93A5; }
                        svg[data-viz-preset="blueprint"] .ak-viz-plabel-text { fill: #4E7486; }
                        svg[data-viz-preset="blueprint"] .ak-viz-plabel.is-empty .ak-viz-plabel-text { fill: #6D93A5; }
                    </style>
                    <defs>
                        <marker data-viz-marker-end viewBox="0 0 10 10" refX="9" refY="5"
                            markerWidth="7" markerHeight="7" orient="auto-start-reverse" markerUnits="userSpaceOnUse">
                            <path d="M0 0 L10 5 L0 10 z" />
                        </marker>
                        <marker data-viz-marker-start viewBox="0 0 10 10" refX="9" refY="5"
                            markerWidth="7" markerHeight="7" orient="auto-start-reverse" markerUnits="userSpaceOnUse">
                            <path d="M0 0 L10 5 L0 10 z" />
                        </marker>
                    </defs>
                </svg>
                {{-- nodes injected by chain-viz.js --}}
            </div>
        </div>

        {{-- Empty state / no chain — overlaid, hidden when there's a drawing.
             The ghost graph previews what a drawn chain looks like (nodes +
             links in the canvas palette) so the empty canvas reads as
             intentional, not unfinished. Static Blade markup (unlike the real
             `.ak-viz-node` canvas nodes, which are JS-built and exempt from
             the Tailwind-utilities rule per AGENTS.md) — so it's plain
             utility classes, reusing the same `--viz-*` scoped tokens rather
             than new hardcoded colors. The title/hint text below are set by
             chain-viz.js (`showEmpty`) — keep these hooks. --}}
        <div data-viz-empty
            class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-2 px-6 text-center">
            @php
                $ghostNode = 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl bg-[var(--viz-node)] px-[11px] py-[7px] font-[Space_Grotesk,Inter,system-ui,sans-serif] text-[11px] font-semibold text-[color:var(--viz-ink)] shadow-[0_1px_2px_rgba(16,24,40,.08)]';
                $ghostAvatar = 'size-[15px] shrink-0 rounded-full bg-white shadow-[0_0_0_1px_rgba(16,24,40,.08)]';
                $ghostLink = 'w-[26px] shrink-0 border-t-[1.6px] border-dashed border-[color:var(--viz-line)]';
            @endphp
            <div aria-hidden="true" class="mb-2.5 flex max-w-full items-center justify-center opacity-90 max-[400px]:hidden">
                <span class="{{ $ghostNode }}"><span class="{{ $ghostAvatar }}"></span>Solução</span>
                <span class="{{ $ghostLink }}"></span>
                <span class="{{ $ghostNode }}"><span class="{{ $ghostAvatar }}"></span>Middleware</span>
                <span class="{{ $ghostLink }}"></span>
                <span class="inline-flex items-center whitespace-nowrap rounded-xl border border-dashed border-[color:var(--viz-line)] bg-[var(--viz-node)] px-[11px] py-[7px] font-[Space_Grotesk,Inter,system-ui,sans-serif] text-[11px] font-semibold text-[color:var(--viz-ink)]/70">Externo</span>
            </div>
            {{-- Server-rendered default, replaced by `chain-viz.js::showEmpty()`
                 the moment the graph arrives (which is immediately: the page
                 renders one hidden row and `chain-select.js` auto-selects it).
                 It said "escolha uma na lista" until 2026-08-26 — the list was
                 the solution's rail of integrations, and it no longer exists. --}}
            <p data-viz-empty-title class="mt-1 text-sm font-medium text-muted">Nada desenhado ainda</p>
            <p data-viz-empty-hint class="text-xs text-faint">Use o "+" na barra de cima para criar o primeiro bloco.</p>
        </div>

        {{-- Controls: zoom / center / fullscreen --}}
        <div data-viz-bottombar
            class="ak-viz-bottombar absolute bottom-3 left-1/2 z-10 flex -translate-x-1/2 items-center gap-1 rounded-lg border border-line bg-surface/95 p-1 shadow-[0_2px_8px_rgba(20,58,34,0.08)] backdrop-blur">
            <x-forms.button type="button" variant="ghost" data-viz-zoom-out title="Diminuir zoom"
                class="!rounded-md !px-2.5 !py-1 !text-base !font-medium !text-ink hover:!bg-accent-soft">−</x-forms.button>
            <span data-viz-zoom-label class="w-12 select-none text-center font-mono text-[11px] text-faint">100%</span>
            <x-forms.button type="button" variant="ghost" data-viz-zoom-in title="Aumentar zoom"
                class="!rounded-md !px-2.5 !py-1 !text-base !font-medium !text-ink hover:!bg-accent-soft">+</x-forms.button>
            <span class="mx-0.5 h-5 w-px bg-line"></span>
            <x-forms.button type="button" variant="ghost" data-viz-fit title="Centralizar"
                class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                <x-heroicon-o-arrows-pointing-in class="size-4" />
            </x-forms.button>
            <x-forms.button type="button" variant="ghost" data-viz-fullscreen title="Tela cheia"
                class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                <x-heroicon-o-arrows-pointing-out data-viz-fs-open class="size-4" />
                <x-heroicon-o-arrows-pointing-in data-viz-fs-close class="hidden size-4" />
            </x-forms.button>
            <span class="mx-0.5 h-5 w-px bg-line"></span>
            {{-- Presentation mode: turns the animated dots on and off
                 (`chain-viz.js::enterPresentation()`/`exitPresentation()`).
                 Always visible, including to somebody who may not edit — it is
                 a viewing feature, not an authoring one. The icon alternates
                 between "present" and "stop" on the same pattern as
                 `data-viz-fs-open`/`data-viz-fs-close` just above. --}}
            <x-forms.button type="button" variant="ghost" data-viz-present-toggle title="Modo apresentação"
                class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                <x-heroicon-o-presentation-chart-line data-viz-present-icon-start class="size-4" />
                <x-heroicon-o-stop data-viz-present-icon-stop class="hidden size-4" />
            </x-forms.button>
            {{-- Velocidade da animação — só aparece enquanto se apresenta
                 (`refreshEditableUI()` alterna `hidden`/`flex`). Muda
                 `presentSpeedMultiplier` ao vivo, sem resetar o progresso das
                 bolinhas; sempre volta a 1x da próxima vez que a apresentação
                 é iniciada (estado só de sessão, nunca persiste). --}}
            <span data-viz-present-speed-wrap class="hidden items-center gap-1">
                <span class="mx-0.5 h-5 w-px bg-line"></span>
                <div class="w-[62px] shrink-0">
                    <x-forms.select data-viz-present-speed title="Velocidade da animação"
                        class="!h-[26px] !w-full !rounded-md !border-line !bg-surface !py-0 !pl-1.5 !pr-5 !text-xs">
                        <option value="0.5">0.5x</option>
                        <option value="0.75">0.75x</option>
                        <option value="1" selected>1x</option>
                        <option value="1.25">1.25x</option>
                        <option value="1.5">1.5x</option>
                    </x-forms.select>
                </div>
            </span>
            <span class="mx-0.5 h-5 w-px bg-line"></span>
            {{-- The canvas theme (Original/Casual/Corporativo/Tech) — live,
                 not only in the export: `chain-viz.js::applyTheme()` sets
                 `data-viz-preset` on `world`/`edges` and the SAME CSS rules the
                 export uses (block/edge/pill, in this component's <style>)
                 already paint ordinary editing. Persisted in
                 `viz_layout.theme` — it makes "Salvar" dirty the way moving a
                 block would, it does not save by itself. Visible to anyone (not
                 only to whoever edits) — it is a viewing preference, in the
                 same spirit as presentation mode. No `hidden` class here —
                 like `data-viz-export-toggle`/`data-viz-present-toggle`, it is
                 visible by default; `showEmpty()`/`render()` toggle `!hidden`
                 (never bare `hidden`) when there is no chain. --}}
            <span data-viz-theme-wrap class="flex items-center gap-1">
                <div class="w-[104px] shrink-0">
                    <x-forms.select data-viz-theme title="Tema do diagrama"
                        class="!h-[26px] !w-full !rounded-md !border-line !bg-surface !py-0 !pl-1.5 !pr-5 !text-xs">
                        <option value="original" selected>Original</option>
                        <option value="casual">Casual</option>
                        <option value="corporativo">Corporativo</option>
                        <option value="tech">Tech</option>
                        <option value="arquitetura">Arquitetura</option>
                        <option value="blueprint">Blueprint</option>
                    </x-forms.select>
                </div>
                <span class="mx-0.5 h-5 w-px bg-line"></span>
            </span>
            {{-- Export: an image (PNG) or a video (animated GIF) of the
                 diagram — 100% client-side (`html-to-image` + `gif.js`, see
                 `chain-viz.js::captureDiagramCanvas()`/`exportImage()`/
                 `exportVideo()`), with no new endpoint and no queued job: the
                 export captures the DOM already rendered in the user's own
                 browser, cropped exactly around the content (nodes ∪ lanes) —
                 never to the shape of the open viewport, which is why `fit()`
                 (designed for editing) leaves white margins at the sides when
                 the content's aspect ratio does not match the window's. The
                 video enters presentation mode on its own (if it is not in it
                 already) and returns to the previous state when it finishes.
                 The popover follows the same pattern as the topbar's "?"
                 shortcut list, except that it opens UPWARD (`bottom-full`),
                 being near the canvas's bottom edge. --}}
            <div class="relative shrink-0">
                <x-forms.button type="button" variant="ghost" data-viz-export-toggle
                    data-ak-toggle="viz-export-menu" data-ak-toggle-classes="hidden" data-ak-toggle-blur="true"
                    title="Exportar diagrama" aria-label="Exportar diagrama"
                    class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                    <x-heroicon-o-arrow-down-tray class="size-4" />
                </x-forms.button>
                {{-- `hidden` (toggled) lives on THIS outer element; the flex
                     layout lives on the INNER wrapper below, unconditionally
                     — same split as `#viz-hint-popover`'s outer div vs. its
                     inner `<ul class="flex flex-col ...">`. Toggling `hidden`
                     directly on an element that also carries `flex` would
                     race the two display utilities against each other. --}}
                <div id="viz-export-menu"
                    class="hidden absolute bottom-full right-0 z-30 mb-1.5 w-64 rounded-field border border-line bg-surface p-1 shadow-xl">
                    <div class="flex flex-col gap-0.5">
                        <x-forms.button type="button" variant="ghost" data-viz-export-png
                            class="!w-full !justify-start !gap-2 !rounded-md !px-2.5 !py-1.5 !text-xs !font-medium">
                            <x-heroicon-o-photo class="size-4 text-muted" /> Imagem (PNG)
                        </x-forms.button>
                        <x-forms.button type="button" variant="ghost" data-viz-export-gif
                            class="!w-full !justify-start !gap-2 !rounded-md !px-2.5 !py-1.5 !text-xs !font-medium">
                            <x-heroicon-o-film class="size-4 text-muted" /> Vídeo (GIF animado)
                        </x-forms.button>
                        <p data-viz-export-status class="hidden px-2.5 pb-1 pt-1.5 text-[11px] text-muted"></p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Contextual toolbar: a FIXED panel in the canvas's top-left corner
             (excalidraw.com-style left rail — vertical stack of labeled
             sections, never anchored to the selected node's own screen
             position, so pan/zoom/drag never move it), shown the instant a
             node is selected and hidden the instant it's deselected
             (`selectNode(null)` — canvas background click, Esc, or selecting
             something else). Block style (color, text color, font) is only
             editable — it never touches the topology, only the visual
             `viz_layout`, same spirit as the position/anchors already
             persisted there. Fixed `w-56` + `max-h`/`overflow-y-auto` (rather
             than the old shrink-to-fit `flex-wrap` box) so every section
             always stacks the same way regardless of how many are visible at
             once. --}}
        <div data-viz-toolbar
            class="ak-viz-toolbar pointer-events-none absolute left-3 top-3 z-20 hidden max-h-[calc(100%-24px)] w-56 flex-col gap-3 overflow-y-auto rounded-xl border border-line bg-surface p-2.5 shadow-[0_8px_28px_rgba(16,24,40,.16)]">
            <div data-viz-toolbar-style class="pointer-events-auto flex flex-col gap-3">
                {{-- The block's tone — five swatches built in JS
                     (chain-viz.js::buildSwatches(), from `TONES`), each one a
                     fill + border + text color chosen together. There is no
                     free color and no separate text color any more: the
                     reference profile has exactly these five, and a free pick
                     let every author invent what a color meant. Action blocks
                     only (system/step) — hidden for the rest by `selectNode()`:
                     a decision is always the orange diamond. --}}
                <div data-viz-toolbar-tone>
                    <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">Cor</p>
                    <div data-viz-swatches class="flex flex-wrap items-center gap-1.5"></div>
                </div>

                <div>
                    <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">Fonte</p>
                    {{-- Text font — mono / sans / serif. Full-width now that it
                         owns its own section (no longer squeezed next to the
                         font-size select on the same row). --}}
                    <x-forms.select data-viz-font title="Fonte do texto"
                        class="!h-8 !w-full !rounded-md !border-line !bg-surface !py-0 !text-xs">
                        <option value="sans">Sans</option>
                        <option value="serif">Serif</option>
                        <option value="mono">Mono</option>
                    </x-forms.select>
                </div>

                <div>
                    <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">Tamanho da fonte</p>
                    {{-- Peq (15px, the default) / Médio / Grande (`FONT_SIZES` in chain-viz.js). --}}
                    <x-forms.select data-viz-font-size title="Tamanho da fonte"
                        class="!h-8 !w-full !rounded-md !border-line !bg-surface !py-0 !text-xs">
                        <option value="sm">Peq</option>
                        <option value="md">Médio</option>
                        <option value="lg">Grande</option>
                    </x-forms.select>
                </div>
            </div>

            <div data-viz-toolbar-row2 class="pointer-events-auto flex flex-col gap-3">
                <div>
                    <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">Estilo</p>
                    <div class="flex flex-wrap items-center gap-1.5">
                        {{-- Dashed border toggle — purely visual (`viz_layout.nodes[i].dashed`),
                             independent of the block's color/shape. The button's OWN
                             border switches solid/dashed to show the current state
                             (`chain-viz.js::refreshToolbarControls()`), instead
                             of a separate icon. --}}
                        <x-forms.button type="button" variant="ghost" data-viz-toolbar-dashed title="Borda tracejada do bloco"
                            class="!size-[26px] !shrink-0 !rounded-md !border !border-line !p-0 !text-ink hover:!bg-accent-soft">
                            <span class="pointer-events-none block h-0 w-4 border-t-2 border-dashed border-current"></span>
                        </x-forms.button>

                        {{-- Light image border — image blocks only (hidden for every
                             other kind, toggled in `chain-viz.js::selectNode()`).
                             Toggle turns a solid border on/off (defaults to white the
                             first time); the color input customizes it — changing it
                             always implies "on" too. Purely visual
                             (`viz_layout.nodes[i].imageBorderColor`), independent of
                             the block's background color. --}}
                        <div data-viz-toolbar-image-border class="hidden items-center gap-1.5">
                            <x-forms.button type="button" variant="ghost" data-viz-toolbar-image-border-toggle title="Borda leve da imagem"
                                class="!size-[26px] !shrink-0 !rounded-md !border !border-line !p-0 !text-ink hover:!bg-accent-soft">
                                <span class="pointer-events-none block size-3.5 rounded-[3px] border-2 border-current"></span>
                            </x-forms.button>
                            <x-forms.input type="color" data-viz-image-border-color title="Cor da borda"
                                class="!size-[22px] !shrink-0 !cursor-pointer !rounded-md !border !border-line !bg-transparent !p-0 [&::-webkit-color-swatch]:!rounded-md [&::-webkit-color-swatch]:!border-none [&::-webkit-color-swatch-wrapper]:!p-0" />
                        </div>

                        {{-- The dark pill — a SHAPE any action block may take
                             (`viz_layout.nodes[i].pill`), not a kind: the block
                             stays a system or a step. Dark whatever the tone, so
                             picking a tone takes the block out of it again. --}}
                        <x-forms.button type="button" variant="ghost" data-viz-toolbar-pill title="Pílula escura"
                            class="!size-[26px] !shrink-0 !rounded-md !border !border-line !p-0 !text-ink hover:!bg-accent-soft">
                            <span class="pointer-events-none block h-2.5 w-4 rounded-full bg-current"></span>
                        </x-forms.button>
                    </div>
                </div>

                {{-- The block's picture — action blocks only (hidden for the
                     rest by `selectNode()`). "Imagem" uploads one into the
                     block (Ctrl+V with the block selected does the same);
                     "Remover" only appears for a picture of the block's own,
                     since a catalog logo belongs to the Solution. The two
                     placements are `viz_layout.nodes[i].imageMode`: beside the
                     text, or above it with the box gone. --}}
                <div data-viz-toolbar-picture class="hidden">
                    <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">Imagem</p>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <x-forms.button type="button" variant="ghost" data-viz-toolbar-picture-upload title="Colocar uma imagem no bloco"
                            class="!size-[26px] !shrink-0 !rounded-md !border !border-line !p-0 !text-ink hover:!bg-accent-soft">
                            <x-heroicon-o-photo class="pointer-events-none size-3.5" />
                        </x-forms.button>
                        <x-forms.input type="file" accept="image/png,image/jpeg,image/gif,image/webp"
                            data-viz-toolbar-picture-input class="!hidden" />
                        <x-forms.button type="button" variant="ghost" data-viz-toolbar-picture-left title="Imagem ao lado do texto"
                            class="!size-[26px] !shrink-0 !rounded-md !border !border-line !p-0 !text-ink hover:!bg-accent-soft">
                            <span class="pointer-events-none flex items-center gap-0.5">
                                <span class="block size-2 rounded-[2px] bg-current"></span>
                                <span class="flex flex-col gap-0.5"><span class="block h-px w-2 bg-current"></span><span class="block h-px w-2 bg-current"></span></span>
                            </span>
                        </x-forms.button>
                        <x-forms.button type="button" variant="ghost" data-viz-toolbar-picture-top title="Só a imagem, com o texto embaixo"
                            class="!size-[26px] !shrink-0 !rounded-md !border !border-line !p-0 !text-ink hover:!bg-accent-soft">
                            <span class="pointer-events-none flex flex-col items-center gap-0.5">
                                <span class="block size-2 rounded-[2px] bg-current"></span>
                                <span class="block h-px w-3 bg-current"></span>
                            </span>
                        </x-forms.button>
                        <x-forms.button type="button" variant="ghost" data-viz-toolbar-picture-remove title="Remover a imagem do bloco"
                            class="!size-[26px] !shrink-0 !rounded-md !border !border-line !p-0 !text-ink hover:!bg-accent-soft">
                            <x-heroicon-o-x-mark class="pointer-events-none size-3.5" />
                        </x-forms.button>
                    </div>
                </div>

                {{-- The block's kind (system / decision / actor / start /
                     end) — icons only, with `title` as the tooltip
                     (`chain-viz.js::refreshKindRow()`), applying the change
                     immediately (a PATCH to `graphRef.nodeUpdateUrl`), with no
                     separate "Salvar" button. Hidden on anything but the root
                     node and on a pasted image (`ChainNodeKind::pickable()`),
                     the same rule as before — `selectNode()`'s `hidden`/`flex`
                     toggles the WHOLE SECTION (label included) so this leaves
                     no orphan heading behind when it goes. No solution/text
                     field here: both are edited on the shape itself (double
                     click on the block's text, `startInlineLabelEdit()`), with
                     solution autocomplete on a `system` block. --}}
                <div data-viz-toolbar-kind class="hidden flex-col gap-1">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-faint">Tipo</p>
                    <div data-viz-toolbar-kind-icons class="flex flex-wrap items-center gap-1"></div>
                </div>

                <div>
                    <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">Ações</p>
                    <div class="flex items-center gap-1.5">
                        {{-- Edit the block's text/solution. The same
                             `startInlineLabelEdit()` as the double click on the
                             shape — which was the ONLY path and therefore
                             unreachable on a touch device, where there is no
                             double
                             clique: renomear um bloco (e, num bloco `system`,
                             trocar a Solução ligada) era simplesmente
                             impossível de um tablet. Raia e pill de protocolo
                             já tinham caminho por painel; o bloco não tinha.
                             Mesmo portão do "Tipo"/"Excluir" (`editable`, fora
                             do nó raiz, nunca numa imagem colada), alternado
                             junto com eles por `selectNode()`. --}}
                        <x-forms.button type="button" variant="ghost" data-viz-toolbar-rename title="Renomear bloco"
                            class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                            <x-heroicon-o-pencil-square class="size-4" />
                        </x-forms.button>
                        <x-forms.button type="button" variant="ghost" data-viz-toolbar-comment title="Comentário"
                            class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                            <x-heroicon-o-chat-bubble-left-ellipsis class="size-4" />
                        </x-forms.button>
                        {{-- Delete the block. Same gate as the block type (editable +
                             not the root), since removing index 0 is rejected
                             server-side too. Destructive, so it's set apart by a
                             separator and tinted `crit` — and it takes every link
                             touching the block with it, which is what the confirm
                             spells out. --}}
                        <span class="mx-0.5 h-6 w-px shrink-0 bg-line" data-viz-toolbar-remove-sep></span>
                        <x-forms.button type="button" variant="ghost" data-viz-toolbar-remove title="Excluir bloco"
                            class="!rounded-md !p-1.5 !text-crit hover:!bg-crit-soft">
                            <x-heroicon-o-trash class="size-4" />
                        </x-forms.button>
                    </div>
                </div>
            </div>
        </div>

        {{-- "Adicionar bloco" panel: a SINGLE horizontal row of kind icons
             (sistema / decisão / ator / início / fim, from `[data-ak-node-kinds]`)
             — clicking one creates the block IMMEDIATELY
             (`chain-viz.js::createNodeFromKind()`), no separate
             Solution/text field or "Adicionar" step here anymore. The block
             is born with a placeholder text (the kind's own name — "Sistema",
             "Decisão", …), and the very next thing that happens is
             `startInlineLabelEdit()` on it, same as double-clicking a block's
             text — so the picker's job ends the instant a kind is picked, and
             naming/linking a Solution (system's autocomplete) happens
             directly on the canvas, not in this panel. No arrow, no protocol
             either way: the block is born isolated, wiring is the separate
             gesture of dragging an arrow out of any block's port. Mutually
             exclusive with the block/lane/protocol toolbars. WHERE it opens
             depends on how it was opened: the topbar's "+" has nothing to
             anchor to, so it uses the fixed top-left corner of the classes
             below; dropping a dragged arrow on empty canvas
             (`openQuickAddEditor()`) anchors it beside the drop point
             instead (`positionAddEditorAt()` writes inline left/top, clamped
             to the stage, and `closeAddEditor()` clears them again) — the
             new block is born at that same point, so panel and block appear
             together at the arrow's end rather than a screen apart. --}}
        <div data-viz-add-editor
            class="pointer-events-auto absolute left-3 top-3 z-20 hidden max-h-[calc(100%-24px)] w-56 flex-col gap-2 overflow-y-auto rounded-xl border border-line bg-surface p-2.5 shadow-[0_8px_28px_rgba(16,24,40,.16)]">
            <div data-viz-add-kind-icons class="flex flex-wrap items-center gap-1.5"></div>
            {{-- Text set from the JS, because the two ways in mean opposite
                 things: from the "+" the block really is born loose, while a
                 block created by dropping an arrow is born already wired to
                 that arrow — and this card now opens right at the arrow's tip,
                 where "drag an arrow to it" would contradict what's on screen. --}}
            <p data-viz-add-hint class="text-[11px] leading-snug text-faint"></p>
        </div>

        {{-- Lane toolbar: opened by a click (no drag) on any lane
             (`chain-viz.js::selectLane()`) — same click-vs-drag
             distinction a block already makes, and the same spirit as the
             block's own contextual toolbar (`data-viz-toolbar` above), just
             scoped to what a lane actually has: two independent preset-color
             pickers (body fill/border and header strip — swatches built from
             `LANE_COLORS`, same pattern as a block's palette; `setLaneColor()`/
             `setLaneHeaderColor()`), a style cluster (corners, border,
             orientation, header text size, opacity, title on/off — all
             purely visual, `viz_layout.lanes[i]`, applied live via
             `chain-viz.js::refreshLaneToolbarControls()`/the
             individual setters below it) and remove. There's no name field
             here anymore — double-click the header strip on the canvas
             itself to rename (`chain-viz.js::startInlineLaneLabelEdit()`),
             same spirit as a block's own inline label editing. Position/size
             aren't edited here either — dragging the lane's body (move) or
             its edge/corner handles (resize), directly on the canvas, is the
             UI for that. Same fixed top-left panel as the block toolbar
             (mutually exclusive with it) — never anchored to the lane itself,
             so resizing/moving the selected lane never has to reposition it. --}}
        <div data-viz-lane-toolbar title="Dê 2 cliques na aba do cabeçalho pra renomear."
            class="pointer-events-auto absolute left-3 top-3 z-20 hidden max-h-[calc(100%-24px)] w-64 flex-col gap-3 overflow-y-auto rounded-xl border border-line bg-surface p-2.5 shadow-[0_8px_28px_rgba(16,24,40,.16)]">
            <div>
                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">Cor do corpo</p>
                <div data-viz-lane-toolbar-swatches class="flex flex-wrap items-center gap-1"></div>
            </div>
            <div>
                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">Cor do cabeçalho</p>
                <div data-viz-lane-toolbar-header-swatches class="flex flex-wrap items-center gap-1"></div>
            </div>

            <div>
                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">Estilo</p>
                {{-- Corners (square/rounded) + border (solid/dashed) — same
                     "the button's own visual reflects the current state" pattern
                     as the block's dashed-border toggle above; both flip a
                     single boolean on click (`chain-viz.js`'s
                     `laneToolbarRoundedBtn`/`laneToolbarDashedBtn` listeners),
                     no separate dialog. --}}
                <div class="flex flex-wrap items-center gap-1.5">
                    <x-forms.button type="button" variant="ghost" data-viz-lane-toolbar-rounded title="Cantos arredondados"
                        class="!size-[26px] !shrink-0 !rounded-md !border !border-line !p-0 !text-ink hover:!bg-accent-soft">
                        <span data-viz-lane-toolbar-rounded-icon class="pointer-events-none block size-3.5 rounded-none border-2 border-current"></span>
                    </x-forms.button>
                    <x-forms.button type="button" variant="ghost" data-viz-lane-toolbar-dashed title="Borda tracejada"
                        class="!size-[26px] !shrink-0 !rounded-md !border !border-line !p-0 !text-ink hover:!bg-accent-soft">
                        <span class="pointer-events-none block h-0 w-4 border-t-2 border-dashed border-current"></span>
                    </x-forms.button>
                    <span class="mx-0.5 h-6 w-px shrink-0 bg-line"></span>
                    {{-- Orientation: horizontal (as-is — title on the left edge,
                         vertical text) or vertical (title on the top edge, plain
                         left-to-right text — CSS variant `.ak-viz-lane.is-vertical`). --}}
                    <x-forms.button type="button" variant="ghost" data-viz-lane-toolbar-orientation="horizontal" title="Raia horizontal"
                        class="!size-[26px] !shrink-0 !rounded-md !border !border-line !p-0 !text-ink hover:!bg-accent-soft">
                        <x-heroicon-o-arrows-right-left class="pointer-events-none size-3.5" />
                    </x-forms.button>
                    <x-forms.button type="button" variant="ghost" data-viz-lane-toolbar-orientation="vertical" title="Raia vertical"
                        class="!size-[26px] !shrink-0 !rounded-md !border !border-line !p-0 !text-ink hover:!bg-accent-soft">
                        <x-heroicon-o-arrows-up-down class="pointer-events-none size-3.5" />
                    </x-forms.button>
                </div>
            </div>

            {{-- Header label text size — same small/medium/large scale
                 convention as the block's own font-size select, just its own
                 (smaller) scale since the header is a narrow strip
                 (`LANE_FONT_SIZES` in chain-viz.js). --}}
            <div>
                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">Texto do cabeçalho</p>
                <x-forms.select data-viz-lane-toolbar-font-size title="Tamanho do texto do cabeçalho"
                    class="!h-8 !w-full !rounded-md !border-line !bg-surface !py-0 !text-xs">
                    <option value="sm">Peq</option>
                    <option value="md">Médio</option>
                    <option value="lg">Grande</option>
                </x-forms.select>
            </div>

            <div>
                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">Opacidade</p>
                <x-forms.input type="range" data-viz-lane-toolbar-opacity title="Opacidade do preenchimento"
                    min="0.03" max="0.5" step="0.01" class="!h-1.5 !w-full !cursor-pointer !border-0 !bg-transparent !p-0" />
            </div>

            {{-- Title on/off — with it off, the lane still renders as a
                 tinted rectangle, but the click target that opens this very
                 toolbar (and the double-click that renames it) moves from the
                 (now hidden) title strip to the whole body
                 (`chain-viz.js`'s `laneAnchorEl()`). --}}
            <x-forms.toggle name="viz-lane-toolbar-title" data-viz-lane-toolbar-title :checked="true">
                Título
            </x-forms.toggle>

            <div class="flex items-center justify-end">
                <x-forms.button type="button" variant="ghost" data-viz-lane-toolbar-remove title="Remover raia"
                    class="!rounded-md !px-2.5 !py-1 !text-xs !text-crit hover:!bg-crit-soft">
                    <x-heroicon-o-trash class="size-3.5" /> Remover
                </x-forms.button>
            </div>
        </div>

        {{-- Editor for a link — opened by clicking the protocol pill above
             an existing arrow (or the dashed "+ protocolo" pill); also opened
             AUTOMATICALLY right after a brand new link is created by dragging
             an arrow out of a port (`chain-viz.js::createEdgeFrom()`),
             so direction/dashed are one click away without a separate "select
             the new arrow" step. A SINGLE ROW of icon buttons, nothing else —
             direction (two independent toggles, not a 3-option `<select>`:
             clicking either arrow flips it on/off, both can be active at once
             for a double-headed arrow `<->`, and turning the last active one
             off is a no-op since `->`/`<-`/`<->` are the only valid arrows),
             dashed (icon toggle — the button's own border switches
             solid/dashed to show state, same pattern as the block's
             `data-viz-toolbar-dashed` — never a checkbox with a text label),
             and "Desligar" (icon only, tinted `crit`). All three apply
             IMMEDIATELY (no "Salvar"/"Cancelar" — same live-apply spirit as
             the block's own toolbar and the lane toolbar): direction toggles
             PATCH the edge as soon as they're clicked
             (`chain-viz.js::toggleArrowSide()`), and dashed is purely
             visual (`viz_layout`), applied and left for the next "Salvar
             layout" the same way the block's dashed toggle already works.
             Protocol text itself is edited DIRECTLY ON THE ARROW'S LABEL, not
             in this panel — double-click the pill on the canvas
             (`chain-viz.js::startInlineProtocolEdit()`), free text with
             an autocomplete dropdown built from `[data-ak-protocols]` (same
             source as the `App\Enums\Protocol` enum), mirroring the block's
             own inline label editing (`startInlineLabelEdit()`) — that's what
             the panel's own `title` tooltip points to, since there's no room
             for a hint line in a one-row icon menu. Same fixed top-left panel
             as the block/lane toolbars (mutually exclusive with them) — never
             anchored to the edge's own pill, so the pill moving under
             pan/zoom/drag never has to drag this panel along with it. --}}
        <div data-viz-protocol-editor title="Dê 2 cliques no rótulo da seta pra editar o protocolo."
            class="pointer-events-auto absolute left-3 top-3 z-20 hidden w-max flex-col gap-2 rounded-xl border border-line bg-surface p-1.5 shadow-[0_8px_28px_rgba(16,24,40,.16)]">
            <div class="flex items-center gap-1.5">
                <x-forms.button type="button" variant="ghost" data-viz-protocol-arrow-left title="Seta para a origem (recebe)"
                    class="!flex !size-[30px] !shrink-0 !items-center !justify-center !rounded-md !border !border-line !p-0 !text-ink hover:!bg-accent-soft">
                    <x-heroicon-o-arrow-left class="size-3.5" />
                </x-forms.button>
                <x-forms.button type="button" variant="ghost" data-viz-protocol-arrow-right title="Seta para o destino (envia)"
                    class="!flex !size-[30px] !shrink-0 !items-center !justify-center !rounded-md !border !border-line !p-0 !text-ink hover:!bg-accent-soft">
                    <x-heroicon-o-arrow-right class="size-3.5" />
                </x-forms.button>
                {{-- Dashed arrow — purely visual (`viz_layout.edges[i].dashed`),
                     independent of direction/protocol. --}}
                <x-forms.button type="button" variant="ghost" data-viz-protocol-dashed title="Seta tracejada"
                    class="!size-[30px] !shrink-0 !rounded-md !border !border-line !p-0 !text-ink hover:!bg-accent-soft">
                    <span class="pointer-events-none block h-0 w-4 border-t-2 border-dashed border-current"></span>
                </x-forms.button>
                <span class="mx-0.5 h-6 w-px shrink-0 bg-line"></span>
                <x-forms.button type="button" variant="ghost" data-viz-protocol-delete title="Desligar (remove só a ligação, não os blocos)"
                    class="!flex !size-[30px] !shrink-0 !items-center !justify-center !rounded-md !p-0 !text-crit hover:!bg-crit-soft">
                    <x-heroicon-o-trash class="size-3.5" />
                </x-forms.button>
            </div>
        </div>

        {{-- Comment sidebar (markdown), scoped to the component — never
             fixed to the page viewport. --}}
        <div data-viz-sidebar
            class="ak-viz-sidebar absolute inset-y-0 right-0 z-30 flex w-[min(390px,92%)] translate-x-full flex-col border-l border-line bg-surface shadow-[-12px_0_32px_rgba(16,24,40,.10)] transition-transform duration-300">
            <div class="flex shrink-0 items-start justify-between gap-3 border-b border-line px-4 py-3">
                <div class="min-w-0">
                    <p class="font-display text-sm font-semibold text-ink">Comentário</p>
                    <p data-viz-sidebar-node class="mt-0.5 truncate text-xs text-faint"></p>
                </div>
                <x-forms.button type="button" variant="ghost" data-viz-sidebar-close title="Fechar"
                    class="!shrink-0 !rounded-md !p-1.5 !text-muted hover:!bg-accent-soft hover:!text-ink">
                    <x-heroicon-o-x-mark class="size-4" />
                </x-forms.button>
            </div>
            <div class="flex flex-1 flex-col gap-2 overflow-y-auto px-4 py-3">
                <label class="text-[11px] font-semibold uppercase tracking-wide text-faint">Markdown</label>
                <textarea data-viz-sidebar-input rows="7"
                    class="w-full resize-y rounded-field border border-line bg-canvas px-3 py-2 font-mono text-xs text-ink outline-none focus:border-accent focus:bg-surface"
                    placeholder="Escreva em markdown…"></textarea>
                <label class="mt-1 text-[11px] font-semibold uppercase tracking-wide text-faint">Pré-visualização</label>
                <div data-viz-sidebar-preview class="ak-viz-md rounded-field border border-line bg-surface px-3.5 py-3 text-sm text-ink"></div>
            </div>
        </div>
    </div>

    {{-- The canvas's stylesheet is resources/css/components/chain-viz.css,
         imported by chain-viz.js (loaded with the canvas's chunk). --}}
</div>
