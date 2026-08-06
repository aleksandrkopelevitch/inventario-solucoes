# Inventário de Soluções

Aplicação Laravel standalone para catalogar as soluções e integrações da Leo
Madeiras: cadastro de soluções/pessoas/empresas, um editor gráfico de
topologia de integrações por solução, um mapa read-only do ecossistema
completo, documentação rica (estilo GitBook) para soluções e integrações — com
um assistente de IA que gera rascunhos —, um hub que reúne a cobertura dessa
documentação, e um Especialista em Integrações que gera flowSpec Digibee em
formato de chat.

A base de infraestrutura genérica (form components, sistema de slots, módulos
JS, shells de layout, autenticação) foi portada do projeto de referência
**akop-pro**. O domínio legado daquele projeto (CRM Customer/Organization/
Interaction, análise DISC, extensão Chrome, multi-tenancy/RLS) **não** faz
parte deste projeto.

## Stack

- **Backend:** Laravel 13+, PHP 8.3+, SQLite (dev) / PostgreSQL (prod)
- **Frontend:** Blade, Vanilla JS (sem jQuery), Tailwind CSS 4
- **Build:** Vite 8 (requer **Node 20+**)
- **Auth:** sessão web, sem self-registration (contas só por convite de admin)
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

`App\Enums\UserRole`: **viewer**, **admin**. Não existe self-registration —
toda conta é criada por um admin na área "Usuários" (menu do usuário na
sidebar, `App\Http\Controllers\UserController`), que envia um convite por
e-mail; a pessoa convidada define a própria senha pelo fluxo de reset de
senha já existente (`Password::createToken()`/`ResetPasswordController`),
sem um sistema de token separado. O primeiro admin vem do
`DatabaseSeeder` (`admin@leomadeiras.com.br`).

## Arquitetura em resumo

O app é **server-authoritative** ("HTML over the wire"): o servidor renderiza o
HTML — inclusive as **atualizações parciais** — e o cliente só troca pedaços já
prontos. **Não há framework de renderização no cliente** (React/Vue). A UI é
Blade + JS vanilla organizado em módulos. Isso é uma decisão deliberada, não
falta de ferramenta: o app é majoritariamente CRUD/catálogo (formulários,
listas, filtros) com round-trip ao servidor por ação — cenário em que um SPA
adicionaria hidratação, bundle e um segundo modelo mental sobre o Blade sem
ganho. As duas telas de fato interativas (o canvas de integração e o mapa do
ecossistema) são SVG/DOM imperativo, que um framework tampouco simplificaria.
Se um dia surgir reatividade local complexa, o caminho é **Alpine.js** (ilha
declarativa) ou **Livewire** (componente server-driven) — ambos preservam este
modelo —, nunca um rewrite em SPA.

**Ciclo de uma requisição que muta dados:**

```
Ação do usuário
  → <x-forms.button data-ak-ajax> dispara POST (ajax-post.js, via ajax.js/fetch)
  → Rota nomeada → Controller fino
       ├─ Form Request valida a entrada (nunca $request->validate() inline)
       ├─ Service (lógica ampla) / Action (operação única, DI no construtor)
       └─ response()->json({ message, type, updatableSlots: [Componente::slot()] })
  → ajax-slot.js troca os nós por id e re-inicializa os módulos JS
  → Toast/Modal refletem message/type
```

A mesma action serve HTML (GET normal) e JSON (AJAX) decidindo por
`$request->wantsJson()` — nunca há uma action separada só para o AJAX.

**Pilares que se repetem no código** (regras completas em `CLAUDE.md`):

- **Updatable slots** — primitivo de "reatividade": um Componente de View
  renderiza um trecho, o controller o devolve após a mutação, o cliente
  substitui por id. Ver abaixo.
- **Controllers finos** — lógica ampla em Services, operações únicas em Actions;
  validação sempre em Form Requests; toda mutação passa por `authorize()`/Policy.
- **Fonte de verdade única por domínio** — a topologia de integração vive só na
  `chain`; colunas derivadas são reconstruídas por uma Action, nunca escritas à
  mão (ver "Notas técnicas").
- **Assíncrono por job + polling** — features de IA disparam um job e o front
  faz polling do status até sair de `pending`; sem broadcasting.
- **Strict mode do Eloquent** fora de produção — relação não carregada lança
  exceção em vez de lazy-load silencioso (ver "Notas técnicas").

