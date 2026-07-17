{{--
    Graphical visualization of the selected integration (right side of the F3
    section). JS-driven canvas (legitimate exception to "utilities over custom
    CSS", like flow-canvas): absolutely-positioned nodes + SVG edges, with
    pan/zoom and browser fullscreen. Draws the chain (`chain = {nodes, edges}`,
    each edge `{from, to, arrow, protocol}` by node index) of the integration
    chosen in the list on the left — a genuinely FREE GRAPH, not a straight
    line, and it doesn't require every block to be linked to something: a
    block can stay isolated, either because it was born that way ("Sem
    conexão" in the "Adicionar bloco" panel) or because its last link was
    removed (the "Desligar" button in the link editor). Editable/extensible
    things in place: a node's title (except the root, index 0), via the
    pencil in the block's contextual toolbar; direction + protocol of any
    link, by clicking the pill above the arrow (including the dashed "+
    protocolo" pill, when it doesn't have one yet) — the same editor has a
    "Desligar" button that removes only the link, never the blocks; a NEW
    block at the END of the chain, via the "+" button in the topbar
    ("Adicionar bloco" panel — pick a registered Solution or free text, plus
    the arrow/protocol of the new link, or "Sem conexão" to be born isolated);
    retargeting any link (one just created or any other existing one) to a
    different block, by dragging the arrow's tip to it; and "link mode" (the
    link icon in the block's toolbar) — click a block, it activates the mode,
    click any other block creates a NEW link between the two, without
    depending on an existing link to drag (this is what lets you link two
    blocks that were never connected, or reconnect an isolated block). All of
    these actions touch `chain` (the topology's source of truth) and re-run
    SyncIntegrationFromChain on the server — they aren't "purely visual"
    tweaks like position/color/comment (those stay only in `viz_layout`).
    This is the app's only topology editor — there's no separate form/modal
    anymore. Data arrives already resolved in each row's
    `data-integration-graph` (see Solutions\IntegrationsMap);
    `integration-viz.js` reads and draws it. Arrows follow the link's
    direction (`->` forward, `<-` backward, `<->` both) and each arrow's
    label is the protocol.

    A fifth adjustment, on the topbar's pencil (not the block's): name/status
    of the selected integration — the only metadata that doesn't live on a
    node/edge. Creating a new Integration is the "Nova" form in the list on
    the left (`integrations-map.blade.php`), which already delivers the chain
    with only the root node; from there on, it's all done here.

    Chrome (top bar, contextual selection toolbar, comment sidebar) in
    Tailwind utilities + `x-forms.button`, following the Leo brand. The
    internal canvas (nodes/edges/handles) keeps the `<style>` block scoped
    with `--viz-*` tokens, now mirroring the navy/blue palette of the
    reference mind-map (the only sanctioned exception to "utilities over
    custom CSS" in this view).
--}}
<div data-integration-viz
    class="ak-viz relative flex min-h-[360px] flex-1 flex-col overflow-hidden rounded-card border border-line bg-surface">

    {{-- Top bar: logo + selected integration + view actions (organize
         default layout / center / fullscreen / save). No topology-authoring
         action lives here — only the topology, always the chain, decides
         nodes and edges. --}}
    <div data-viz-topbar class="ak-viz-topbar flex shrink-0 items-center gap-3 border-b border-line bg-surface px-3 py-2">
    
        <p data-viz-title class="min-w-0 flex-1 truncate text-sm font-medium text-ink">Selecione uma integração à esquerda</p>

        {{-- Rename / change status of the selected integration — the only
             metadata that data-viz doesn't yet edit on the block/edge itself.
             Only visible when editable and an integration is selected. --}}
        <x-forms.button type="button" variant="ghost" data-viz-meta-edit title="Renomear / mudar status"
            class="!hidden !shrink-0 !rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
            <x-heroicon-o-pencil-square class="size-4" />
        </x-forms.button>

        <span data-viz-hint class="hidden shrink-0 text-xs text-faint lg:inline">clique seleciona · arraste move · roda dá zoom</span>

        <div class="flex shrink-0 items-center gap-1">
            {{-- Add block: always at the END of the chain (root → ... → new)
                 — opens the `data-viz-add-editor` panel, anchored to this
                 button. Only visible when the integration is editable (same
                 gate as the Save button). --}}
            <x-forms.button type="button" variant="ghost" data-viz-add-node title="Adicionar bloco"
                class="!hidden !rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                <x-heroicon-o-plus class="size-4" />
            </x-forms.button>
            <x-forms.button type="button" variant="ghost" data-viz-organize title="Organizar layout padrão"
                class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                <x-heroicon-o-squares-2x2 class="size-4" />
            </x-forms.button>
            <x-forms.button type="button" variant="ghost" data-viz-fit-top title="Centralizar"
                class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                <x-heroicon-o-arrows-pointing-in class="size-4" />
            </x-forms.button>
            <x-forms.button type="button" variant="ghost" data-viz-fullscreen-top title="Tela cheia"
                class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                <x-heroicon-o-arrows-pointing-out data-viz-fs-open-top class="size-4" />
                <x-heroicon-o-arrows-pointing-in data-viz-fs-close-top class="hidden size-4" />
            </x-forms.button>
            {{-- Save layout — the JS reveals it (removes `hidden`) only when
                 the integration is editable, and enables it when there's an
                 unsaved change. --}}
            <span data-viz-save-sep class="mx-0.5 hidden h-5 w-px bg-line"></span>
            <x-forms.button type="button" data-viz-save title="Salvar posição dos blocos, das setas e dos comentários"
                class="!hidden !rounded-md !px-3 !py-1 !text-xs disabled:!opacity-45 disabled:!cursor-not-allowed">
                <span data-viz-save-label>Salvar</span>
            </x-forms.button>
        </div>
    </div>

    {{-- `data-viz-stage`: reference base for positioning the toolbar/protocol
         editor in JS. These panels are `position:absolute` and resolve
         against THIS element (the nearest positioned ancestor), not against
         `[data-integration-viz]` — which is also `relative`, but includes the
         topbar above. Using `root.getBoundingClientRect()` for these
         calculations mistakenly adds the topbar's height, pushing the panel
         down, on top of the block (reported bug: "covers half the block
         vertically"). --}}
    <div data-viz-stage class="relative min-h-0 flex-1">
        <div data-viz-viewport class="ak-viz-viewport">
            <div data-viz-world class="ak-viz-world">
                <svg data-viz-edges class="ak-viz-edges" xmlns="http://www.w3.org/2000/svg">
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
                {{-- nodes injected by integration-viz.js --}}
            </div>
        </div>

        {{-- Empty state / no chain — overlaid, hidden when there's a drawing --}}
        <div data-viz-empty
            class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-2 px-6 text-center">
            <x-heroicon-o-share class="size-8 text-faint" />
            <p data-viz-empty-title class="text-sm font-medium text-muted">Selecione uma integração à esquerda</p>
            <p data-viz-empty-hint class="text-xs text-faint">A visualização gráfica aparecerá aqui.</p>
        </div>

        {{-- "Link mode" (data-viz-toolbar-link): active after clicking the
             link pencil, until clicking the destination block (or Esc/click
             on the background, which cancels). Hint + explicit cancel button,
             for anyone who doesn't know the keyboard shortcut. --}}
        <div data-viz-link-hint
            class="pointer-events-none absolute left-1/2 top-3 z-10 hidden -translate-x-1/2 items-center gap-2 rounded-lg border border-line bg-surface/95 px-3 py-1.5 text-xs text-ink shadow-[0_2px_8px_rgba(20,58,34,0.08)] backdrop-blur">
            <span>Clique em outro bloco para ligar</span>
            <x-forms.button type="button" variant="ghost" data-viz-link-cancel
                class="pointer-events-auto !rounded-md !p-1 !text-muted hover:!bg-accent-soft hover:!text-ink">
                <x-heroicon-o-x-mark class="size-3.5" />
            </x-forms.button>
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
        </div>

        {{-- Contextual toolbar: appears anchored to the selected node (click
             without drag). Block style (color, text color, font) is only
             editable — it never touches the topology, only the visual
             `viz_layout`, same spirit as the position/anchors already
             persisted there. --}}
        <div data-viz-toolbar
            class="ak-viz-toolbar pointer-events-none absolute z-20 hidden flex-wrap items-center gap-1.5 rounded-xl border border-line bg-surface p-1.5 shadow-[0_8px_28px_rgba(16,24,40,.16)]">
            <div data-viz-toolbar-style class="pointer-events-auto flex items-center gap-1.5">
                {{-- Block color palette — presets generated in JS (integration-viz.js::buildSwatches) --}}
                <div data-viz-swatches class="flex items-center gap-1"></div>

                {{-- Custom block color --}}
                <x-forms.input type="color" data-viz-custom-color title="Cor personalizada do bloco"
                    class="!size-[22px] !shrink-0 !cursor-pointer !rounded-md !border !border-line !bg-transparent !p-0 [&::-webkit-color-swatch]:!rounded-md [&::-webkit-color-swatch]:!border-none [&::-webkit-color-swatch-wrapper]:!p-0" />

                <span class="mx-0.5 h-6 w-px shrink-0 bg-line"></span>

                {{-- Text color — square with an underlined "A" in the current color, same as the reference mind-map --}}
                <div class="relative flex size-[26px] shrink-0">
                    <x-forms.label for="viz-text-color-input" data-viz-text-color-wrap title="Cor do texto"
                        class="!m-0 !flex !size-full !font-extrabold !text-ink size-full cursor-pointer items-center justify-center rounded-md border border-line text-sm">
                        <span class="pointer-events-none border-b-[3px] border-current pb-px">A</span>
                    </x-forms.label>
                    <x-forms.input type="color" id="viz-text-color-input" data-viz-text-color
                        class="!absolute !inset-0 !size-full !cursor-pointer !border-0 !bg-transparent !p-0 !opacity-0" />
                </div>

                {{-- Text font — mono / sans / serif. Wrapped in a fixed-width
                     wrapper: the design system's <select> auto-wraps itself in
                     `w-full`, which inside a flex toolbar would take up all the
                     remaining space (same caveat documented in solutions/map.blade.php). --}}
                <div class="w-[70px] shrink-0">
                    <x-forms.select data-viz-font title="Fonte do texto"
                        class="!h-[26px] !w-full !rounded-md !border-line !bg-surface !py-0 !pl-1.5 !pr-5 !text-xs">
                        <option value="sans">Sans</option>
                        <option value="serif">Serif</option>
                        <option value="mono">Mono</option>
                    </x-forms.select>
                </div>

                <span class="mx-0.5 h-6 w-px shrink-0 bg-line"></span>
            </div>

            <div data-viz-toolbar-actions class="pointer-events-auto flex items-center gap-1.5">
                {{-- Node title: only shown when the integration is editable and on
                     a node that isn't the root (index 0) — same invariant as the
                     full chain form, where the root is fixed by the route's
                     context (see `selectNode` in integration-viz.js). --}}
                <x-forms.button type="button" variant="ghost" data-viz-toolbar-title title="Editar título do nó"
                    class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                    <x-heroicon-o-pencil class="size-4" />
                </x-forms.button>
                <x-forms.button type="button" variant="ghost" data-viz-toolbar-comment title="Comentário"
                    class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                    <x-heroicon-o-chat-bubble-left-ellipsis class="size-4" />
                </x-forms.button>
                {{-- Only visible when editable (same gate as the title) — activates
                     "link mode": the next click on a different block opens the
                     new-link editor (`data-viz-protocol-editor` in "create" mode),
                     without going through `retargetEdge`. --}}
                <x-forms.button type="button" variant="ghost" data-viz-toolbar-link title="Ligar a outro bloco"
                    class="!hidden !rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                    <x-heroicon-o-link class="size-4" />
                </x-forms.button>
                <x-forms.button type="button" variant="ghost" data-viz-toolbar-open title="Abrir solução"
                    class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft disabled:!cursor-not-allowed disabled:!opacity-40">
                    <x-heroicon-o-arrow-top-right-on-square class="size-4" />
                </x-forms.button>
            </div>

            {{-- Node title editor — select of registered Solutions + free text,
                 editing an already-existing node directly on the selected
                 block. Choosing a Solution pulls in name/logo/attributes as
                 always; the "Outro" option accepts free text. Solution
                 options come from `[data-ak-solutions]` (rendered once in
                 integrations-map.blade.php), read and cached in JS. --}}
            <div data-viz-title-editor class="pointer-events-auto hidden w-[min(280px,80vw)] flex-col gap-2">
                <x-forms.select data-viz-title-select class="!h-8 !w-full !rounded-md !border-line !bg-surface !py-0 !text-xs"></x-forms.select>
                <x-forms.input type="text" data-viz-title-label placeholder="Nome do sistema externo"
                    class="hidden !h-8 !w-full !rounded-md !border-line !bg-surface !text-xs" />
                <div class="flex items-center justify-end gap-1.5">
                    <x-forms.button type="button" variant="ghost" data-viz-title-cancel
                        class="!rounded-md !px-2.5 !py-1 !text-xs !text-muted hover:!bg-accent-soft">
                        Cancelar
                    </x-forms.button>
                    <x-forms.button type="button" data-viz-title-save
                        class="!rounded-md !px-2.5 !py-1 !text-xs">
                        <span data-viz-title-save-label>Salvar</span>
                    </x-forms.button>
                </div>
            </div>
        </div>

        {{-- "Adicionar bloco" panel: creates a new node at the END of the
             chain, linked to whichever node is currently last by a new link
             (arrow/protocol chosen here). This is only the starting point —
             the created link can be dragged to any other block afterwards
             (the arrow's handle on the canvas), re-linking the chain into a
             free graph; there's no second panel to "choose who to connect
             to" because dragging already handles that. Anchored to the
             topbar's "+" button (not to a node), which is why it's its own
             panel, outside the block's contextual toolbar. System:
             registered Solution (same list as `[data-ak-solutions]`) or
             free text — same pair as the node title editor. Arrow: 3
             hardcoded options (not from an enum). Protocol: same list as
             `[data-ak-protocols]` from the link's protocol editor. --}}
        <div data-viz-add-editor
            class="pointer-events-auto absolute z-20 hidden w-[min(260px,80vw)] flex-col gap-2 rounded-xl border border-line bg-surface p-2.5 shadow-[0_8px_28px_rgba(16,24,40,.16)]">
            <x-forms.select data-viz-add-select class="!h-8 !w-full !rounded-md !border-line !bg-surface !py-0 !text-xs"></x-forms.select>
            <x-forms.input type="text" data-viz-add-label placeholder="Nome do sistema externo"
                class="hidden !h-8 !w-full !rounded-md !border-line !bg-surface !text-xs" />
            <x-forms.select data-viz-add-arrow aria-label="Sentido do fluxo"
                class="!h-8 !w-full !rounded-md !border-line !bg-surface !py-0 !text-xs">
                <option value="->">&rarr; envia</option>
                <option value="<-">&larr; recebe</option>
                <option value="<->">&harr; envia e recebe</option>
                <option value="">Sem conexão — bloco isolado</option>
            </x-forms.select>
            <x-forms.select data-viz-add-protocol class="!h-8 !w-full !rounded-md !border-line !bg-surface !py-0 !text-xs">
                <option value="">Sem protocolo</option>
            </x-forms.select>
            <div class="flex items-center justify-end gap-1.5">
                <x-forms.button type="button" variant="ghost" data-viz-add-cancel
                    class="!rounded-md !px-2.5 !py-1 !text-xs !text-muted hover:!bg-accent-soft">
                    Cancelar
                </x-forms.button>
                <x-forms.button type="button" data-viz-add-save
                    class="!rounded-md !px-2.5 !py-1 !text-xs">
                    <span data-viz-add-save-label>Adicionar</span>
                </x-forms.button>
            </div>
        </div>

        {{-- Editor for the selected integration's name/status — anchored to
             the topbar's pencil (not to a node/edge), same spirit as the
             "Adicionar bloco" panel. Creating a new Integration is done via
             the "Nova" form in the list on the left
             (`integrations-map.blade.php`); this one only renames/changes
             the status of the one already selected. --}}
        <div data-viz-meta-editor
            class="pointer-events-auto absolute z-20 hidden w-[min(260px,80vw)] flex-col gap-2 rounded-xl border border-line bg-surface p-2.5 shadow-[0_8px_28px_rgba(16,24,40,.16)]">
            <x-forms.input type="text" data-viz-meta-name placeholder="Nome da integração"
                class="!h-8 !w-full !rounded-md !border-line !bg-surface !text-xs" />
            <x-forms.select data-viz-meta-status class="!h-8 !w-full !rounded-md !border-line !bg-surface !py-0 !text-xs"></x-forms.select>
            <div class="flex items-center justify-end gap-1.5">
                <x-forms.button type="button" variant="ghost" data-viz-meta-cancel
                    class="!rounded-md !px-2.5 !py-1 !text-xs !text-muted hover:!bg-accent-soft">
                    Cancelar
                </x-forms.button>
                <x-forms.button type="button" data-viz-meta-save
                    class="!rounded-md !px-2.5 !py-1 !text-xs">
                    <span data-viz-meta-save-label>Salvar</span>
                </x-forms.button>
            </div>
        </div>

        {{-- Editor for a link — two modes, same panel (`edgeEditorMode`
             in integration-viz.js):
               "edit"   opened by clicking the protocol pill above an
                        already existing arrow (or the dashed "+ protocolo"
                        pill); anchored to the clicked pill. Edits the
                        link's direction + protocol, with a "Desligar"
                        button to remove it (the block keeps existing, it
                        only loses that link).
               "create" opened by completing "link mode" (clicking the
                        destination block, after activating it via the
                        toolbar's link pencil); anchored to the destination
                        block. Chooses direction + protocol of the new
                        link; no "Desligar" (there's nothing to disconnect
                        yet).
             Protocol options come from `[data-ak-protocols]` (same
             source/format as the `App\Enums\Protocol` enum), read and
             cached in JS. --}}
        <div data-viz-protocol-editor
            class="pointer-events-auto absolute z-20 hidden w-[min(220px,80vw)] flex-col gap-2 rounded-xl border border-line bg-surface p-2.5 shadow-[0_8px_28px_rgba(16,24,40,.16)]">
            <x-forms.select data-viz-protocol-arrow aria-label="Sentido do fluxo"
                class="!h-8 !w-full !rounded-md !border-line !bg-surface !py-0 !text-xs">
                <option value="->">&rarr; envia</option>
                <option value="<-">&larr; recebe</option>
                <option value="<->">&harr; envia e recebe</option>
            </x-forms.select>
            <x-forms.select data-viz-protocol-select class="!h-8 !w-full !rounded-md !border-line !bg-surface !py-0 !text-xs">
                <option value="">Sem protocolo</option>
            </x-forms.select>
            <div class="flex items-center justify-between gap-1.5">
                <x-forms.button type="button" variant="ghost" data-viz-protocol-delete title="Desligar (remove só a ligação, não os blocos)"
                    class="!rounded-md !p-1.5 !text-muted hover:!bg-accent-soft hover:!text-crit">
                    <x-heroicon-o-link-slash class="size-4" />
                </x-forms.button>
                <div class="flex items-center gap-1.5">
                    <x-forms.button type="button" variant="ghost" data-viz-protocol-cancel
                        class="!rounded-md !px-2.5 !py-1 !text-xs !text-muted hover:!bg-accent-soft">
                        Cancelar
                    </x-forms.button>
                    <x-forms.button type="button" data-viz-protocol-save
                        class="!rounded-md !px-2.5 !py-1 !text-xs">
                        <span data-viz-protocol-save-label>Salvar</span>
                    </x-forms.button>
                </div>
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

    {{-- Inline (not @push): the layout has no @stack and the F3 section mounts
         this component only once per page, so there's no risk of duplication. --}}
    <style>
            @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap');

            /* Integration canvas (F3) — JS-driven, its own navy/blue palette
               (mirrors the reference mind-map), independent of the app's
               green/lime theme. */
            [data-integration-viz] {
                --viz-bg: #F7F9FC;
                --viz-grid: #E7ECF4;
                --viz-line: #94A3C4;
                --viz-node: #C9D4F7;
                --viz-node-free: #EBF4FC;
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
            .ak-viz-viewport.is-panning { cursor: grabbing; }
            /* "Link mode" active — next click on a block creates the link. */
            .ak-viz-viewport.is-linking { cursor: crosshair; }
            .ak-viz-viewport.is-linking .ak-viz-node { cursor: crosshair; }
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
                stroke-width: 2;
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
            .ak-viz-edges .ak-viz-plabel.is-editable:hover .ak-viz-plabel-box { stroke: var(--viz-select); }
            .ak-viz-edges .ak-viz-plabel.is-editable:hover .ak-viz-plabel-text { fill: var(--viz-select); }
            /* Lavender/blue-ish nodes (mind-map palette), 13px radius. Column
               layout: attribute line (optional) + body (avatar + name). */
            .ak-viz-node {
                position: absolute;
                display: flex;
                flex-direction: column;
                gap: 4px;
                width: max-content;
                min-width: 54px;
                max-width: 240px;
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
            }
            /* Discreet line above the block: solution's hosting/cloud
               (icon + label), only when the solution has that attribute set. */
            .ak-viz-node-attrs {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 6px;
                color: #6b7590;
                font-size: 9.5px;
                font-weight: 600;
                line-height: 1;
                text-transform: uppercase;
                letter-spacing: .02em;
            }
            .ak-viz-node-attr {
                display: inline-flex;
                align-items: center;
                gap: 2px;
            }
            .ak-viz-node-attr-icon {
                display: inline-flex;
            }
            .ak-viz-node-attr-icon svg {
                width: 10px;
                height: 10px;
            }
            /* Block body: avatar (logo or initial) + name. */
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
            /* Free-text node: light blue with dashed border (external to Leo). */
            .ak-viz-node.is-free {
                background: var(--viz-node-free);
                border: 1px dashed var(--viz-line);
                box-shadow: none;
            }
            /* Selected: highlighted blue ring. */
            .ak-viz-node.is-selected {
                box-shadow: 0 0 0 2px var(--viz-bg), 0 0 0 4px var(--viz-select), 0 4px 14px rgba(16, 24, 40, .14);
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
            /* Blocks are draggable when the integration is editable. */
            [data-integration-viz][data-editable] .ak-viz-node { cursor: grab; }
            [data-integration-viz][data-editable] .ak-viz-node.is-dragging { cursor: grabbing; }
            /* Arrow-tip handles (draggable) — subtle. */
            .ak-viz-handle {
                position: absolute;
                width: 9px;
                height: 9px;
                border-radius: 50%;
                background: #fff;
                border: 1.5px solid var(--viz-line);
                transform: translate(-50%, -50%);
                cursor: grab;
                z-index: 6;
                box-shadow: 0 1px 1.5px rgba(16, 24, 40, .15);
                transition: transform .1s ease, border-color .1s ease, background-color .1s ease;
            }
            .ak-viz-handle:hover {
                border-color: var(--viz-select);
                transform: translate(-50%, -50%) scale(1.3);
            }
            .ak-viz-handle.is-dragging {
                border-color: var(--viz-highlight);
                background: var(--viz-highlight);
                cursor: grabbing;
                transform: translate(-50%, -50%) scale(1.3);
            }
            .ak-viz-anchor {
                position: absolute;
                width: 10px;
                height: 10px;
                border-radius: 50%;
                background: rgba(74, 144, 217, .22);
                transform: translate(-50%, -50%);
                z-index: 4;
                pointer-events: none;
            }
            .ak-viz-anchor.is-near {
                background: var(--viz-highlight);
                box-shadow: 0 0 0 4px rgba(170, 219, 30, .28);
            }
            [data-integration-viz]:fullscreen {
                background: var(--viz-bg);
                border-radius: 0;
            }
            [data-integration-viz]:fullscreen .ak-viz-viewport { border-radius: 0; }

            /* Markdown comment preview — arbitrary content with no fixed
               element to attach a class to (same exception as .html-content
               documented in CLAUDE.md), hence CSS here instead of utilities,
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
