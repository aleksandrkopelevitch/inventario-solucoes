# Inventário de Soluções

Aplicação Laravel standalone para catalogar as soluções e integrações da Leo
Madeiras: cadastro de soluções/pessoas/empresas, um editor gráfico de
topologia de integrações por solução, um mapa read-only do ecossistema
completo, documentação rica (estilo GitBook) para soluções e integrações — com
um assistente de IA que gera rascunhos —, um hub que reúne a cobertura dessa
documentação, e um gerador de flowSpec Digibee em formato de chat.

A base de infraestrutura genérica (form components, sistema de slots, módulos
JS, shells de layout, autenticação) foi portada do projeto de referência
**akop-pro**. O domínio legado daquele projeto (CRM Customer/Organization/
Interaction, análise DISC, extensão Chrome, multi-tenancy/RLS) **não** faz
parte deste projeto.

## Stack

- **Backend:** Laravel 13+, PHP 8.3+, SQLite (dev) / PostgreSQL (prod)
- **Frontend:** Blade, Vanilla JS (sem jQuery), Tailwind CSS 4
- **Build:** Vite 8 (requer **Node 20+**)
- **Auth:** sessão web (Sanctum disponível para futuros endpoints de API)
- **Testes:** Pest 4

## Setup

```bash
composer install          # instala dependências PHP (inclui Pest)
cp .env.example .env       # configure APP/DB
php artisan key:generate
php artisan migrate
npm install && npm run build
```

## Comandos

```bash
composer dev          # serve + queue + pail + vite em paralelo
composer test         # Pest (php artisan test)
./vendor/bin/pint     # code style
npm run build && php artisan optimize
```

## Papéis de usuário

`App\Enums\UserRole`: **viewer**, **agent**, **admin**. O primeiro usuário
cadastrado vira `admin`; os demais começam como `viewer`. O papel `agent` é
bloqueado de toda a web (`App\Http\Middleware\BlockAgentFromWeb`).

## Sistema de Slots (atualização parcial via AJAX)

Componentes de View usam a trait `App\View\Components\Concerns\Renderable`
para se renderizar em um payload de "updatable slot" consumido por
`resources/js/modules/ajax-slot.js`.

```php
use App\View\Components\Concerns\Renderable;

class Index extends Component
{
    use Renderable;

    public static function slot(): array
    {
        return (new static)->toSlot('catalog-index-slot');
    }

    public function render(): View
    {
        return view('components.catalog.index');
    }
}
```

```php
// Controller — mesma action serve HTML (GET) e JSON (AJAX)
return response()->json([
    'message'        => 'Salvo.',
    'updatableSlots' => [Catalog\Index::slot()],
]);
```

Ver `App\View\Components\Examples\SlotExample` como implementação de
referência e `tests/Feature/RenderableTest.php`. IDs podem ser
pipe-separados para substituir vários nós:
`toSlot('header-widget-slot|sidebar-widget-slot')`.

## Form components

`resources/views/components/forms/`:

| Componente | Observação |
|---|---|
| `x-forms.input`, `x-forms.select`, `x-forms.textarea` | controles base |
| `x-forms.button` | padrão `data-spinner`/`data-label` interno; prop `variant`: `primary` (verde sólido, CTA principal), `glass` (translúcido, ação secundária), `ghost` (transparente, ações de linha) |
| `x-forms.checkbox`, `x-forms.radio`, `x-forms.radio-group` | seleção |
| `x-forms.label`, `x-forms.file` | rótulo / upload de arquivo |
| `x-forms.field` | wrapper label + hint + erro |
| `x-forms.toggle` | booleano (CSS puro, peer) |
| `x-forms.image-upload` | preview/trocar/remover (reusa `avatar-upload.js`) |
| `x-forms.chips` | seleção múltipla com papel (`chips.js`) |

Todos são exercitados em `tests/Feature/FormComponentsTest.php`. Há uma página
de demonstração em `/componentes` (autenticada).

