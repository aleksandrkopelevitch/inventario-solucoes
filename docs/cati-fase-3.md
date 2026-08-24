# CATI — Fase 3: os desenhos da submissão

Continuação de `cati-fase-1.md` (registro, checklist, entrevista, saídas em
texto) e `cati-fase-2.md` (o deck). Aqui a submissão passa a ter **arquitetura
própria** em vez de emprestar a do catálogo.

## Estado

| Peça | Situação |
|---|---|
| `App\Contracts\ChainCanvas` + `Concerns\EditsChain` (canvas com dois donos) | **feito** |
| `App\Support\ChainGraph` (payload do canvas, sem dono fixo) | **feito** |
| `SubmissionDiagram` + enum de 4 tipos + migration | **feito** |
| 14 rotas `submissions/{submission}/diagrams/{diagram}/…` | **feito** |
| Aba "Diagramas" + página do canvas | **feito** |
| Quatro slides no deck, na ordem do comitê | **feito** |
| Item "Diagramas de arquitetura anexados" derivado | **feito** |
| 19 testes (`tests/Feature/CatiSubmissionDiagramTest.php`), suíte em 877 | **feito** |

## O problema que isto resolve

A Fase 2 já punha diagrama no deck: um slide por integração da Solution ligada,
com a imagem que o canvas F3 publica a cada "Salvar". O que faltava não era
render — era **de quem é o desenho**.

- Uma submissão é uma **proposta**. O comitê delibera sobre o que vai mudar, e
  não havia onde desenhar o TO BE. O deck só sabia mostrar como o catálogo está
  hoje.
- Submissão **sem Solution do catálogo** — sistema novo, que é a maior parte do
  que chega ao comitê — não tinha diagrama nenhum.
- `RenderTicketText::DIAGRAMS_ITEM` saía permanentemente desmarcado, com um
  docblock dizendo que ficaria assim "até a Fase 3 poder responder honestamente".

## Decisões

### Dois desenhados, dois enviados — e isso não é preguiça

O checklist do comitê pede "desenho da solução e C4 com mínimo C1/C2". As duas
metades são respondidas de formas diferentes de propósito
(`App\Enums\SubmissionDiagramKind`):

- **AS IS / TO BE são DESENHADOS** no mesmo canvas F3 das integrações, porque
  são topologia — a coisa que este inventário já modela e já renderiza. Aceitar
  como imagem daria à arquitetura da proposta nenhuma casa além de um PNG:
  editável em lugar nenhum, comparável com nada, velho no instante em que algo
  se move.
- **C4 C1/C2 são ENVIADOS**, porque C4 é uma notação e o canvas é um grafo
  livre. Dobrar um no outro seria inventar uma notação e chamá-la de C4 — pior
  que aceitar o desenho que o arquiteto já fez numa ferramenta que fala aquilo.

### O canvas ganhou um segundo dono sem uma linha de JS

`integration-viz.js` tem ~4.500 linhas e **nenhuma rota própria**: cada endpoint
que ele chama chega dentro do payload do grafo (`nodeAddUrl`, `edgeRetargetUrl`,
`saveUrl`, …). Foi isso que tornou o segundo dono barato — o cliente nunca
descobre que existe.

O lado servidor virou o mesmo desenho: `ChainCanvas` (o contrato),
`Concerns\EditsChain` (as nove mutações, uma vez só) e `ChainGraph` (o payload).
`IntegrationsMap::graph()` passou a delegar; as 121 provas do canvas de
integração passaram sem alteração nenhuma, que é o que diz que a extração não
mudou comportamento.

**A diferença entre os dois donos é um método**, `afterChainMutation()`:
`Integration` re-deriva participants/source/target/direction ali;
`SubmissionDiagram` não deriva nada. Uma proposta é uma coisa em discussão — e
pode ser reprovada. Deixar uma reprovada escrever no catálogo é exatamente a
deriva que este módulo existe para eliminar. Um teste trava isso.

### O canvas tem página própria, não um painel na aba

Pan/zoom, barra de ferramentas, tela cheia: 340px de uma aba não é lugar onde
alguém desenha arquitetura. A aba "Diagramas" mostra os quatro slots com estado
e miniatura; desenhar acontece em
`submissions/{submission}/diagrams/{diagram}`, como acontece para uma
integração.

### AS IS da submissão suprime os canvases do catálogo no deck

Se a submissão desenhou o próprio AS IS, os slides "Arquitetura — {integração}"
somem. Os dois respondem "como funciona hoje" em altitudes diferentes, e
imprimir ambos convida o comitê a procurar a diferença em vez de ler a proposta.

## Armadilhas pagas

- **Uma FormRequest compartilhada não pode resolver o dono pelo NOME do
  parâmetro.** `AddChainEdgeRequest` fazia `$this->route('integration')` dentro
  das *regras* para calcular o índice máximo de nó; com um `SubmissionDiagram`
  aquilo é null, `max` vira 0 e toda ligação além da raiz é rejeitada como
  "fora do intervalo". Falha no caminho que funciona, que lê como canvas
  quebrado, não como permissão faltando. Resolve-se por TIPO
  (`Concerns\AuthorizesChainOwner::chainOwner()`), e o mesmo vale para
  `RetargetChainEdgeRequest`.
- **`chainUrls()` tem que usar o MODELO, não o id.** A route key de `Submission`
  é o slug, então `$this->submission_id` produzia `/submissions/1/...` — que não
  casa binding nenhum e dá 404 em toda chamada do canvas. Invisível em teste que
  monta a URL com `route()`: o único lugar onde a URL errada existia era dentro
  do payload que o cliente lê. Só um teste que compara a URL RENDERIZADA pega.
- **Um canvas com só a raiz não está desenhado.** `SubmissionDiagram::isFilled()`
  exige mais de um nó, porque a raiz é semeada na criação — contar a raiz faria
  o checklist do comitê marcar para toda submissão que um dia abriu a aba.

## Verificado no navegador

O canvas de verdade, contra um `SubmissionDiagram`, sem alteração de JS:
adicionar bloco, renomear inline, salvar e publicar o PNG —
`POST /chain/nodes`, `PATCH /chain/nodes/1`, `PATCH /layout`,
`POST /picture`, todos 200, nenhum erro de console. A aba mostra "Pronto" e a
miniatura do canvas capturado.

## Fora de escopo — e uma consequência que não era esperada

- Promover o TO BE aprovado de volta para o inventário. **Isto virou trabalho
  imediato**, não Fase 4 distante: dar desenhos próprios à submissão reabriu um
  buraco que `cati-fase-4.md` declarava fechado ("a topologia já está promovida
  no instante em que é desenhada" — verdade enquanto o desenho era o canvas
  vivo do inventário). Fechado em 2026-08-24 por `ApprovedTopology`; ver a
  seção correspondente em `docs/cati-fase-4.md`. O `afterChainMutation()` vazio
  continua certo: proposta não escreve no catálogo — **aprovação** escreve, e é
  outro momento.
- Comparar AS IS × TO BE (um diff de topologia). O modelo permite — os dois são
  chains no mesmo formato —, mas ninguém pediu ainda.
