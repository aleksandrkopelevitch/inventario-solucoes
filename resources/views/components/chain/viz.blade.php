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

    {{-- Top bar: view actions (organize default layout / center / fullscreen /
         save). No topology-authoring action lives here — only the topology,
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

        {{-- Authoring cluster: add block, organize layout, save. Viewport
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
            <x-forms.button type="button" variant="ghost" data-viz-organize title="Organizar layout padrão"
                class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                <x-heroicon-o-squares-2x2 class="size-4" />
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
                     They are 16px wide and follow every route, and the edges SVG
                     is a LATER sibling of the lanes — so in a lane drawing they
                     sat on top of the 10px strip you drag a lane's edge by, and
                     killed the resize wherever a link crossed a lane boundary,
                     which is everywhere. Here they are the LAST thing to claim a
                     click: block, lane handle and lane label all win, and a link
                     answers only where there is nothing but line.

                     `z-index: -1` is safe in THIS parent and would not be on the
                     lanes: `.ak-viz-world` carries a transform, so it is a real
                     stacking context and the negative layer cannot escape behind
                     the viewport. --}}
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
                         component (`#94A3C4`, unthemed) so hardcoding it here
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
                        .ak-viz-edges path.ak-viz-edge { fill: none; stroke: #94A3C4; stroke-width: 2; }
                        .ak-viz-edges path.ak-viz-edge.is-dashed { stroke-dasharray: 7 5; }
                        .ak-viz-edges marker path { fill: #94A3C4; }
                        .ak-viz-edges .ak-viz-plabel-box { fill: #fff; stroke: #94A3C4; stroke-width: 1; }
                        .ak-viz-edges .ak-viz-plabel-text {
                            fill: #4f5b7a;
                            font-family: ui-monospace, 'SF Mono', Menlo, Consolas, monospace;
                            font-size: 10px;
                            text-anchor: middle;
                            dominant-baseline: middle;
                        }
                        .ak-viz-edges .ak-viz-plabel.is-empty .ak-viz-plabel-box { fill: transparent; stroke-dasharray: 3 2; }
                        .ak-viz-edges .ak-viz-plabel.is-empty .ak-viz-plabel-text { fill: #94A3C4; }
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
                             where the base rule's hardcoded `#94A3C4` would
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
                <div>
                    <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">Cor</p>
                    <div class="flex flex-wrap items-center gap-1.5">
                        {{-- Block color palette — presets generated in JS (chain-viz.js::buildSwatches) --}}
                        <div data-viz-swatches class="flex flex-wrap items-center gap-1"></div>

                        {{-- Custom block color --}}
                        <x-forms.input type="color" data-viz-custom-color title="Cor personalizada do bloco"
                            class="!size-[22px] !shrink-0 !cursor-pointer !rounded-md !border !border-line !bg-transparent !p-0 [&::-webkit-color-swatch]:!rounded-md [&::-webkit-color-swatch]:!border-none [&::-webkit-color-swatch-wrapper]:!p-0" />
                    </div>
                </div>

                <div>
                    <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">Cor do texto</p>
                    {{-- Text color — square with an underlined "A" in the current color, same as the reference mind-map --}}
                    <div class="relative flex size-[26px] shrink-0">
                        <x-forms.label for="viz-text-color-input" data-viz-text-color-wrap title="Cor do texto"
                            class="!m-0 !flex !size-full !font-extrabold !text-ink size-full cursor-pointer items-center justify-center rounded-md border border-line text-sm">
                            <span class="pointer-events-none border-b-[3px] border-current pb-px">A</span>
                        </x-forms.label>
                        <x-forms.input type="color" id="viz-text-color-input" data-viz-text-color
                            class="!absolute !inset-0 !size-full !cursor-pointer !border-0 !bg-transparent !p-0 !opacity-0" />
                    </div>
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
                    {{-- Peq (13px, today's default) / Médio / Grande (`FONT_SIZES` in chain-viz.js). --}}
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

                        {{-- "Somente logo" — system blocks with a registered Solution
                             (and a logo) only, hidden for every other kind/state
                             (toggled in `chain-viz.js::selectNode()`). Swaps
                             the whole card (avatar + name) for just the Solution's
                             logo, no border/background (`viz_layout.nodes[i].logoOnly`). --}}
                        <div data-viz-toolbar-logo-only class="hidden items-center gap-1.5">
                            <x-forms.button type="button" variant="ghost" data-viz-toolbar-logo-only-toggle title="Somente logo"
                                class="!size-[26px] !shrink-0 !rounded-md !border !border-line !p-0 !text-ink hover:!bg-accent-soft">
                                <x-heroicon-o-photo class="pointer-events-none size-3.5" />
                            </x-forms.button>
                        </div>
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

    {{-- Inline (not @push): the layout has no @stack and the Diagrama tab
         mounts this component only once per page, so there's no risk of duplication. --}}
    <style>
            @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap');

            /* Diagram canvas (F3) — JS-driven, its own navy/blue palette
               (mirrors the reference mind-map), independent of the app's
               green/lime theme. */
            [data-ak-chain-viz] {
                --viz-bg: #F7F9FC;
                --viz-grid: #E7ECF4;
                --viz-line: #94A3C4;
                /* Every block is WHITE (2026-08-26). It used to be one pastel
                   per kind — lavender for a system, mint for an actor, straw
                   for a decision — which meant a diagram of a dozen blocks was
                   a dozen tints of nothing in particular, and the per-block
                   color someone actually chose (`viz_layout.nodes[i].color`)
                   had to fight a default that was already colored. White is
                   the ground; SHAPE says what a block is (chamfered hexagon =
                   decision, circle = actor, dashed border = external), and
                   color is left to mean whatever the author decides it means.
                   The two flow terminals keep their fill: a start/end marker
                   is a punctuation mark, not a box, and green/red is the
                   flowchart convention readers already know. */
                --viz-node: #FFFFFF;
                --viz-node-start: #22C55E;    /* início block (solid circle) — bold on purpose, flow terminal */
                --viz-node-end: #EF4444;      /* fim block (solid circle) — bold on purpose, flow terminal */
                --viz-ink: #1A1A2E;
                --viz-select: #4A90D9; /* selection ring + comment badge */
                --viz-highlight: #AADB1E; /* highlighted anchor/handle */
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
            /* Theme canvas background (`data-viz-theme` select, `applyTheme()`)
               — colors MUST match `EXPORT_PRESETS[theme].bg` in the JS (that's
               what the export actually uses; `viewport` itself is never part
               of the captured subtree, so this rule only matters for what the
               user sees while editing). "corporativo" drops the dot texture
               entirely — a plain white canvas reads cleaner/more "serious"
               than repeating the same dotted grid tinted a different color. */
            .ak-viz-viewport[data-viz-preset="casual"] {
                background:
                    radial-gradient(circle at 1px 1px, rgba(245, 158, 11, .18) 1px, transparent 0) 0 0 / 26px 26px,
                    #FFF7ED;
            }
            {{-- "mais polido" (2026-08-04): a barely-there green-tinted
                 gradient instead of flat white — the same "considered, not
                 default" neutral this app's own palette already argues for
                 (see AGENTS.md's color-system memory), just applied here. --}}
            .ak-viz-viewport[data-viz-preset="corporativo"] {
                background: linear-gradient(160deg, #FFFFFF 0%, #F3F7F4 60%, #EEF3EF 100%);
            }
            {{-- Flat solid navy, no dot texture — matched to a real dark-UI
                 reference (swimlane flowchart template / ER diagram tool),
                 not a neon/cyberpunk treatment. --}}
            .ak-viz-viewport[data-viz-preset="tech"] { background: #132A45; }
            {{-- "arquitetura" (dark) and "blueprint" (light), 2026-09-16 — the
                 two themes that theme by TOKEN instead of by rule. Every
                 preset above overrides individual declarations (a background,
                 a box-shadow, an edge stroke) and leaves the `--viz-*` values
                 on `[data-ak-chain-viz]` standing; these two redefine the
                 tokens themselves, here on the viewport, and the base rules —
                 which already read `var(--viz-bg)`, `var(--viz-node)`,
                 `var(--viz-line)`, `var(--viz-ink)` — repaint themselves. The
                 dot grid, the edge stroke, the marker fill, the free block's
                 dashed border and the selection ring all follow for free.
                 What still needs a rule below is only what the base hardcodes
                 for a LIGHT canvas (a dark drop shadow, a white pill, a
                 near-black hairline) — which is exactly the list a dark theme
                 has to undo.

                 Defining them on the VIEWPORT and not on `[data-ak-chain-viz]`
                 is deliberate: the toolbar, the sidebar and the empty-state
                 legend are app chrome that happens to reuse these tokens, and
                 a theme is a property of the drawing, not of the panel holding
                 it. Everything that paints the diagram lives under the
                 viewport and inherits; everything outside keeps the defaults.

                 Palettes measured out of a rendered artifact from Archify
                 (MIT, tt-a1i/archify) — its "classic" dark and "blueprint"
                 light presets. The credit is all that is left of it: the
                 renderer itself was removed once this canvas could draw the
                 four models on its own, and no code of theirs ships here. What is NOT borrowed is its color axis: Archify fills
                 a block by what it technically is (frontend cyan, database
                 violet, security rose), which is the per-kind pastel this
                 canvas dropped on 2026-08-26 for the reason written on
                 `--viz-node` above. Shape still says what a block is; these
                 two only change the ground it stands on. --}}
            .ak-viz-viewport[data-viz-preset="arquitetura"] {
                --viz-bg: #020617;
                --viz-grid: #1E293B;
                --viz-line: #64748B;
                {{-- Opaque, where Archify's equivalent block is translucent:
                     its fills are `rgba(…, .4)` over an opaque `--mask` layer
                     painted behind every node precisely so the arrows running
                     underneath don't show through it. Our edges `<svg>` is a
                     sibling BELOW the blocks, so translucency would need that
                     same extra layer — and `#0F172A` is exactly what its
                     `rgba(30, 41, 59, .5)` composites to over `#020617`. Same
                     pixels, one layer fewer. --}}
                --viz-node: #0F172A;
                --viz-ink: #E2E8F0;
            }
            .ak-viz-viewport[data-viz-preset="blueprint"] {
                --viz-bg: #EDF7FA;
                --viz-grid: #B5D5E1;
                --viz-line: #6D93A5;
                --viz-node: #F9FDFF;
                --viz-ink: #123344;
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
            /* Lane (`viz_layout.lanes`) — a free rectangle drawn behind the
               canvas, child of `#world` (real WORLD-space position, like a
               node): `x`/`y`/`width`/`height` become `left`/`top`/`width`/
               `height` in `chain-viz.js::rebuildLanes()` with no unit
               conversion, so pan/zoom move/scale it for free through
               `world`'s own CSS transform — nothing to recompute per frame,
               unlike the previous "always full viewport width" design (which
               had to live in screen space specifically so its width could
               stay pinned to the viewport regardless of zoom; a free
               rectangle has no such constraint).

               NO z-index here on purpose. Behind-nodes ordering comes from
               plain DOM order instead: `rebuildLanes()` prepends every lane
               before the nodes already in `world`, so a lane always paints
               behind every block — dragging a block on top of a lane must
               keep the block fully visible/clickable, never buried behind
               the lane's tint. A positive z-index would paint above any
               `z-index: auto` sibling regardless of DOM order (wrong: it'd
               permanently occlude any block under it); a negative one
               escapes to the nearest REAL stacking context, and neither
               `.ak-viz-viewport` nor `[data-ak-chain-viz]` establish one
               (both are `position` + `z-index: auto`, which doesn't count),
               so it would paint behind the component's own `bg-surface`
               instead of just behind the nodes. Plain document order has no
               such escape hatch — the lesson that already burned this
               feature once, still true after the world-space move. */
            .ak-viz-lane {
                position: absolute;
                border: 1.5px solid rgba(148, 163, 196, .5);
                border-radius: 0;
                pointer-events: none;
            }
            /* Selected (`chain-viz.js::selectLane()`, a click with no
               drag) — thicker, tinted border instead of the node's glowing
               ring: a lane can be very large, and a big box-shadow halo
               around its whole perimeter would read as noisy rather than a
               clean selection cue. */
            .ak-viz-lane.is-selected {
                border-width: 2px;
                border-color: var(--viz-select);
            }
            /* Rounded corners toggle (`chain-viz.js`'s
               `lane.rounded`) — `--ak-lane-radius` drives both the
               rectangle itself and (below) the matching corners of its
               label strip, so the label's own square corners never poke
               out past the now-rounded body. */
            .ak-viz-lane.is-rounded {
                --ak-lane-radius: 14px;
                border-radius: var(--ak-lane-radius);
            }
            /* Editable: the lane's own body becomes the "move" handle
               (`chain-viz.js`'s `drag.type === 'lane-move'`) — grabbing
               empty lane background repositions it, same gesture as
               dragging a block; a click with no drag opens the lane's
               toolbar instead (`selectLane()`). Resize handles below stop
               their own `mousedown` from reaching this, so they don't also
               trigger a move. Read-only mode leaves it inert (nothing to
               grab/click). */
            [data-ak-chain-viz][data-editable] .ak-viz-lane {
                pointer-events: auto;
                cursor: grab;
            }
            [data-ak-chain-viz][data-editable] .ak-viz-lane:active { cursor: grabbing; }
            /* Lane label — a vertical title running the full height of the
               lane's LEFT edge (a child of `.ak-viz-lane`, positioned
               relative to it, not the viewport), the classic swimlane
               convention. Background, text color and font-size are all set
               inline per-lane by `chain-viz.js` (`applyLaneHeaderStyle()`)
               — not here — since the header now has its OWN color
               (`lane.headerColor`, independent of the body's), and its text
               color is picked for CONTRAST against whatever that resolves to
               (`textColorFor()`) instead of a fixed white, now that a light
               header (white/bege) is a real option. It's the ONLY part of the
               lane that opens the toolbar on a plain click (`selectLane()`)
               — clicking anywhere else on the body only moves the lane if
               actually dragged, same gesture but no selection — hence its
               own `pointer-events`/cursor when editable, instead of falling
               through to `.ak-viz-lane` like everything else drawn on top of
               the body. */
            .ak-viz-lane-label {
                position: absolute;
                left: 0;
                top: 0;
                bottom: 0;
                width: 26px;
                display: flex;
                align-items: center;
                justify-content: center;
                writing-mode: vertical-rl;
                transform: rotate(180deg);
                text-orientation: mixed;
                font-family: 'Space Grotesk', 'Inter', system-ui, sans-serif;
                font-weight: 700;
                letter-spacing: .02em;
                white-space: nowrap;
                overflow: hidden;
                pointer-events: none;
            }
            [data-ak-chain-viz][data-editable] .ak-viz-lane-label {
                pointer-events: auto;
                cursor: pointer;
            }
            /* Matches the label's outer corners to `--ak-lane-radius` (set
               by `.is-rounded` above) so it visually merges into the
               rounded body instead of poking a square corner past it — only
               the two corners that actually sit on the lane's own boundary
               (left edge for the horizontal strip, top edge for the
               vertical one) need rounding; the other two are an internal
               cut into the rectangle and stay square. */
            .ak-viz-lane.is-rounded .ak-viz-lane-label {
                border-radius: var(--ak-lane-radius) 0 0 var(--ak-lane-radius);
            }
            /* Vertical orientation (`lane.orientation === 'vertical'`) —
               the title strip moves from the left edge (full height,
               rotated text) to the top edge (full width, plain
               left-to-right text), the classic top-header swimlane variant
               for a flow that runs top-to-bottom instead of left-to-right. */
            .ak-viz-lane.is-vertical .ak-viz-lane-label {
                left: 0;
                right: 0;
                top: 0;
                bottom: auto;
                width: auto;
                height: 26px;
                writing-mode: horizontal-tb;
                transform: none;
                text-orientation: initial;
                justify-content: flex-start;
                padding: 0 10px;
            }
            .ak-viz-lane.is-rounded.is-vertical .ak-viz-lane-label {
                border-radius: var(--ak-lane-radius) var(--ak-lane-radius) 0 0;
            }
            /* Resize handles — thin invisible strips (edges) or a small
               square (corner), all children of `.ak-viz-lane`: `-e` (right
               edge) changes only `width`, `-s` (bottom edge) only `height`,
               `-se` (corner) both at once — same 3-handle convention as any
               rectangle editor (Figma, Miro, Excalidraw). Read-only mode
               turns every one of them off entirely (nothing to drag). */
            .ak-viz-lane-resize {
                position: absolute;
                pointer-events: none;
            }
            [data-ak-chain-viz][data-editable] .ak-viz-lane-resize {
                pointer-events: auto;
            }
            .ak-viz-lane-resize-e { top: 0; right: -5px; width: 10px; height: 100%; cursor: ew-resize; }
            .ak-viz-lane-resize-s { left: 0; bottom: -5px; width: 100%; height: 10px; cursor: ns-resize; }
            .ak-viz-lane-resize-se { right: -6px; bottom: -6px; width: 14px; height: 14px; border-radius: 4px; cursor: nwse-resize; }
            [data-ak-chain-viz][data-editable] .ak-viz-lane-resize:hover,
            [data-ak-chain-viz][data-editable] .ak-viz-lane-resize.is-resizing {
                background: rgba(16, 24, 40, .18);
            }
            /* A "post-it" note (`chain-viz.js::rebuildNotes()`) — free
               multi-line text, always yellow (no configurable palette, unlike a
               block or a lane — it is a BASIC annotation on purpose). A child
               of `world` like a block or a lane (pan/zoom for free through
               `world`'s transform), but it enters AFTER the nodes in document
               order: a post-it is stuck ON TOP of the diagram, not behind
               it. */
            .ak-viz-note {
                position: absolute;
                width: 190px;
                display: flex;
                flex-direction: column;
                background: #FEF3C7;
                border-radius: 3px;
                box-shadow: 0 6px 16px rgba(120, 90, 10, .18), 0 1px 2px rgba(120, 90, 10, .15);
                font-family: 'Inter', system-ui, sans-serif;
            }
            /* The top strip — the only part that drags (to move) and the one
               carrying the remove button; the body below is all editable text
               (see `rebuildNotes()`), so it needs a "neutral" area to serve as
               a handle, in the same spirit as a lane's label. */
            .ak-viz-note-handle {
                display: flex;
                justify-content: flex-end;
                height: 18px;
                flex-shrink: 0;
                background: #FDE68A;
                border-radius: 3px 3px 0 0;
            }
            [data-ak-chain-viz][data-editable] .ak-viz-note-handle { cursor: grab; }
            [data-ak-chain-viz][data-editable] .ak-viz-note-handle:active { cursor: grabbing; }
            .ak-viz-note-remove {
                display: none;
                align-items: center;
                justify-content: center;
                width: 18px;
                height: 18px;
                border: none;
                background: transparent;
                color: #92650B;
                font-size: 14px;
                line-height: 1;
                cursor: pointer;
                opacity: .55;
            }
            .ak-viz-note-remove:hover { opacity: 1; }
            /* Só quem edita vê o botão de remover — mesmo espírito de
               `[data-integable]:not([data-editable]) .ak-viz-port` acima:
               um viewer ainda vê a anotação (conteúdo de `viz_layout` como
               qualquer outro), só não pode mexer nela. */
            [data-ak-chain-viz][data-editable] .ak-viz-note-remove { display: flex; }
            .ak-viz-note-body {
                resize: none;
                overflow: hidden;
                border: none;
                outline: none;
                background: transparent;
                width: 100%;
                box-sizing: border-box;
                padding: 4px 10px 10px;
                min-height: 54px;
                font: inherit;
                font-size: 12.5px;
                line-height: 1.4;
                color: #713F12;
            }
            .ak-viz-note-body::placeholder { color: rgba(113, 63, 18, .45); }
            [data-ak-chain-viz][data-editable] .ak-viz-note-body { cursor: text; }
            .ak-viz-edges path.ak-viz-edge {
                fill: none;
                stroke: var(--viz-line);
                stroke-width: 2;
            }
            /* Arrow being pulled out of a port, until the mouse is released
               (there's no edge in the chain yet — see `drag.type === 'connect'`). */
            .ak-viz-edges path.ak-viz-edge.is-preview {
                stroke: var(--viz-select);
                stroke-dasharray: 5 4;
            }
            /* Dashed arrow toggle (`viz_layout.edges[i].dashed`) — purely
               visual, independent of direction/protocol. */
            .ak-viz-edges path.ak-viz-edge.is-dashed {
                stroke-dasharray: 7 5;
            }
            .ak-viz-edges marker path { fill: var(--viz-line); }
            .ak-viz-edges .ak-viz-plabel-box {
                fill: #fff;
                stroke: var(--viz-line);
                stroke-width: 1;
            }
            .ak-viz-edges .ak-viz-plabel-text {
                fill: #4f5b7a;
                font-family: ui-monospace, 'SF Mono', Menlo, Consolas, monospace;
                font-size: 10px;
                text-anchor: middle;
                dominant-baseline: middle;
            }
            /* Editable protocol pill — the parent SVG (.ak-viz-edges) has
               `pointer-events: none` (shouldn't capture pan clicks), so only
               the clickable pill re-enables events here. */
            .ak-viz-edges .ak-viz-plabel.is-editable { cursor: pointer; pointer-events: auto; }
            .ak-viz-edges .ak-viz-plabel.is-empty .ak-viz-plabel-box {
                fill: transparent;
                stroke-dasharray: 3 2;
            }
            .ak-viz-edges .ak-viz-plabel.is-empty .ak-viz-plabel-text { fill: var(--viz-line); }
            /* The EMPTY pill ("+ protocolo") is an invitation to click, not
               content of the drawing — and only somebody who edits sees it (see
               `drawProtocolPill()`: a viewer gets no pill at all on a link with
               no protocol). So it follows the same counter-scaling as the
               ports and handles and keeps its on-screen size at any zoom,
               instead of becoming the fattest thing on the arrow at 220%. The
               pill WITH a protocol written in it does not belong here: that is
               content, it scales with the diagram and it goes into the
               screenshot. `transform-box: fill-box` is what makes
               `transform-origin: center` mean the pill's own centre (inside an
               SVG <g> the default would be the coordinate system's origin). */
            .ak-viz-edges .ak-viz-plabel.is-empty {
                transform-box: fill-box;
                transform-origin: center;
                transform: scale(var(--viz-inv-scale, 1));
            }
            .ak-viz-edges .ak-viz-plabel.is-editable:hover .ak-viz-plabel-box { stroke: var(--viz-select); }
            .ak-viz-edges .ak-viz-plabel.is-editable:hover .ak-viz-plabel-text { fill: var(--viz-select); }
            /* The empty pill is an invitation to click — one per link with no
               protocol, all the time, was the biggest source of noise in a
               large drawing: in a lane flow they landed on the real labels and
               on the arrows themselves. It now appears only when the link is
               under the pointer (`drawEdgeHit()`) or lit by a block's
               selection — and it never takes pointer events, so it cannot
               fight the wide target that revealed it: entering it would give
               the target a `pointerleave`, the pill would vanish, the pointer
               would be back over the target and it would reappear, in a loop.
               Click and double click on a protocol-less link arrive through
               the target. */
            .ak-viz-edges .ak-viz-plabel.is-empty {
                opacity: 0;
                pointer-events: none;
                transition: opacity .12s ease;
            }
            .ak-viz-edges .ak-viz-plabel.is-empty.is-hovered,
            .ak-viz-edges .ak-viz-plabel.is-empty.is-linked { opacity: 1; }
            /* The links' pointer-target layer (see the markup for why it is not
               inside the edges SVG). It paints nothing and catches nothing
               itself — only its paths do. */
            .ak-viz-hits {
                position: absolute;
                top: 0;
                left: 0;
                overflow: visible;
                pointer-events: none;
                z-index: -1;
            }
            /* Alvo de ponteiro da ligação (`drawEdgeHit()`): invisível, largo,
               e o único elemento desta camada que recebe eventos.
               `pointer-events: stroke` limita o alvo à faixa
               do próprio traço — com `visiblePainted` um traço transparente não
               receberia nada, e com `all` a área FECHADA da rota viraria alvo
               junto, engolindo cliques no vazio entre dois cotovelos. */
            .ak-viz-hits path.ak-viz-edge-hit {
                fill: none;
                stroke: transparent;
                stroke-width: 16;
                pointer-events: stroke;
                cursor: pointer;
            }
            .ak-viz-edges path.ak-viz-edge.is-hovered { stroke-width: 3; }
            /* Presentation-mode dot (`chain-viz.js::startPresentAnimation()`)
               — a plain <circle>, sibling of the .ak-viz-edge <path>s inside
               this same <svg data-viz-edges>, positioned every frame via
               `getPointAtLength()`. Never touched by `clearOverlays()` (only
               removes `.ak-viz-edge`/`.ak-viz-plabel`). Color is per-dot, from
               `PRESENT_DOT_COLORS` — set on BOTH `el.style.fill` (translucent
               body, `fill-opacity: 0.7`) and `el.style.color` (the glow below
               reads `currentColor`, which resolves from `color`, not `fill` —
               setting only `fill` would leave every dot's glow the same
               inherited neutral shade instead of matching its own color). */
            .ak-viz-dot {
                filter: drop-shadow(0 0 4px currentColor) drop-shadow(0 0 8px currentColor);
            }
            /* Lavender/blue-ish nodes (mind-map palette), 10px radius (a bit
               tighter than the original 13px). Column layout: attribute line
               (optional) + body (avatar + name). The ring in the box-shadow
               is the node's only border — bumped from .03 to .14 opacity
               (2026-07-28) so it still delimits the block now that fills are
               much lighter (including plain white), where the old barely-there
               ring all but disappeared. */
            .ak-viz-node {
                position: absolute;
                display: flex;
                flex-direction: column;
                gap: 4px;
                width: max-content;
                min-width: 54px;
                max-width: 240px;
                padding: 10px 14px;
                border-radius: 10px;
                background: var(--viz-node);
                color: var(--viz-ink);
                font-family: 'Space Grotesk', 'Inter', system-ui, sans-serif;
                font-size: 13px;
                line-height: 1.35;
                font-weight: 500;
                white-space: normal;
                overflow-wrap: break-word;
                user-select: none;
                box-shadow: 0 1px 2px rgba(16, 24, 40, .08), 0 0 0 1px rgba(16, 24, 40, .14);
            }
            /* Theme border/shadow (`data-viz-theme` bottom-bar select,
               `applyTheme()`) — border/shadow color only, matching each
               theme's edge color (`EXPORT_PRESETS` in the JS); kind colors
               (system/decision/actor/…) are untouched on purpose, so a
               theme changes the MOOD without erasing what each block IS.
               `.ak-viz-node` is plain HTML (not inside the edges `<svg>`),
               so a normal external rule like this is enough — no need for
               the internal-`<style>`-duplication trick the SVG needs. */
            .ak-viz-world[data-viz-preset="casual"] .ak-viz-node {
                box-shadow: 0 2px 6px rgba(245, 158, 11, .15), 0 0 0 1px rgba(245, 158, 11, .35);
            }
            {{-- "mais polido" (2026-08-04): a proper layered card shadow —
                 soft ambient lift (large blur, negative spread, low opacity)
                 + a tight contact shadow + a crisp thin border — instead of
                 the single flat ring every other theme uses. This is the
                 actual visual difference between "flat" and "polished": more
                 shadow LAYERS at different distances, not more blur on one. --}}
            .ak-viz-world[data-viz-preset="corporativo"] .ak-viz-node {
                box-shadow: 0 8px 20px -8px rgba(27, 77, 46, .18), 0 2px 4px rgba(27, 77, 46, .08), 0 0 0 1px rgba(27, 77, 46, .20);
            }
            {{-- Flat card resting on the dark canvas — a plain (uncolored)
                 lift shadow grounds it, and the border is a barely-there
                 neutral hairline, not a colored/glowing ring. Matches the
                 reference: light pastel cards, no border glow at all. --}}
            .ak-viz-world[data-viz-preset="tech"] .ak-viz-node {
                box-shadow: 0 2px 8px rgba(0, 0, 0, .45), 0 0 0 1px rgba(148, 163, 184, .22);
            }
            {{-- The two token themes (see the viewport block above) need the
                 same treatment stated four times, because the base stylesheet
                 says "outline" in four different ways: a ring on the ordinary
                 block, NO ring on the three kinds that draw their own outline
                 (a free block's dashed border, a decision's clipped hexagon
                 layer, a logo-only block's bare image), a plain lift on the
                 two solid terminals, and a ring again on the actor's circle.
                 Each of those is a `.ak-viz-node.is-*` rule of its own, and a
                 theme rule is more specific than all of them — so a single
                 generic override doesn't just retint them, it erases the
                 distinction. --}}
            {{-- The block's CATEGORY family, as one custom property.

                 Set on every node in every theme and READ by only two of them
                 (right below) — which is the point. "Original", "Casual",
                 "Corporativo" and "Tech" go on saying a block is a block; the
                 two token themes add a SECOND axis on top of the one this
                 canvas already has. Shape still carries the KIND (chamfered
                 hexagon = decision, circle = actor, dashed = external), and
                 the hue carries what the system IS — the same hue the solution
                 tile, the category chip and the ecosystem map already use, so
                 somebody who has learned the catalog's colors reads the drawing
                 with them instead of with a legend.

                 Only a registered Solution has one: `ChainGraph::resolveNode()`
                 sends null for everything else and `paintNode()` removes the
                 attribute, so a decision, an actor, a terminal and a free-text
                 system stay deliberately uncolored. That is what keeps the axis
                 meaning something — a colored block is a system in the catalog,
                 filed under that family.

                 The values come straight from `@theme` in app.css
                 (`--color-cat-*`), which is what stops them drifting from
                 `App\Support\CategoryPalette`. These are real CSS rules rather
                 than Tailwind utilities, so the JIT's `@source` scan of that
                 class is not involved here. --}}
            .ak-viz-node[data-category-family="emerald"] { --viz-node-accent: var(--color-cat-emerald); }  /* manufatura, TMS, infraestrutura */
            .ak-viz-node[data-category-family="teal"] { --viz-node-accent: var(--color-cat-teal); }  /* e-commerce, CRM, atendimento */
            .ak-viz-node[data-category-family="blue"] { --viz-node-accent: var(--color-cat-blue); }  /* dados/BI, iPaaS */
            .ak-viz-node[data-category-family="indigo"] { --viz-node-accent: var(--color-cat-indigo); }  /* plataforma interna, ERP, ITSM */
            .ak-viz-node[data-category-family="fuchsia"] { --viz-node-accent: var(--color-cat-fuchsia); }  /* marketing, HCM */
            .ak-viz-node[data-category-family="rose"] { --viz-node-accent: var(--color-cat-rose); }  /* segurança, IAM, jurídico */
            .ak-viz-node[data-category-family="amber"] { --viz-node-accent: var(--color-cat-amber); }  /* pagamentos, fiscal */
            .ak-viz-node[data-category-family="slate"] { --viz-node-accent: var(--color-cat-slate); }  /* outros */

            {{-- A block whose system has a category wears its family's hue: a
                 solid 1px stroke, and the same hue folded into the fill at low
                 strength. `color-mix` is dropped whole by a browser that lacks
                 it, leaving the plain `--viz-node` fill from the base rule —
                 which is why the mix is written against an OPAQUE base and not
                 against `transparent`: a fallback that let the arrows
                 underneath show through the block would be worse than no tint.
                 Archify's treatment, over our own axis. --}}
            .ak-viz-world[data-viz-preset="arquitetura"] .ak-viz-node {
                box-shadow: 0 2px 10px rgba(2, 6, 23, .55), 0 0 0 1px rgba(148, 163, 184, .28);
            }
            {{-- `:not(.is-lifeline)`: numa linha de vida quem carrega a cor
                 é o CABEÇALHO, não a coluna. Tingir a caixa inteira pintava
                 uma faixa vertical do tamanho do diagrama atrás das
                 mensagens — o corpo de uma linha de vida é espaço vazio de
                 propósito, é por ele que as setas passam. --}}
            .ak-viz-world[data-viz-preset="arquitetura"] .ak-viz-node[data-category-family]:not(.is-lifeline) {
                background: color-mix(in oklab, var(--viz-node-accent) 16%, var(--viz-node));
                box-shadow: 0 2px 10px rgba(2, 6, 23, .55), 0 0 0 1px var(--viz-node-accent);
            }
            {{-- O cartão da linha de vida carrega a sombra/aro que o bloco
                 comum carrega; o bloco em si é transparente, então a regra
                 genérica do tema não o alcança. --}}
            .ak-viz-world[data-viz-preset="arquitetura"] .ak-viz-node.is-lifeline .ak-viz-node-body {
                background: var(--viz-node);
                box-shadow: 0 2px 10px rgba(2, 6, 23, .55), 0 0 0 1px rgba(148, 163, 184, .28);
            }
            .ak-viz-world[data-viz-preset="arquitetura"] .ak-viz-node.is-lifeline[data-category-family] .ak-viz-node-body {
                background: color-mix(in oklab, var(--viz-node-accent) 16%, var(--viz-node));
                box-shadow: 0 2px 10px rgba(2, 6, 23, .55), 0 0 0 1px var(--viz-node-accent);
            }
            .ak-viz-world[data-viz-preset="arquitetura"] .ak-viz-node.is-lifeline,
            .ak-viz-world[data-viz-preset="arquitetura"] .ak-viz-node.is-free,
            .ak-viz-world[data-viz-preset="arquitetura"] .ak-viz-node.is-decision,
            .ak-viz-world[data-viz-preset="arquitetura"] .ak-viz-node.is-logo-only {
                box-shadow: none;
            }
            .ak-viz-world[data-viz-preset="arquitetura"] .ak-viz-node.is-start,
            .ak-viz-world[data-viz-preset="arquitetura"] .ak-viz-node.is-end {
                box-shadow: 0 2px 10px rgba(2, 6, 23, .6);
            }
            .ak-viz-world[data-viz-preset="arquitetura"] .ak-viz-node.is-actor {
                box-shadow: 0 2px 10px rgba(2, 6, 23, .55), 0 0 0 1px rgba(148, 163, 184, .28);
            }
            {{-- The hexagon's outline is the 1px of this layer left showing
                 around the fill layer inset into it (see `.is-decision::before`
                 below) — a near-black wash on a near-black canvas is no
                 outline at all. --}}
            .ak-viz-world[data-viz-preset="arquitetura"] .ak-viz-node.is-decision::before {
                background: rgba(148, 163, 184, .38);
            }
            .ak-viz-world[data-viz-preset="arquitetura"] .ak-viz-node.is-dashed,
            .ak-viz-world[data-viz-preset="arquitetura"] .ak-viz-node.is-decision.is-dashed::after {
                border-color: rgba(148, 163, 184, .45);
            }
            {{-- The chip under a round block, and the resize handle's hover
                 wash: both are a fixed light-canvas color, not a token. --}}
            .ak-viz-world[data-viz-preset="arquitetura"] .ak-viz-node-endcap-label {
                background: #0F172A;
            }
            [data-ak-chain-viz][data-editable] .ak-viz-world[data-viz-preset="arquitetura"] .ak-viz-lane-resize:hover,
            [data-ak-chain-viz][data-editable] .ak-viz-world[data-viz-preset="arquitetura"] .ak-viz-lane-resize.is-resizing {
                background: rgba(226, 232, 240, .22);
            }
            {{-- "blueprint" is a light theme, so the base shadows already read
                 correctly; what it changes is their tint — a drafting-paper
                 canvas wants a blue-grey contact shadow, not the neutral
                 near-black one. --}}
            .ak-viz-world[data-viz-preset="blueprint"] .ak-viz-node {
                box-shadow: 0 1px 2px rgba(18, 51, 68, .10), 0 0 0 1px rgba(120, 170, 189, .55);
            }
            {{-- Lighter mix than the dark theme's: the same 16% over a white
                 card reads as a colored card, not a tinted one, and blueprint
                 is meant to stay drafting paper. --}}
            {{-- `:not(.is-lifeline)`: numa linha de vida quem carrega a cor
                 é o CABEÇALHO, não a coluna. Tingir a caixa inteira pintava
                 uma faixa vertical do tamanho do diagrama atrás das
                 mensagens — o corpo de uma linha de vida é espaço vazio de
                 propósito, é por ele que as setas passam. --}}
            .ak-viz-world[data-viz-preset="blueprint"] .ak-viz-node[data-category-family]:not(.is-lifeline) {
                background: color-mix(in oklab, var(--viz-node-accent) 8%, var(--viz-node));
                box-shadow: 0 1px 2px rgba(18, 51, 68, .10), 0 0 0 1px var(--viz-node-accent);
            }
            {{-- O cartão da linha de vida carrega a sombra/aro que o bloco
                 comum carrega; o bloco em si é transparente, então a regra
                 genérica do tema não o alcança. --}}
            .ak-viz-world[data-viz-preset="blueprint"] .ak-viz-node.is-lifeline .ak-viz-node-body {
                background: var(--viz-node);
                box-shadow: 0 1px 2px rgba(18, 51, 68, .10), 0 0 0 1px rgba(120, 170, 189, .55);
            }
            .ak-viz-world[data-viz-preset="blueprint"] .ak-viz-node.is-lifeline[data-category-family] .ak-viz-node-body {
                background: color-mix(in oklab, var(--viz-node-accent) 8%, var(--viz-node));
                box-shadow: 0 1px 2px rgba(18, 51, 68, .10), 0 0 0 1px var(--viz-node-accent);
            }
            .ak-viz-world[data-viz-preset="blueprint"] .ak-viz-node.is-lifeline,
            .ak-viz-world[data-viz-preset="blueprint"] .ak-viz-node.is-free,
            .ak-viz-world[data-viz-preset="blueprint"] .ak-viz-node.is-decision,
            .ak-viz-world[data-viz-preset="blueprint"] .ak-viz-node.is-logo-only {
                box-shadow: none;
            }
            .ak-viz-world[data-viz-preset="blueprint"] .ak-viz-node.is-start,
            .ak-viz-world[data-viz-preset="blueprint"] .ak-viz-node.is-end {
                box-shadow: 0 2px 8px rgba(18, 51, 68, .22);
            }
            .ak-viz-world[data-viz-preset="blueprint"] .ak-viz-node.is-actor {
                box-shadow: 0 2px 8px rgba(18, 51, 68, .12), 0 0 0 1px rgba(120, 170, 189, .55);
            }
            .ak-viz-world[data-viz-preset="blueprint"] .ak-viz-node.is-decision::before {
                background: rgba(120, 170, 189, .75);
            }
            .ak-viz-world[data-viz-preset="blueprint"] .ak-viz-node.is-dashed,
            .ak-viz-world[data-viz-preset="blueprint"] .ak-viz-node.is-decision.is-dashed::after {
                border-color: rgba(109, 147, 165, .6);
            }
            .ak-viz-world[data-viz-preset="blueprint"] .ak-viz-node-endcap-label {
                background: #F9FDFF;
            }
            /* Block body: avatar (logo or initial) + name. `position: relative`
               so it paints ABOVE the `::before` shape layer of a decision
               block (an absolutely-positioned pseudo element would otherwise
               cover static in-flow content). */
            .ak-viz-node-body {
                position: relative;
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
            }
            .ak-viz-node-avatar img {
                width: 100%;
                height: 100%;
                object-fit: contain;
            }
            .ak-viz-node-avatar.is-fallback {
                background: var(--viz-select);
                color: #fff;
                font-size: 10px;
                font-weight: 700;
            }
            /* Free-text node (external to Leo): white like every other block,
               told apart by the DASHED border. The border replaces the ring the
               base rule paints, hence `box-shadow: none` — two outlines on the
               same edge read as a rendering bug, not as emphasis. */
            {{-- LIFELINE (`ChainNodeKind::Lifeline`) — a sequence diagram's
                 participant.

                 The whole block is the COLUMN: the header is ordinary content
                 (avatar + name, like a system block) and the body is the empty
                 space below it, crossed by the `::after`'s dashed line. That is
                 why this is the only kind of block with a height stored in
                 `viz_layout` — on the others the size comes from what is
                 written inside.

                 `align-items: flex-start` plus an inline `height`: the content
                 stays at the top and the rest of the height is the line. The
                 box is still rectangular, so all the anchor/edge arithmetic
                 of
                 chain-viz.js segue valendo sem exceção — uma mensagem encosta
                 na lateral na altura que o `fromT`/`toT` da aresta disser. --}}
            .ak-viz-node.is-lifeline {
                justify-content: flex-start;
                padding: 10px 14px 0;
                min-width: 120px;
                background: transparent;
                box-shadow: none;
            }
            {{-- The header is the only part with a background: drawn as a
                 card inside the block, so the dashed line below starts at its
                 edge rather than out of nowhere. --}}
            .ak-viz-node.is-lifeline .ak-viz-node-body {
                width: 100%;
                justify-content: center;
                border-radius: 10px;
                padding: 8px 12px;
                background: var(--viz-node);
                box-shadow: 0 1px 2px rgba(16, 24, 40, .08), 0 0 0 1px rgba(16, 24, 40, .14);
            }
            .ak-viz-node.is-lifeline::after {
                content: '';
                position: absolute;
                left: 50%;
                top: 52px;
                bottom: 0;
                width: 0;
                border-left: 1.5px dashed var(--viz-line);
                transform: translateX(-50%);
                pointer-events: none;
            }

            {{-- STEP (`ChainNodeKind::Step`) — what HAPPENS, not who does it.

                 It exists because a step is not a system: written as the free
                 text of a `system` block, it inherited the dashed border that
                 means "outside Leo", which is true of a partner's API and says
                 nothing at all about "Abre o chamado". A solid box, like a
                 catalog system's, because it is content of the drawing just the
                 same — it simply is not a system. --}}
            .ak-viz-node.is-step {
                background: var(--viz-node);
            }

            .ak-viz-node.is-free {
                background: var(--viz-node);
                border: 1px dashed var(--viz-line);
                box-shadow: none;
            }
            /* Decision block (`ChainNodeKind::Decision`): the flow's branch, a
               chamfered hexagon. The shape is a `::before` layer, NOT
               `clip-path` on the block itself — clipping the element would also
               cut off its children (connection ports, comment badge), which sit
               on/outside its edges on purpose. The bounding box stays
               rectangular either way, so every edge/anchor calculation in
               chain-viz.js keeps working unchanged. */
            .ak-viz-node.is-decision {
                background: transparent;
                box-shadow: none;
                padding-left: 26px;
                padding-right: 26px;
            }
            /* TWO clipped layers, not one, and the reason is subtle enough to
               be worth stating: `clip-path` clips the element's BORDER too, and
               a border is drawn along the rectangular border box — so on a
               hexagon only the flat top and bottom spans of it ever survive,
               and the diagonals never had an outline at all. That went unnoticed
               while the block had a colored fill, because the FILL was what drew
               the shape. White fill on a near-white canvas made the block
               disappear (caught in a render on 2026-08-26), so the outline had
               to become real: `::before` is a full-size hexagon in the line
               color, `::after` is the white hexagon 1px inside it, and the 1px
               of the first one showing around the second IS the border. */
            .ak-viz-node.is-decision::before,
            .ak-viz-node.is-decision::after {
                content: '';
                position: absolute;
                clip-path: polygon(18px 0, calc(100% - 18px) 0, 100% 50%, calc(100% - 18px) 100%, 18px 100%, 0 50%);
                /* Both layers stay at 0 while everything that must READ above
                   them is lifted to 1 below. `::after` is generated as the last
                   child, so without this it would paint over the block's own
                   text, ports and comment badge. */
                z-index: 0;
            }
            .ak-viz-node.is-decision::before {
                inset: 0;
                background: rgba(16, 24, 40, .24);
            }
            .ak-viz-node.is-decision::after {
                inset: 1px;
                background: var(--viz-node);
            }
            .ak-viz-node.is-decision .ak-viz-node-body,
            .ak-viz-node.is-decision .ak-viz-comment-badge,
            .ak-viz-node.is-decision .ak-viz-port {
                z-index: 1;
            }
            /* THE THREE ROUND BLOCKS — Início/Fim (`ChainNodeKind::Start`/
               `End`) and Ator (`::Actor`): a small circle holding nothing but
               the kind's icon, with the label written BELOW it.

               The actor joined this group on 2026-08-26. It used to be a
               rounded PILL with the icon beside its text, which made a person
               read as one more box in the flow — and a flow's actors aren't
               steps in it, they're who it happens to. Same silhouette as the
               terminals says that in one glance, and the label leaving the
               shape is what lets a long name ("Analista fiscal do CD") stop
               stretching the circle into a lozenge.

               The label stays OUT of the node's own box on purpose:
               `node.w`/`h` (read from `offsetWidth`/`offsetHeight`) must remain
               exactly the circle's size, or the connection ports and edge
               anchors drift off-center toward whatever the label's height
               happens to be. That is why it is absolutely positioned at
               `top: 100%` rather than being a second row in a flex column. */
            .ak-viz-node.is-start,
            .ak-viz-node.is-end,
            .ak-viz-node.is-actor {
                width: 52px;
                height: 52px;
                min-width: 0;
                max-width: none;
                padding: 0;
                border-radius: 999px;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 2px 8px rgba(16, 24, 40, .22);
            }
            .ak-viz-node.is-start { background: var(--viz-node-start); }
            .ak-viz-node.is-end { background: var(--viz-node-end); }
            /* White like every other block, so it needs the delimiting ring
               back — the drop shadow above alone would leave a white circle
               floating on a near-white canvas with no edge at all. The two
               terminals don't: they are solid green/red. */
            .ak-viz-node.is-actor {
                background: var(--viz-node);
                box-shadow: 0 2px 8px rgba(16, 24, 40, .12), 0 0 0 1px rgba(16, 24, 40, .14);
            }
            .ak-viz-node.is-start .ak-viz-node-avatar.is-kind,
            .ak-viz-node.is-end .ak-viz-node-avatar.is-kind,
            .ak-viz-node.is-actor .ak-viz-node-avatar.is-kind {
                width: 22px;
                height: 22px;
            }
            .ak-viz-node.is-start .ak-viz-node-avatar.is-kind,
            .ak-viz-node.is-end .ak-viz-node-avatar.is-kind {
                color: #fff;
            }
            /* On white, the icon carries the ink color instead. */
            .ak-viz-node.is-actor .ak-viz-node-avatar.is-kind {
                color: var(--viz-ink);
            }
            .ak-viz-node.is-start .ak-viz-node-avatar.is-kind svg,
            .ak-viz-node.is-end .ak-viz-node-avatar.is-kind svg,
            .ak-viz-node.is-actor .ak-viz-node-avatar.is-kind svg {
                width: 22px;
                height: 22px;
            }
            /* The label under a round block wears the SAME chip as an arrow's
               protocol pill (`.ak-viz-plabel-box`: white fill, 1px
               `--viz-line` hairline): both are text that sits on open canvas
               rather than inside a shape, and without a ground of its own it
               was read against whatever the label happened to overlap — a
               grid line, a lane's tint, another block's shadow.

               `max-width` + `text-overflow` rather than `white-space: nowrap`
               alone: the chip is centered on a 52px circle, so an unbounded
               one reaches halfway across its neighbours on both sides. The
               full text stays available as the block's `title`. */
            .ak-viz-node-endcap-label {
                position: absolute;
                top: 100%;
                left: 50%;
                transform: translateX(-50%);
                margin-top: 6px;
                max-width: 140px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                padding: 2px 6px;
                border-radius: 6px;
                background: #fff;
                box-shadow: 0 0 0 1px var(--viz-line);
                font-size: 11px;
                font-weight: 600;
                color: var(--viz-ink);
                pointer-events: none;
            }
            /* Inline label editing (double click on the block's text —
               `startInlineLabelEdit()`): the `<input>` that replaces the
               `<span>` inherits the original class (`.ak-viz-node-text` or
               `.ak-viz-node-endcap-label`) plus this one — it resets the
               default input look and swaps the dotted outline for a visual cue
               that this is being edited. `pointer-events: auto` undoes the
               `none` on the start/end label (see above) — without it the mouse
               click that repositions the caret would never reach it. */
            .ak-viz-node-text-input {
                border: none;
                background: transparent;
                padding: 0;
                margin: 0;
                font: inherit;
                color: inherit;
                outline: 1.5px dashed var(--viz-select);
                outline-offset: 2px;
                border-radius: 3px;
                pointer-events: auto;
            }
            /* The inline edit's solution autocomplete, on a `system` block
               only — a child of the node itself (`n.el`), so it inherits
               `.ak-viz-world`'s pan/zoom `transform` for free and needs no
               position arithmetic of its own (unlike the toolbar and the
               protocol editor, which are children of `stage` and therefore
               compute their position from `getBoundingClientRect()`). */
            .ak-viz-inline-suggest {
                position: absolute;
                top: 100%;
                left: 0;
                margin-top: 4px;
                z-index: 30;
                display: flex;
                flex-direction: column;
                gap: 2px;
                width: max-content;
                min-width: 140px;
                max-width: 240px;
                max-height: 180px;
                overflow-y: auto;
                padding: 4px;
                border-radius: 8px;
                border: 1px solid var(--viz-line);
                background: var(--viz-bg);
                box-shadow: 0 8px 24px rgba(16, 24, 40, .18);
            }
            .ak-viz-inline-suggest.hidden { display: none; }
            .ak-viz-inline-suggest-item {
                display: block;
                width: 100%;
                text-align: left;
                padding: 4px 8px;
                border-radius: 6px;
                border: none;
                background: transparent;
                color: var(--viz-ink);
                font-size: 12px;
                cursor: pointer;
            }
            .ak-viz-inline-suggest-item:hover,
            .ak-viz-inline-suggest-item.is-active {
                background: var(--viz-select);
                color: #fff;
            }
            /* Protocol inline edit (`chain-viz.js::startInlineProtocolEdit()`)
               — the pill itself lives inside `<svg data-viz-edges>`, and a
               plain HTML child can't nest inside an SVG `<g>` without a
               `<foreignObject>`, so the `<input>` and its suggestion dropdown
               are both children of `stage` instead (screen-space), positioned
               via `getBoundingClientRect()` — same convention as the toolbar/
               protocol-editor panels, unlike the node's own inline Solution
               autocomplete above (a real child of the node, positioning
               itself for free off the node's `top:100%`). */
            .ak-viz-plabel-input {
                position: absolute;
                z-index: 21;
                border: none;
                outline: 1.5px dashed var(--viz-select);
                outline-offset: 1px;
                border-radius: 5px;
                background: #fff;
                padding: 0 4px;
                font-family: ui-monospace, 'SF Mono', Menlo, Consolas, monospace;
                font-size: 10px;
                color: #4f5b7a;
                text-align: center;
            }
            .ak-viz-plabel-suggest {
                position: absolute;
                z-index: 30;
                display: flex;
                flex-direction: column;
                gap: 2px;
                width: max-content;
                min-width: 120px;
                max-width: 220px;
                max-height: 160px;
                overflow-y: auto;
                padding: 4px;
                border-radius: 8px;
                border: 1px solid var(--viz-line);
                background: var(--viz-bg);
                box-shadow: 0 8px 24px rgba(16, 24, 40, .18);
            }
            .ak-viz-plabel-suggest.hidden { display: none; }
            .ak-viz-plabel-suggest-item {
                display: block;
                width: 100%;
                text-align: left;
                padding: 4px 8px;
                border-radius: 6px;
                border: none;
                background: transparent;
                color: var(--viz-ink);
                font-size: 12px;
                cursor: pointer;
            }
            .ak-viz-plabel-suggest-item:hover,
            .ak-viz-plabel-suggest-item.is-active {
                background: var(--viz-select);
                color: #fff;
            }
            /* Inline lane renaming
               (`chain-viz.js::startInlineLaneLabelEdit()`) — a child of `stage`
               (SCREEN space) centred on the label through a `transform`, not a
               child of the label itself: a lane's strip is only 26px wide/tall
               and (in the horizontal orientation) its text is vertical
               `writing-mode` — neither the room nor the orientation is any good
               for typing straight into, unlike a block's label. Fixed size and
               always horizontal, so typing here is the same whatever the
               orientation or size of the lane underneath. */
            .ak-viz-lane-label-input {
                position: absolute;
                z-index: 21;
                transform: translate(-50%, -50%);
                width: 160px;
                max-width: 60vw;
                border: none;
                outline: 1.5px dashed var(--viz-select);
                outline-offset: 2px;
                border-radius: 5px;
                background: #fff;
                color: var(--viz-ink);
                padding: 4px 8px;
                font-family: 'Space Grotesk', 'Inter', system-ui, sans-serif;
                font-size: 12px;
                font-weight: 600;
                text-align: center;
            }
            /* A pasted image (Ctrl+V, `ChainNodeKind::Image`): minimal frame
               (the picture already is the content), without the padding and
               minimum width of the other blocks — `max-width` matches a block's
               default `max-width` (240px) so it does not stand out in size in
               the middle of the graph. NO forced background — an image with
               real transparency (PNG/SVG with alpha) should let the canvas's
               dotted grid show through it rather than be given an imposed white
               slab; whoever wants a visible frame uses the toolbar's optional
               border (`imageBorderColor`, see the `.ak-viz-node.is-image`
               inline style in `applyNodeStyle()`), not this `background`. */
            .ak-viz-node.is-image {
                padding: 4px;
                min-width: 0;
                /* Explicit, not just "omitted" — the base `.ak-viz-node` rule
                   above already sets `background: var(--viz-node)` (the
                   lavender fill), which would otherwise show through any
                   transparent pixel just the same as a forced white would.
                   `applyNodeStyle()` still overrides this with an inline
                   style when the user picks a custom block color from the
                   toolbar (that escape hatch is intentional — e.g. a colored
                   mat behind a transparent-background logo). */
                background: transparent;
            }
            .ak-viz-node.is-image img {
                display: block;
                max-width: 232px;
                max-height: 232px;
                width: auto;
                height: auto;
                border-radius: 6px;
                object-fit: contain;
                pointer-events: none; /* the block's drag is already handled by the node's own `mousedown` */
            }
            /* "Somente logo" (`viz_layout.nodes[i].logoOnly`): swaps the card
               (avatar + name) for the solution's image at full size, with no
               frame and no background — the same reasoning as `.is-image`
               above (an image with real transparency should let the canvas show
               through it), except that here the image is the catalog's logo
               rather than media belonging to the node. */
            .ak-viz-node.is-logo-only {
                padding: 4px;
                min-width: 0;
                background: transparent;
                box-shadow: none;
            }
            .ak-viz-node.is-logo-only img {
                display: block;
                width: 64px;
                height: 64px;
                object-fit: contain;
                pointer-events: none;
            }
            /* Mídia removida por fora (ou um nó `image` mal formado sem
               `media_id`) — quadro vazio com o ícone de fallback em vez de
               quebrar/ficar em branco sem nenhuma pista visual. */
            .ak-viz-node-image-fallback {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 120px;
                height: 90px;
                border-radius: 6px;
                background: var(--viz-node);
                border: 1px dashed var(--viz-line);
                color: var(--viz-line);
            }
            .ak-viz-node-image-fallback svg {
                width: 28px;
                height: 28px;
            }
            /* Dashed border toggle (`viz_layout.nodes[i].dashed`) — purely
               visual, independent of the block's kind/color. The decision
               block's visible shape is the `::before` layer (clip-path
               hexagon), so its outer box gets no border at all — the dash
               goes on `::before` instead, clipped by the same polygon so it
               traces the hexagon's edge rather than the bounding rectangle. */
            .ak-viz-node.is-dashed {
                border: 1.5px dashed rgba(26, 26, 46, .4);
            }
            .ak-viz-node.is-free.is-dashed {
                border-color: var(--viz-line);
            }
            .ak-viz-node.is-decision.is-dashed {
                border: none;
            }
            /* The dashed cue on a hexagon is still only as good as a clipped
               border can be — the flat top and bottom spans, never the
               diagonals (see the two-layer note above). It rides on the FILL
               layer so it sits inside the hairline rather than replacing it,
               which keeps the shape readable either way. */
            .ak-viz-node.is-decision.is-dashed::after {
                border: 1.5px dashed rgba(26, 26, 46, .4);
            }
            /* Kind icon (decision/actor) — same slot as the solution avatar,
               but transparent: it's a stroked glyph, not a logo. */
            .ak-viz-node-avatar.is-kind {
                background: transparent;
                box-shadow: none;
                color: inherit;
                width: 16px;
                height: 16px;
                overflow: visible;
            }
            .ak-viz-node-avatar.is-kind svg {
                width: 16px;
                height: 16px;
            }
            /* Selected: highlighted blue ring.

               The `[data-ak-chain-viz]` prefix on both state rules is
               load-bearing, not tidiness: a theme paints its ring through
               `.ak-viz-world[data-viz-preset="…"] .ak-viz-node`, which is one
               class MORE specific than a bare `.ak-viz-node.is-selected` — so
               without the prefix, picking any theme but "Original" replaced
               the selection ring with the theme's resting shadow and selecting
               a block stopped showing at all. Being later in the file doesn't
               help: specificity is decided before source order. */
            [data-ak-chain-viz] .ak-viz-node.is-selected {
                box-shadow: 0 0 0 2px var(--viz-bg), 0 0 0 4px var(--viz-select), 0 4px 14px rgba(16, 24, 40, .14);
            }
            /* Block under the pointer while an arrow is being dragged out of
               another block's port — the drop target of the new link. */
            [data-ak-chain-viz] .ak-viz-node.is-link-target {
                box-shadow: 0 0 0 2px var(--viz-bg), 0 0 0 4px var(--viz-highlight), 0 4px 14px rgba(16, 24, 40, .14);
            }
            /* Comment badge — node's top-right corner. */
            .ak-viz-comment-badge {
                position: absolute;
                top: -6px;
                right: -6px;
                width: 14px;
                height: 14px;
                border-radius: 50%;
                background: var(--viz-select);
                border: 2px solid var(--viz-bg);
                display: none;
            }
            .ak-viz-node.has-comment .ak-viz-comment-badge { display: block; }
            /* Blocks are draggable when the diagram is editable. */
            [data-ak-chain-viz][data-editable] .ak-viz-node { cursor: grab; }
            [data-ak-chain-viz][data-editable] .ak-viz-node.is-dragging { cursor: grabbing; }
            /* Presentation mode: nothing is clickable/draggable — a block's
               own `mousedown` (`startNodePointer()`) always calls
               `stopPropagation()`/`preventDefault()` unconditionally (that's
               how a non-editable VIEWER still gets the read-only toolbar), so
               `editable=false` alone doesn't stop it; `pointer-events: none`
               here does — the event never fires at all, and falls straight
               through to the viewport's own pan handling underneath. The
               opacity transition is scoped to this same attribute so
               `exitPresentation()` can drop it first and reset every node
               back to fully visible instantly, with no reverse-fade. */
            [data-ak-chain-viz][data-presenting] .ak-viz-node {
                pointer-events: none;
                cursor: grab;
                transition: opacity .5s ease;
            }
            /* Same fadeIn as the block above, but for the arrow itself (and
               its protocol pill, if it has one) — an edge starts invisible
               and only fades in the instant a dot BEGINS travelling it
               (`revealEdge()`), not just because `draw()` rendered it. No
               `pointer-events: none` needed here: `editable=false` while
               presenting already means `draw()` never wires up the pill's
               click listener or the handles in the first place. */
            [data-ak-chain-viz][data-presenting] .ak-viz-edge,
            [data-ak-chain-viz][data-presenting] .ak-viz-plabel {
                transition: opacity .5s ease;
            }
            /* Connection ports — 4 per block (top/right/bottom/left), the
               "pull an arrow out of here" grip. Children of the block, so they
               follow it around with no coordinate math; only rendered when the
               diagram is editable, and only visible on hover/selection so
               the canvas stays clean. */
            .ak-viz-port {
                position: absolute;
                width: 11px;
                height: 11px;
                border-radius: 50%;
                background: #fff;
                border: 1.5px solid var(--viz-select);
                /* `scale(1/zoom)`: a port is a CONTROL, not drawing — it has
                   to measure the same on screen at 60% and at 300%.
                   `--viz-inv-scale` is written by `applyView()` on every
                   pan/zoom; the `translate(-50%,-50%)` still centres on the
                   anchor point (the scaling happens around the element's own
                   centre), so none of the anchor arithmetic changes. */
                transform: translate(-50%, -50%) scale(var(--viz-inv-scale, 1));
                opacity: 0;
                /* Invisible until hover/selection (below) — MUST also ignore
                   clicks while invisible, or its 11px hit target silently
                   steals clicks meant for whatever's underneath/around it
                   (a protocol pill drawn close to the block, an edge, empty
                   canvas to pan) with zero visual cue anything was even
                   there. Found via a scripted click on a protocol pill that
                   landed on a neighboring block's invisible port instead. */
                pointer-events: none;
                cursor: crosshair;
                transition: opacity .12s ease, transform .1s ease;
                box-shadow: 0 1px 2px rgba(16, 24, 40, .18);
                /* Below `.ak-viz-handle` (z-index 6) on purpose: an existing
                   arrow's handle lands on exactly the same spot as the port of
                   the anchor it's glued to, and dragging THAT must keep meaning
                   "retarget this arrow", not "start a new one". The block's
                   other three ports stay available. */
                z-index: 5;
            }
            [data-ak-chain-viz]:not([data-editable]) .ak-viz-port { display: none; }
            .ak-viz-port.is-t { left: 50%; top: 0; }
            .ak-viz-port.is-b { left: 50%; top: 100%; }
            .ak-viz-port.is-l { left: 0; top: 50%; }
            .ak-viz-port.is-r { left: 100%; top: 50%; }
            .ak-viz-node:hover .ak-viz-port,
            .ak-viz-node.is-selected .ak-viz-port { opacity: 1; pointer-events: auto; }
            .ak-viz-port:hover {
                background: var(--viz-select);
                transform: translate(-50%, -50%) scale(calc(1.35 * var(--viz-inv-scale, 1)));
            }
            /* Ports would only get in the way while a block is being dragged. */
            .ak-viz-node.is-dragging .ak-viz-port { display: none; }
            /* Arrow-tip handles (draggable) — subtle. */
            .ak-viz-handle {
                position: absolute;
                width: 9px;
                height: 9px;
                border-radius: 50%;
                background: #fff;
                border: 1.5px solid var(--viz-line);
                /* Mesma contra-escala da porta, e pelo mesmo motivo. */
                transform: translate(-50%, -50%) scale(var(--viz-inv-scale, 1));
                cursor: grab;
                z-index: 6;
                box-shadow: 0 1px 1.5px rgba(16, 24, 40, .15);
                transition: transform .1s ease, border-color .1s ease, background-color .1s ease;
            }
            .ak-viz-handle:hover {
                border-color: var(--viz-select);
                transform: translate(-50%, -50%) scale(calc(1.3 * var(--viz-inv-scale, 1)));
            }
            .ak-viz-handle.is-dragging {
                border-color: var(--viz-highlight);
                background: var(--viz-highlight);
                cursor: grabbing;
                transform: translate(-50%, -50%) scale(calc(1.3 * var(--viz-inv-scale, 1)));
            }
            /* Precision targets on a touch device. The port (11px) and the
               arrow-end handle (9px) are comfortable with a mouse and close to
               unhittable with a finger, which covers ~40px. Only the area
               grows — `translate(-50%, -50%)` still centres on the same
               coordinates, so nothing in the anchoring arithmetic
               (`screenToWorld()`, anchors) changes. The port keeps
               `pointer-events: none` while invisible, so the larger area does
               not go back to stealing clicks (see the comment on
               `.ak-viz-port`).

               On a touch device the port only appears through the
               `.is-selected` path: there is no hover, so the block has to be
               TAPPED before the link can be pulled — the help popover says
               so. */
            @media (pointer: coarse) {
                .ak-viz-port { width: 22px; height: 22px; }
                .ak-viz-handle { width: 20px; height: 20px; }
                .ak-viz-lane-resize-e { right: -11px; width: 22px; }
                .ak-viz-lane-resize-s { bottom: -11px; height: 22px; }
                .ak-viz-lane-resize-se { right: -12px; bottom: -12px; width: 26px; height: 26px; }
            }
            .ak-viz-anchor {
                position: absolute;
                width: 10px;
                height: 10px;
                border-radius: 50%;
                background: rgba(74, 144, 217, .22);
                /* Idem porta/alça: sinalização de ancoragem, não desenho. */
                transform: translate(-50%, -50%) scale(var(--viz-inv-scale, 1));
                z-index: 4;
                pointer-events: none;
            }
            .ak-viz-anchor.is-near {
                background: var(--viz-highlight);
                box-shadow: 0 0 0 4px rgba(170, 219, 30, .28);
            }
            [data-ak-chain-viz]:fullscreen {
                background: var(--viz-bg);
                border-radius: 0;
            }
            [data-ak-chain-viz]:fullscreen .ak-viz-viewport { border-radius: 0; }

            /* Markdown comment preview — arbitrary content with no fixed
               element to attach a class to (same exception as .html-content
               documented in AGENTS.md), hence CSS here instead of utilities,
               but using the app's color tokens (chrome). */
            .ak-viz-md { line-height: 1.6; }
            .ak-viz-md .md-empty { color: var(--color-faint); font-style: italic; }
            .ak-viz-md h1, .ak-viz-md h2, .ak-viz-md h3, .ak-viz-md h4 {
                font-weight: 600;
                color: var(--color-ink);
                line-height: 1.25;
                margin: .6em 0 .35em;
            }
            .ak-viz-md h1 { font-size: 1.3em; }
            .ak-viz-md h2 { font-size: 1.15em; }
            .ak-viz-md h3 { font-size: 1.05em; }
            .ak-viz-md h4 { font-size: .95em; }
            .ak-viz-md p { margin: .5em 0; }
            .ak-viz-md ul, .ak-viz-md ol { margin: .5em 0; padding-left: 1.4em; }
            .ak-viz-md li { margin: .2em 0; }
            .ak-viz-md a { color: var(--color-accent); text-decoration: underline; }
            .ak-viz-md code {
                background: var(--color-raised);
                padding: .12em .4em;
                border-radius: 5px;
                font-family: ui-monospace, Menlo, Consolas, monospace;
                font-size: .88em;
            }
            .ak-viz-md pre {
                background: var(--color-ink);
                color: #fff;
                padding: 12px 14px;
                border-radius: 9px;
                overflow-x: auto;
                margin: .6em 0;
            }
            .ak-viz-md pre code { background: transparent; color: inherit; padding: 0; font-size: .85em; }
            .ak-viz-md blockquote {
                border-left: 3px solid var(--color-accent);
                margin: .6em 0;
                padding: .1em 0 .1em 14px;
                color: var(--color-muted);
            }
            .ak-viz-md hr { border: none; border-top: 1px solid var(--color-line); margin: .9em 0; }
            .ak-viz-md strong { font-weight: 700; color: var(--color-ink); }
            .ak-viz-md del { color: var(--color-faint); }
    </style>
</div>