## Layout

Páginas autenticadas usam `<x-layouts.layout>` (sidebar verde + canvas claro,
identidade Leo), que provê os shells permanentes: `#alert-modal`,
`#main-modal`, `#toast-container`, `#side-panel`.

## Sistema de cor "Blocos Leo"

Convenção de cor por **significado**, não decoração: tiles de logo sem
imagem e selos de seção (o bloco mono uppercase tipo "F3") são blocos verdes
sólidos (`bg-accent text-white`); badges de metadados seguem um tom
semântico por dimensão (categoria → verde sólido, ambiente/status → verde
suave, cloud → lima, contrato/criticidade média → âmbar, criticidade
alta/crítica → vermelho). Botões têm 3 variantes por peso (`primary`/
`glass`/`ghost`, ver tabela acima). Cinza fica reservado a papel estrutural
(hovers, trilhas, placeholders, texto terciário). Referência:
`Solutions\DetailHeader`, `resources/views/components/solutions/detail-header.blade.php`.

## Módulos JS

Registrados em `window.globalModules` (`resources/js/app.js`). Convenção de
hooks `data-ak-*`. Antes de criar um módulo novo, verifique se um existente
em `resources/js/modules/` já cobre o comportamento.

## Funcionalidades

- **Catálogo de soluções** (`/solucoes`): listagem com busca/filtros, CRUD via
  side panel. O detalhe de cada solução (`/solucoes/{slug}`) tem um cabeçalho
  (`Solutions\DetailHeader`) com nome/descrição e uma ficha de 8 atributos
  (Categoria, Status, Criticidade, Ambiente, Hospedagem, Contrato, Suporte,
  Diretoria) — cada um **editável inline** por um `<select>` que salva sozinho
  ao trocar de valor (sem botão de salvar), visível só para quem pode editar a
  solução; atributos em branco mostram "Não informado" em vez de sumir da
  ficha.
- **Atributos gerenciáveis em runtime**: os valores de cada um dos 8 grupos
  (`App\Enums\AttributeGroup`) são registros `AttributeOption` editáveis por
  admin em "Gerenciar atributos" (`/atributos`, sempre dentro da `#main-modal`,
  acionado a partir do form de Solução) — criar/renomear/remover um valor sem
  precisar de migration. Os grupos em si (quais 8 existem) são fixos em código.
- **Pessoas e empresas** (`/pessoas`, `/empresas`): listagem, CRUD via side
  panel, vínculo pessoa↔solução por papel via `x-forms.chips`. Pessoa tem um
  par `email`/`phone` simples (colunas em `people`) **e** uma lista separada
  de contatos adicionais (`Person::contacts()`, tipo email/telefone/whatsapp/
  outro) editável na mesma tela via seção repetível "Contatos adicionais".