## Sistema de Slots (atualização parcial via AJAX)

**O problema que resolve.** Sem framework de cliente (§ Arquitetura em
resumo), como uma lista/widget volta a refletir o servidor depois de um
create/edit feito num side panel — sem recarregar a página inteira e sem
duplicar a lógica de montagem daquele HTML no JS? A resposta é o *updatable
slot*: um trecho de HTML com um `id` estável que **o servidor sabe
re-renderizar isoladamente** e **o cliente sabe trocar no lugar**. É o
primitivo de "reatividade" do app — reatividade dirigida pelo servidor, não
por um estado espelhado no cliente.

**Por que a lógica de render mora num View Component — e nunca no Model.**
Um Model descreve *dados e regras de domínio*; ele não deve saber que existe
uma grade de cards nem qual `id` de DOM aquele HTML ocupa. Amarrar
`render()`/`slot()` no Model acoplaria o domínio a uma decisão de
apresentação e tornaria impossível ter duas visões diferentes do mesmo
registro. O View Component é exatamente a *costura* entre um pedaço de dado e
**um pedaço específico de HTML** — e, por ser uma classe, ele pode ser
instanciado e renderizado no servidor **fora do fluxo de uma página inteira**,
que é o que a atualização parcial exige. Por isso o método que produz o slot
vive no componente, junto do markup que ele possui, e **nunca** no Model.

**Por que uma trait (`Concerns\Renderable`) e não uma classe-base.** Todo
View Component já estende `Illuminate\View\Component`; não dá para inserir uma
classe-base nossa nessa cadeia sem reescrever a hierarquia do framework. A
capacidade "sei me empacotar como slot" é **ortogonal** ao que o componente é
— vale para uma grade, um cabeçalho, um contador, um chip. Isso é
precisamente o caso de uma trait: composição *horizontal* de um comportamento
compartilhado sobre classes que não têm (nem devem ter) ancestral comum. Ver
`## Concerns (traits)` abaixo para o padrão geral.

**Por que `slot()` é `static`.** O controller devolve `Componente::slot()`
sem precisar conhecer o construtor nem o estado interno do componente — o
contrato é só "me dê `{id, content}` fresco". E, decisivo, **o `id` do DOM
mora dentro do componente que possui aquele markup**, não espalhado por N
controllers; trocar o `id` é uma edição num arquivo só, e nenhum controller
fica sabendo.

**Como funciona, ponta a ponta.** `toSlot($id)` chama
`Blade::renderComponent($this)` para renderizar o componente a uma string e
devolve `['id' => $id, 'content' => $html]`. O controller põe isso em
`updatableSlots`; `resources/js/modules/ajax-slot.js` acha o nó por `id`,
substitui o `outerHTML` e chama `window.initAllModules()` para religar os
hooks `data-ak-*` do HTML novo (§ Frontend). Um `id` que não existe na página
atual é silenciosamente ignorado — por isso é seguro devolver sempre todos os
slots onde o registro *poderia* aparecer (índice **e** cabeçalho de detalhe).

