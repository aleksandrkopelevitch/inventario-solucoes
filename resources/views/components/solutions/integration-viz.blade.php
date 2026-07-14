{{--
    Visualização gráfica da integração selecionada (lado direito da seção F3).
    Canvas JS-driven (exceção legítima a "utilitários sobre CSS custom", como o
    flow-canvas): nós posicionados em absoluto + arestas em SVG, com pan/zoom e
    tela cheia do navegador. Desenha a cadeia (`chain = {nodes, edges}`, cada
    edge `{from, to, arrow, protocol}` por índice de nó) da integração
    escolhida na lista à esquerda — um GRAFO LIVRE de verdade, não uma linha
    reta e não exige que todo bloco esteja ligado a algo: um bloco pode ficar
    isolado, seja porque nasceu assim ("Sem conexão" no painel "Adicionar
    bloco") seja porque sua última ligação foi removida (botão "Desligar" do
    editor de ligação). Coisas editáveis/estendíveis no lugar: o título de um
    nó (exceto o raiz, índice 0), via o lápis na toolbar contextual do bloco;
    sentido + protocolo de qualquer ligação, clicando na pill acima da seta
    (inclusive a pill tracejada "+ protocolo", quando ainda não tem um) — o
    mesmo editor tem um botão "Desligar" que remove só a ligação, nunca os
    blocos; um bloco NOVO ao FINAL da cadeia, via o botão "+" da topbar
    (painel "Adicionar bloco" — escolhe uma Solução cadastrada ou texto livre,
    mais a seta/protocolo da nova ligação, ou "Sem conexão" pra nascer
    isolado); a religação de qualquer ligação (a que acabou de ser criada ou
    qualquer outra já existente) pra um bloco diferente, arrastando a ponta da
    seta até ele; e o "modo ligar" (ícone de elo na toolbar do bloco) — clique
    num bloco, ativa o modo, clique em outro bloco qualquer cria uma ligação
    NOVA entre os dois, sem depender de nenhuma ligação existente pra arrastar
    (é o que permite ligar dois blocos que nunca estiveram conectados, ou
    reconectar um bloco isolado). Todas essas ações mexem na `chain` (fonte de
    verdade da topologia) e rerodam SyncIntegrationFromChain no servidor — não
    são ajustes "só visuais" como posição/cor/comentário (esses continuam só
    em `viz_layout`). Este é o único editor de topologia da aplicação — não há
    mais um form/modal separado. Os dados chegam já resolvidos no
    `data-integration-graph` de cada linha (ver Solutions\IntegrationsMap);
    `integration-viz.js` lê e desenha. As setas seguem o sentido da ligação
    (`->` ida, `<-` volta, `<->` ambos) e o rótulo de cada seta é o protocolo.

    Um quinto ajuste, no lápis da topbar (não do bloco): nome/status da
    integração selecionada — o único metadado que não mora num nó/aresta.
    Criar uma Integration nova é o form "Nova" da lista à esquerda
    (`integrations-map.blade.php`), que já entrega a cadeia com só o nó raiz;
    daí em diante é tudo por aqui.

    Chrome (barra do topo, toolbar contextual de seleção, sidebar de
    comentário) em Tailwind utilities + `x-forms.button`, seguindo a marca
    Leo. O canvas interno (nós/arestas/handles) mantém o bloco `<style>`
    escopado com tokens `--viz-*`, agora espelhando a paleta navy/azul do
    mapa mental de referência (única exceção sancionada a "utilitários sobre
    CSS custom" nesta view).
--}}
<div data-integration-viz
    class="ak-viz relative flex min-h-[360px] flex-1 flex-col overflow-hidden rounded-card border border-line bg-surface">

    {{-- Barra do topo: logo + integração selecionada + ações de visualização
         (organizar layout padrão / centralizar / tela cheia / salvar). Nenhuma
         ação de autoria de topologia mora aqui — só a topologia, sempre
         a chain, decide nós e arestas. --}}
    <div data-viz-topbar class="ak-viz-topbar flex shrink-0 items-center gap-3 border-b border-line bg-surface px-3 py-2">
    
        <p data-viz-title class="min-w-0 flex-1 truncate text-sm font-medium text-ink">Selecione uma integração à esquerda</p>

        {{-- Renomear / mudar status da integração selecionada — o único
             metadado que o data-viz ainda não edita no próprio bloco/aresta.
             Só visível quando editável e há uma integração selecionada. --}}
        <x-forms.button type="button" variant="ghost" data-viz-meta-edit title="Renomear / mudar status"
            class="!hidden !shrink-0 !rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
            <x-heroicon-o-pencil-square class="size-4" />
        </x-forms.button>

        <span data-viz-hint class="hidden shrink-0 text-xs text-faint lg:inline">clique seleciona · arraste move · roda dá zoom</span>

        <div class="flex shrink-0 items-center gap-1">
            {{-- Adicionar bloco: sempre ao FINAL da cadeia (raiz → ... → novo)
                 — abre o painel `data-viz-add-editor`, ancorado a este botão.
                 Só visível quando a integração é editável (mesmo gate do
                 botão Salvar). --}}
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
            {{-- Salvar layout — o JS revela (remove `hidden`) só quando a
                 integração é editável, e habilita quando há mudança não salva. --}}
            <span data-viz-save-sep class="mx-0.5 hidden h-5 w-px bg-line"></span>
            <x-forms.button type="button" data-viz-save title="Salvar posição dos blocos, das setas e dos comentários"
                class="!hidden !rounded-md !px-3 !py-1 !text-xs disabled:!opacity-45 disabled:!cursor-not-allowed">
                <span data-viz-save-label>Salvar</span>
            </x-forms.button>
        </div>
    </div>

    {{-- `data-viz-stage`: base de referência para posicionar a toolbar/editor de
         protocolo em JS. Esses painéis são `position:absolute` e resolvem
         contra ESTE elemento (o ancestro posicionado mais próximo), não contra
         `[data-integration-viz]` — que também é `relative`, mas inclui a
         topbar acima. Usar `root.getBoundingClientRect()` para essas contas
         soma a altura da topbar por engano, empurrando o painel pra baixo, em
         cima do bloco (bug relatado: "tampa metade do bloco verticalmente"). --}}
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
                {{-- nós injetados por integration-viz.js --}}
            </div>
        </div>

        {{-- Estado vazio / sem cadeia — sobreposto, escondido quando há desenho --}}
        <div data-viz-empty
            class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-2 px-6 text-center">
            <x-heroicon-o-share class="size-8 text-faint" />
            <p data-viz-empty-title class="text-sm font-medium text-muted">Selecione uma integração à esquerda</p>
            <p data-viz-empty-hint class="text-xs text-faint">A visualização gráfica aparecerá aqui.</p>
        </div>

        {{-- "Modo ligar" (data-viz-toolbar-link): ativo depois do clique no
             lápis de ligação, até o clique no bloco de destino (ou Esc/clique
             no fundo, que cancela). Hint + botão de cancelar explícito, pra
             quem não sabe do atalho de teclado. --}}
        <div data-viz-link-hint
            class="pointer-events-none absolute left-1/2 top-3 z-10 hidden -translate-x-1/2 items-center gap-2 rounded-lg border border-line bg-surface/95 px-3 py-1.5 text-xs text-ink shadow-[0_2px_8px_rgba(20,58,34,0.08)] backdrop-blur">
            <span>Clique em outro bloco para ligar</span>
            <x-forms.button type="button" variant="ghost" data-viz-link-cancel
                class="pointer-events-auto !rounded-md !p-1 !text-muted hover:!bg-accent-soft hover:!text-ink">
                <x-heroicon-o-x-mark class="size-3.5" />
            </x-forms.button>
        </div>

        {{-- Controles: zoom / centralizar / tela cheia --}}
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

        {{-- Toolbar contextual: aparece ancorada ao nó selecionado (clique
             sem arraste). Estilo do bloco (cor, cor do texto, fonte) só é
             editável — nunca mexe na topologia, só no `viz_layout` visual,
             mesmo espírito da posição/âncoras já persistidas ali. --}}
        <div data-viz-toolbar
            class="ak-viz-toolbar pointer-events-none absolute z-20 hidden flex-wrap items-center gap-1.5 rounded-xl border border-line bg-surface p-1.5 shadow-[0_8px_28px_rgba(16,24,40,.16)]">
            <div data-viz-toolbar-style class="pointer-events-auto flex items-center gap-1.5">
                {{-- Paleta de cor do bloco — presets gerados em JS (integration-viz.js::buildSwatches) --}}
                <div data-viz-swatches class="flex items-center gap-1"></div>

                {{-- Cor personalizada do bloco --}}
                <x-forms.input type="color" data-viz-custom-color title="Cor personalizada do bloco"
                    class="!size-[22px] !shrink-0 !cursor-pointer !rounded-md !border !border-line !bg-transparent !p-0 [&::-webkit-color-swatch]:!rounded-md [&::-webkit-color-swatch]:!border-none [&::-webkit-color-swatch-wrapper]:!p-0" />

                <span class="mx-0.5 h-6 w-px shrink-0 bg-line"></span>

                {{-- Cor do texto — quadrado com "A" sublinhado na cor atual, igual ao mapa mental de referência --}}
                <div class="relative flex size-[26px] shrink-0">
                    <x-forms.label for="viz-text-color-input" data-viz-text-color-wrap title="Cor do texto"
                        class="!m-0 !flex !size-full !font-extrabold !text-ink size-full cursor-pointer items-center justify-center rounded-md border border-line text-sm">
                        <span class="pointer-events-none border-b-[3px] border-current pb-px">A</span>
                    </x-forms.label>
                    <x-forms.input type="color" id="viz-text-color-input" data-viz-text-color
                        class="!absolute !inset-0 !size-full !cursor-pointer !border-0 !bg-transparent !p-0 !opacity-0" />
                </div>

                {{-- Fonte do texto — mono / sans / serif. Envolvido num wrapper de
                     largura fixa: o <select> do design system se auto-envolve num
                     `w-full`, que numa toolbar flex ocuparia todo o espaço restante
                     (mesma ressalva documentada em solutions/map.blade.php). --}}
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
                {{-- Título do nó: só aparece com a integração editável e num
                     nó que não seja o raiz (índice 0) — mesma invariante do
                     form completo de cadeia, onde o raiz é fixo pelo contexto
                     da rota (ver `selectNode` em integration-viz.js). --}}
                <x-forms.button type="button" variant="ghost" data-viz-toolbar-title title="Editar título do nó"
                    class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                    <x-heroicon-o-pencil class="size-4" />
                </x-forms.button>
                <x-forms.button type="button" variant="ghost" data-viz-toolbar-comment title="Comentário"
                    class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                    <x-heroicon-o-chat-bubble-left-ellipsis class="size-4" />
                </x-forms.button>
                {{-- Só visível quando editável (mesmo gate do título) — ativa o
                     "modo ligar": o próximo clique num bloco diferente abre o
                     editor de ligação nova (`data-viz-protocol-editor` em modo
                     "create"), sem passar por `retargetEdge`. --}}
                <x-forms.button type="button" variant="ghost" data-viz-toolbar-link title="Ligar a outro bloco"
                    class="!hidden !rounded-md !p-1.5 !text-ink hover:!bg-accent-soft">
                    <x-heroicon-o-link class="size-4" />
                </x-forms.button>
                <x-forms.button type="button" variant="ghost" data-viz-toolbar-open title="Abrir solução"
                    class="!rounded-md !p-1.5 !text-ink hover:!bg-accent-soft disabled:!cursor-not-allowed disabled:!opacity-40">
                    <x-heroicon-o-arrow-top-right-on-square class="size-4" />
                </x-forms.button>
            </div>

            {{-- Editor de título do nó — select de Soluções cadastradas +
                 texto livre, editando um nó já existente diretamente no
                 bloco selecionado. Escolher uma Solução puxa nome/logo/
                 atributos como sempre; a opção "Outro" aceita texto livre.
                 Opções da Solução vêm de `[data-ak-solutions]` (renderizado
                 uma vez em integrations-map.blade.php), lidas e cacheadas no
                 JS. --}}
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

        {{-- Painel "Adicionar bloco": cria um nó novo no FINAL da cadeia,
             ligado ao nó hoje no final por uma ligação nova (seta/protocolo
             escolhidos aqui). Esse é só o ponto de partida — a ligação criada
             pode ser arrastada pra qualquer outro bloco depois (handle da
             seta no canvas), religando a cadeia num grafo livre; não há um
             segundo painel pra "escolher a quem conectar" porque arrastar já
             resolve isso. Ancorado ao botão "+" da topbar (não a um nó), por
             isso é um painel próprio, fora da toolbar contextual do bloco.
             Sistema: Solução cadastrada (mesma lista de `[data-ak-solutions]`)
             ou texto livre — mesmo par do editor de título de nó. Seta: 3
             opções hardcoded (não vêm de enum). Protocolo: mesma lista de
             `[data-ak-protocols]` do editor de protocolo de ligação. --}}
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

        {{-- Editor de nome/status da integração selecionada — ancorado ao
             lápis da topbar (não a um nó/aresta), mesmo espírito do painel
             "Adicionar bloco". Criar uma Integration nova é feito pelo form
             "Nova" da lista à esquerda (`integrations-map.blade.php`); aqui
             só renomeia/muda status da já selecionada. --}}
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

        {{-- Editor de uma ligação — dois modos, mesmo painel (`edgeEditorMode`
             em integration-viz.js):
               "edit"   aberto ao clicar na pill de protocolo em cima de uma
                        seta já existente (ou na pill tracejada "+ protocolo");
                        ancorado à pill clicada. Edita sentido + protocolo da
                        ligação, com botão "Desligar" pra removê-la (o bloco
                        continua existindo, só perde essa ligação).
               "create" aberto ao completar o "modo ligar" (clique no bloco de
                        destino, depois de ativado pelo lápis de ligação da
                        toolbar); ancorado ao bloco de destino. Escolhe
                        sentido + protocolo da ligação nova; sem "Desligar"
                        (ainda não existe o que desligar).
             Opções de protocolo vêm de `[data-ak-protocols]` (mesma origem/
             formato do enum `App\Enums\Protocol`), lidas e cacheadas no JS. --}}
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

        {{-- Sidebar de comentário (markdown), escopada ao componente — nunca
             fixa à viewport da página. --}}
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

    {{-- Inline (não @push): o layout não tem @stack e a seção F3 monta este
         componente uma única vez por página, então não há risco de duplicar. --}}
    <style>
            @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap');

            /* Canvas de integração (F3) — JS-driven, paleta navy/azul própria
               (espelha o mapa mental de referência), independente do tema
               verde/lima da app. */
            [data-integration-viz] {
                --viz-bg: #F7F9FC;
                --viz-grid: #E7ECF4;
                --viz-line: #94A3C4;
                --viz-node: #C9D4F7;
                --viz-node-free: #EBF4FC;
                --viz-ink: #1A1A2E;
                --viz-select: #4A90D9; /* anel de seleção + badge de comentário */
                --viz-highlight: #AADB1E; /* âncora/handle em destaque */
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
            /* "Modo ligar" ativo — próximo clique num bloco cria a ligação. */
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
            /* Pill de protocolo editável — a SVG mãe (.ak-viz-edges) tem
               `pointer-events: none` (não deve capturar cliques de pan), então
               só a pill clicável reabilita eventos aqui. */
            .ak-viz-edges .ak-viz-plabel.is-editable { cursor: pointer; pointer-events: auto; }
            .ak-viz-edges .ak-viz-plabel.is-empty .ak-viz-plabel-box {
                fill: transparent;
                stroke-dasharray: 3 2;
            }
            .ak-viz-edges .ak-viz-plabel.is-empty .ak-viz-plabel-text { fill: var(--viz-line); }
            .ak-viz-edges .ak-viz-plabel.is-editable:hover .ak-viz-plabel-box { stroke: var(--viz-select); }
            .ak-viz-edges .ak-viz-plabel.is-editable:hover .ak-viz-plabel-text { fill: var(--viz-select); }
            /* Nós lavanda/azulados (paleta do mapa mental), raio 13px. Layout
               em coluna: linha de atributos (opcional) + corpo (avatar + nome). */
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
            /* Linha discreta em cima do bloco: hospedagem/cloud da solução
               (ícone + rótulo), só quando a solução tem o atributo definido. */
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
            /* Corpo do bloco: avatar (logo ou inicial) + nome. */
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
            /* Nó de texto livre: azul claro com borda tracejada (externo à Leo). */
            .ak-viz-node.is-free {
                background: var(--viz-node-free);
                border: 1px dashed var(--viz-line);
                box-shadow: none;
            }
            /* Selecionado: anel azul de destaque. */
            .ak-viz-node.is-selected {
                box-shadow: 0 0 0 2px var(--viz-bg), 0 0 0 4px var(--viz-select), 0 4px 14px rgba(16, 24, 40, .14);
            }
            /* Badge de comentário — canto superior direito do nó. */
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
            /* Blocos arrastáveis quando a integração é editável. */
            [data-integration-viz][data-editable] .ak-viz-node { cursor: grab; }
            [data-integration-viz][data-editable] .ak-viz-node.is-dragging { cursor: grabbing; }
            /* Handles das pontas da seta (arrastáveis) — discretos. */
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

            /* Pré-visualização do comentário em markdown — conteúdo arbitrário
               sem elemento fixo para atachar classe (mesma exceção do
               .html-content documentada no CLAUDE.md), por isso CSS aqui em
               vez de utilities, mas usando os tokens de cor do app (chrome). */
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