- **Integrações — sempre a partir de uma solução**: não existe catálogo
  `/integracoes` avulso. No detalhe de cada solução, a seção "Integrações"
  é um bloco de duas colunas — à esquerda, a lista das integrações da
  solução (criar só com um nome, selecionar, excluir); à direita, um canvas
  gráfico (`resources/js/modules/integration-viz.js`) que desenha e edita a
  integração selecionada. A topologia (`Integration.chain =
  {nodes: [{solution_id, label}], edges: [{from, to, arrow, protocol}]}`) é
  a **fonte de verdade única**: `App\Actions\SyncIntegrationFromChain` deriva
  dela os participantes (pivot `integration_solution`, com `position`),
  origem/destino, direção e o protocolo escalar de resumo sempre que a chain
  muda. É um **grafo livre de verdade**, não uma cadeia linear: um bloco pode
  ficar sem nenhuma ligação, `from`/`to` são índices de nó (não posições
  consecutivas), e cada ligação carrega seu próprio sentido (`->`/`<-`/`<->`)
  e protocolo. Editável direto no canvas: título de um nó (exceto o raiz),
  sentido/protocolo de uma ligação, adicionar um bloco novo ao final (ligado
  ou isolado), religar a ponta de uma ligação existente pra outro bloco
  arrastando, criar uma ligação nova entre dois blocos já existentes ("modo
  ligar"), e desligar uma ligação. Posição/cor/comentário de cada bloco no
  canvas são só visuais, persistidos em `Integration.viz_layout` — nunca
  tocam a chain nem os campos derivados.
- **Mapa do ecossistema** (`/mapa`): derivado (somente leitura), layout radial
  hub-and-spoke — cada solução é um hub com seus vizinhos diretos num círculo
  ao redor (`<x-ecosystem-map>`, DOM+SVG, mesmo visual do canvas de integração
  de cada solução; posicionamento por grid empacotado, não por rank — a
  maioria dos clusters é pequena e desconexa entre si). Ligações paralelas
  entre o mesmo par de soluções são deduplicadas em uma só
  (`IntegrationGraphService`); hubs com muitas conexões nascem colapsados
  (badge com a contagem, clique expande/colapsa). Filtros por status/
  categoria/diretoria.
- **Documentação rica (estilo GitBook)**: editor Editor.js persistido como
  Markdown + notação estendida GitBook (`hint`, `tabs`, `embed`, imagens com
  preset de largura) numa coluna `documentation` só de texto — sem tabela de
  blocos separada. Suporta upload/colar/arrastar imagem e arquivo, âncoras de
  heading e atalhos de Markdown (`## `, `- `, `> `, ` ``` `). Três contêineres:
    - **Solução** (`/solucoes/{slug}/documentacao`): árvore **plana** de 1..N
      páginas (`DocumentationPage`) — criar/renomear/mover/excluir; a rota-índice
      resolve (ou cria) a primeira página e redireciona pra ela.
    - **Integração** (`/solucoes/{slug}/integracoes/{slug}/documentacao`): uma
      página única.
    - **Grupos "Aninhamentos"** (`/documentacao/grupos/{group}`): árvores de
      páginas *standalone*, fora de qualquer solução.
  Um **link público** ("magic link", só para Solução) expõe a doc dela — e a de
  cada integração dela — sem exigir login. Renderização read-only via
  `App\Support\GitbookRenderer`.
- **Assiste IA (rascunho por LLM)**: em qualquer página de doc de Solução ou na
  doc de uma Integração, um painel lateral coleta um prompt + **documentos de
  contexto** (coleção `context_documents` por Solução — PDF/imagem/texto,
  compartilhados entre as páginas dela e as docs das suas integrações) + o
  Markdown atual do editor, e gera um rascunho via job assíncrono
  (`GenerateDocumentationDraft`) com polling — carregado no editor para revisão,
  nada é salvo automaticamente. Textos entram embutidos no prompt (com orçamento
  de caracteres); PDFs/imagens vão como anexos nativos ao modelo (`laravel/ai`).
  Uma geração por alvo de cada vez (`WithoutOverlapping`).
- **Gerador de flowSpec Digibee** (`/flowspec`): chat que gera o JSON de
  flowSpec a partir de um pedido em linguagem natural. Contexto **sem RAG** —
  Solutions citadas (explícitas via chips, ou inferidas casando o nome no
  texto), documentação recortada por orçamento de caracteres (páginas das
  Solutions + documentação das integrações em que participam) e 2-3 exemplos de
  um corpus curado por tags (`FlowspecExample`). A resposta é gerada em job
  assíncrono (`GenerateFlowspecReply`, uma vez por thread via
  `WithoutOverlapping`) com polling do thread; um loop **normaliza/valida** o
  JSON e re-prompta com os erros concretos até `max_attempts`. Respostas
  conversacionais (dúvidas) sugerem documentação real que possa faltar. O
  resultado pode ser **anexado a uma integração** (painel read-only na lista de
  integrações da solução) ou **promovido ao corpus** como novo `FlowspecExample`.
  Um `CredentialScrubber` barra segredo literal no que é gerado/promovido.
- **Hub de Documentação** (`/documentacao`): visão gerencial transversal do
  que está documentado e do que falta — soluções **e** integrações, cada uma
  com um selo por **conteúdo real** (não um flag manual), agrupadas por
  solução, com busca e filtro por tipo/status. Contadores agregados calculados
  em `DocumentationCoverageService`. Substitui o antigo painel de cobertura
  por 3 toggles booleanos (`has_macro_architecture` e afins — aposentados).

## Notas técnicas não óbvias

- **Erros de validação não seguem o shape padrão do Laravel.** `bootstrap/app.php`
  reformata `ValidationException` para `{message, title, type}` (sem `errors`),
  para casar com o padrão de Toast/Modal do frontend. `assertJsonValidationErrors()`
  não funciona nos testes deste projeto — use `assertStatus(422)` + confira `message`.
  Ver `CLAUDE.md` § Error Handling.
- **Strict mode ligado fora de produção** (`Model::shouldBeStrict()`). Acessar uma
  relação não carregada lança exceção em vez de silenciosamente disparar uma
  query. Ver `CLAUDE.md` § Eloquent para o padrão de `setRelation()` quando um
  componente filho precisa de uma relação que o pai já tem em mãos.
- **A cadeia (`chain`) é a única fonte de verdade da topologia de integração**
  — nunca escrever `participants`/`source_solution_id`/`target_solution_id`/
  `direction` diretamente; editar `chain` e deixar `SyncIntegrationFromChain`
  rederivar. `Integration.viz_layout` é posição/estilo do canvas, sem nenhum
  efeito colateral em topologia. Ver `CLAUDE.md` § Integration topology
  invariant.
- **Integrações vivem só a partir da solução.** Não há módulo `/integracoes`
  avulso (catálogo/detalhe) nem editor de diagrama em página própria — criar,
  editar topologia, renomear e mudar status acontecem todos no bloco
  "Integrações" do detalhe da solução. As rotas `solutions.integrations.*`
  usam `scopeBindings`, então `{integration}` precisa pertencer à `{solution}`
  da URL.
- **Busca e filtros de Soluções/Pessoas/Empresas** rodam via
  `execute-filters.js`/`execute-search.js` sobre `ajax.js` (contrato Promise
  baseado em `fetch`, não `XMLHttpRequest`) — ver `CLAUDE.md` § `ajax.js`.
- **As duas features de IA (Assiste IA e flowSpec) refletem o job por
  polling, nunca broadcasting.** O front dispara a geração, recebe uma URL de
  status e faz polling até o registro sair de `pending` (com teto de tentativas
  + Toast de desistência). O endpoint de status fica barato enquanto pende:
  só monta o slot/resultado quando a resposta chegou, não a cada tick. Ver
  `CLAUDE.md` § Queue & Jobs.

## Testando

```bash
composer test
```

Cobertura relevante: `IntegrationChain*Test` (edição de nó/ligação/protocolo/
adicionar/remover na chain), `IntegrationLayoutSaveTest` (persistência de
`viz_layout` sem tocar topologia), `SolutionAttributeInlineUpdateTest` (edição
inline dos 8 atributos), `PersonContactsSyncTest` (sincronização de contatos
adicionais), `DocumentationTest` (editor de blocos de Solução/Integração),
`PublicDocumentationTest` (magic link), `DocumentationCoverageTest` (hub de
documentação, content-based), `DocumentationAiAssistTest`/
`DocumentationDraftServiceTest` (Assiste IA — job, polling e montagem do
prompt), `FlowspecChatTest`/`FlowspecContextResolverTest`/
`FlowspecGenerationServiceTest` (gerador de flowSpec — chat, resolução de
contexto e loop de normalização/validação), `ColorRefactorTest`,
`PageCrawlSmokeTest` (crawl de todas as páginas seedadas).