```php
use App\View\Components\Concerns\Renderable;

class Index extends Component
{
    use Renderable;

    // static: o controller chama Index::slot() sem conhecer o componente por dentro;
    // o id do DOM ('catalog-index-slot') mora aqui, junto do markup que ele possui.
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
pipe-separados para substituir vários nós com o **mesmo** HTML
(`toSlot('header-widget-slot|sidebar-widget-slot')`); para devolver slots
*diferentes* de uma mesma mutação (grade + contador + chips, ou índice +
cabeçalho de detalhe), retorne vários itens no array — ver `CLAUDE.md`
§ "Multiple *different* slots from one mutation".

## Concerns (traits) — composição horizontal de comportamento

`Concerns` é onde vivem as traits que **compartilham uma capacidade entre
classes que não têm ancestral comum**. Sempre que dois ou mais pontos do
mesmo tipo (dois View Components, dois controllers, dois Form Requests)
precisam do *mesmo* comportamento, mas herança seria errada — porque a
classe-base já é do framework (`Component`, `Controller`, `FormRequest`) e
porque o comportamento é ortogonal ao que cada classe *é* —, ele nasce como
trait em um subdiretório `Concerns`. É o mesmo raciocínio do
`Illuminate\...\Concerns` do próprio Laravel. Onde o app usa isso hoje:

- **`App\View\Components\Concerns\Renderable`** — "sei me empacotar como
  updatable slot" (`toSlot()`). Aplicável a qualquer componente que apareça
  num slot; ver § acima.
- **`App\Http\Controllers\Concerns\*`** (`EditsDocumentation`,
  `AssistsDocumentation`, `NavigatesSolutionDocs`) — a mesma tela de
  documentação serve **Solução e Integração**, mas cada uma resolve seu
  próprio model, rotas e breadcrumb. A trait guarda o que é idêntico (montar
  a página do editor, salvar o Markdown, subir mídia, o painel e o polling do
  Assiste IA, a árvore de navegação consolidada) e recebe do controller só o
  que difere. Assim `SolutionDocumentationController` e
  `IntegrationDocumentationController` renderizam a mesma coisa sem um
  herdar do outro nem uma classe-base artificial.
- **`App\Http\Requests\Concerns\ParsesFlowspecContextInput`** — regras de
  validação + parsing do contexto (solutions citadas, refs de documento)
  reaproveitados por mais de um Form Request do flowSpec.

Quando é herança e não trait? Quando há mesmo uma relação "é-um" e um estado/
contrato comum a compartilhar — mas nesta base isso quase não aparece: a
regra prática é **traits em `Concerns` para capacidade compartilhada,
Services/Actions para lógica de negócio** (§ abaixo).

## Services e Actions — quando cada um

Controllers são finos de propósito (§ Arquitetura). A lógica sai deles para
dois lugares, por *forma* e não por tamanho:

- **Action** = uma operação única, com um verbo, invocada de vários gatilhos.
  Classe com um `handle()` e DI no construtor. `App\Actions\
  SyncIntegrationFromChain` (rederiva as colunas da topologia a cada mutação
  da `chain`) e `PromoteFlowspecExample` (promove uma resposta ao corpus) são
  os exemplos: cada uma faz *uma* coisa, chamada de vários controllers/pontos,
  e por isso merece um objeto testável e injetável em vez de um método privado
  repetido.
- **Service** = lógica *ampla* de um subdomínio, coordenando mais de um passo
  ou consulta. `DocumentationCoverageService` (agrega cobertura por conteúdo
  real), `IntegrationGraphService` (monta/deduplica o grafo do ecossistema),
  os `Services\Documentation\*` e `Services\Flowspec\*`. Não é "um verbo" — é
  um conjunto coeso de operações de uma área.

Regra de bolso: **se você diria o nome com um verbo no imperativo
("Sincroniza…", "Promove…"), é Action; se diria com um substantivo de área
("…de cobertura", "…do grafo"), é Service.**

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

## Frontend — módulos JS, delegação e reatividade

Todo o JS vive em `resources/js/modules/` e é registrado em
`window.globalModules` (`resources/js/app.js`). Comportamentos são acionados por
atributos `data-ak-*` no HTML (o `app.js` traz a tabela completa dos hooks).
Antes de criar um módulo novo, confira se um existente já cobre o caso.

**Dois formatos de módulo.** Cada módulo exporta uma função `init()`, mas há
duas famílias:

1. **Delegação pura (a maioria).** O listener é anexado **uma vez** ao
   `document` no carregamento do módulo, fora do `init()`, e casa o alvo com
   `e.target.closest('[data-ak-…]')`. Como o listener é global, ele já pega
   HTML inserido depois — então `init()` é um **no-op** (existe só para manter a
   interface de `globalModules`). Exemplos: `toggle`, `chips`, `side-panel`,
   `execute-filters`.

   ```js
   document.addEventListener('click', (e) => {
       const trigger = e.target.closest('[data-ak-my-thing]')
       if (!trigger) return
       // ...
   })
   export function init() {} // no-op
   ```

2. **Init por-elemento (quando é preciso montar uma instância de biblioteca).**
   O `init()` varre `querySelectorAll('[data-ak-…]')` e monta cada elemento —
   mas precisa ser **idempotente**, porque `init()` roda de novo a cada troca de
   slot (ver adiante). A guarda é um **`WeakSet`** de elementos já
   inicializados. Usado por `docs-editor`, `integration-viz`, `ecosystem-map`,
   `tabs`.

   ```js
   const initialized = new WeakSet()
   export function init() {
       document.querySelectorAll('[data-ak-docs-editor]').forEach((el) => {
           if (initialized.has(el)) return  // já montei este elemento → pula
           initialized.add(el)
           mount(el)                          // monta só uma vez
       })
   }
   ```

   **Por que `WeakSet` e não `Set`?** Ele guarda os elementos por referência
   **fraca**: quando uma troca de slot substitui aquele nó, o elemento antigo
   fica inalcançável, o garbage collector o coleta **e a entrada some do
   `WeakSet` sozinha** — sem limpeza manual e sem vazamento. Um `Set` comum
   "prenderia" cada nó já visto na memória para sempre. O elemento **novo** (que
   é outro objeto) não está no set → é montado normalmente, que é o desejado.
   O `WeakSet` também mantém esse flag fora do DOM (nada de `data-initialized`).

**Re-inicialização após troca de slot.** Quando `ajax-slot.js` substitui os nós
de um `updatableSlots`, o HTML novo pode conter hooks `data-ak-*` que precisam
de init. Como o swapper é **genérico** (não sabe o que veio no HTML), ele chama
`window.initAllModules()`, que roda o `init()` de **todos** os módulos — barato
porque os de delegação são no-op e os de init-por-elemento são guardados por
`WeakSet`. Para re-init dirigido em fluxos onde você **sabe** o conteúdo
injetado, existe `window.initListOfModules(['toggle', 'chips'])`; use-o em
código próprio, mas **não** troque o `initAllModules` do swapper genérico por
uma lista fixa — qualquer hook fora dela quebraria em silêncio.

**Contrato do `ajax.js`.** `ajaxModule.init(method, url, formData?)` é
`fetch`-based e retorna uma **`Promise<Response>`** (rejeita em `!ok`); não tem
API de `XMLHttpRequest` (`.onload`/`.send()`). Trate sempre como Promise
(`.then/.catch` ou `await`). Detalhes e armadilhas em `CLAUDE.md`.

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
- **Mapa do ecossistema** (`/map`): derivado (somente leitura), layout radial
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
- **Especialista em Integrações** (`/flowspec`): chat que gera o JSON de
  flowSpec Digibee a partir de um pedido em linguagem natural. Contexto **sem
  RAG** — Solutions citadas (explícitas via chips, ou inferidas casando o nome
  no texto), documentação recortada por orçamento de caracteres (páginas das
  Solutions + documentação das integrações em que participam) e 2-3 exemplos de
  um corpus curado por tags (`FlowspecExample`). A resposta é gerada em job
  assíncrono (`GenerateFlowspecReply`, uma vez por thread via
  `WithoutOverlapping`) com polling do thread; um loop **normaliza/valida** o
  JSON e re-prompta com os erros concretos até `max_attempts`. Respostas
  conversacionais (dúvidas) sugerem documentação real que possa faltar. O
  corpus de referência (`FlowspecExample`) é curado à parte, num modal de
  "Gerenciar referências" (admin, `FlowspecExampleController`) — não a partir
  do resultado de uma conversa. Um `CredentialScrubber` barra segredo literal
  tanto no que é gerado (o documento é descartado se um literal sobreviver a
  todas as tentativas do loop) quanto no que é cadastrado no corpus. O
  catálogo de componentes permitidos (`database/data/digibee_component_catalog.json`,
  validado por `DigibeeFlowspecValidator`) é curado à mão a partir do que é
  usado de verdade nos pipelines Digibee da Leo Madeiras — ver "Notas
  técnicas" para a ferramenta que audita esse catálogo contra produção.
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
- **As duas features de IA (Assiste IA e Especialista em Integrações) refletem o job por
  polling, nunca broadcasting.** O front dispara a geração, recebe uma URL de
  status e faz polling até o registro sair de `pending` (com teto de tentativas
  + Toast de desistência). O endpoint de status fica barato enquanto pende:
  só monta o slot/resultado quando a resposta chegou, não a cada tick. Ver
  `CLAUDE.md` § Queue & Jobs.
- **O catálogo de componentes do flowSpec (Especialista em Integrações) pode
  ficar desatualizado em silêncio.** `digibee_component_catalog.json` é um
  arquivo estático e versionado — de propósito: é o que faz a geração ser
  reprodutível e testável, então nunca deve ser buscado ao vivo durante uma
  geração. `flowspec-catalog-audit.php` (raiz do repo, dev-only — **não** é
  um comando artisan, não roda em CI/produção) audita esse arquivo contra o
  uso real em pipelines de produção, via `digibeectl` (CLI oficial da
  Digibee) — mas fica **fora** do servidor de produção de propósito: a
  credencial interativa do `digibeectl` tem escopo de
  criar/deletar deployment em produção, então essa auditoria roda só na
  máquina do dev, nunca com uma credencial desse alcance dentro do app. O
  script só imprime um relatório (nomes de connector/step type usados de
  verdade vs. cadastrados) — nunca escreve no catálogo nem no corpus sozinho;
  qualquer novo connector ainda precisa de um exemplo curado à mão em
  `database/data/digibee_flowspec_examples/` antes de ser realmente
  utilizável pelo gerador (nome no catálogo só desbloqueia o validador, não
  ensina o formato dos parâmetros).
- **`digibee-connection-monitor.flowspec.json` (raiz do repo) é uma pipeline
  Digibee standalone, gerada por IA — não faz parte do runtime do Laravel,**
  no mesmo espírito do `flowspec-catalog-audit.php` acima (fica no working
  tree, fora do app). Testa cada URL de `global.isol-monitor-targets` em
  paralelo (`for-each-connector`), grava o resultado por endpoint via upsert
  em Object Store (`isol-monitor-results`, chave estável por endpoint — evita
  corrida entre iterações paralelas e nunca cresce sem limite, diferente de
  um acumulador em sessão) e, ao final da rodada, monta um relatório único
  (HTML + Teams) num `script-connector`, notificando e-mail e Teams só
  quando há pelo menos um endpoint fora da faixa 200-299 — nunca um
  disparo por endpoint. Segredo (URL do webhook Teams, destinatários,
  conta SMTP) vive em global vars/Accounts do próprio Digibee, nunca no
  JSON, mesma disciplina do `CredentialScrubber` do F8. O corpo enviado ao
  Teams é o genérico `{title, text}`, consumido por um Workflow do Power
  Automate (gatilho "Quando uma solicitação HTTP do Teams webhook é
  recebida", schema gerado a partir desse payload de amostra, seguido de uma
  ação "Postar em um chat ou canal") — a URL do webhook gerada por esse
  Workflow é o valor de `global.isol-monitor-teams-webhook-url`. Todo recurso
  específico deste monitor (global vars, accounts, object store) usa o
  prefixo/infixo `isol`, para não colidir com nomes de outras pipelines do
  mesmo tenant Digibee — exceção: `dgb-internal-object-store-account`, que é
  compartilhado e reaproveitado de outras pipelines reais do tenant, não
  renomeado. Além do loop genérico sem-auth, o flow tem 5 branches
  dedicados e autenticados — SAP (`sap-connector` RFC, leitura `T000`),
  SVL e Viasoft/Construshow (replicam o handshake de login real de
  `get-token-svl`/`get-token-viasoft`), BigQuery (REST autenticado, não o
  `google-bigquery-sql-connector` do catálogo — a única pipeline real com
  esse connector é um rascunho vazio) e SCL/SAP S4 (reachability do host
  apenas, via `block-execution-connector`, para não disparar o
  `CriaPedido` real a cada rodada) — cada um reaproveita a conta de
  produção real do sistema (`sap-user`, `svl-auth`, `bigquery-auth`,
  `viasoft-auth`, `sap-s4-scl`), mesma exceção de não-renomeação do
  `dgb-internal-object-store-account`. Passa limpo pelo
  `DigibeeFlowspecValidator` do F8 (mesma validação do corpus), mas isso
  não substitui testar de verdade dentro do Studio — o agendamento
  (Schedule trigger) é configurado lá, fora deste arquivo. Checklist
  completo de setup (o que criar na Studio, valores, Power Automate) em
  `digibee-connection-monitor.md`; levantamento das conexões reais por
  sistema em `digibee-real-connections-inventory.md`.

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
`FlowspecGenerationServiceTest` (Especialista em Integrações — chat, resolução de
contexto e loop de normalização/validação), `ColorRefactorTest`,
`PageCrawlSmokeTest` (crawl de todas as páginas seedadas),
`UserInvitationTest` (convite de usuário por admin + fluxo de definir senha),
`AuthenticationTest` (login/throttle/reset de senha — sem self-registration).
