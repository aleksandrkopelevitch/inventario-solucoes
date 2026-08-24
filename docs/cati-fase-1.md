# CATI — Fase 1: registro + entrevista, com saída em texto

Plano de implementação da Fase 1 do módulo de submissões ao Comitê de
Arquitetura de TI. Contexto e justificativa das fases estão na proposta
publicada em `https://claude.ai/code/artifact/6dc2f76a-eccc-41e5-9946-e32976070ef4`.

**A Fase 1 não gera `.pptx`.** Ela entrega o registro estruturado, a coleta de
material, o checklist determinístico, a entrevista por chat e duas saídas em
texto: o documento em Markdown e o texto pronto do chamado no Leo Resolve.
O deck é a Fase 2 — construir o exportador antes de o modelo de conteúdo estar
provado é o erro clássico deste tipo de projeto.

---

## Estado

| Bloco | Situação |
|---|---|
| A — enums, migrations, models, policy, factories | **feito** (2026-08-18, branch `feat/cati-submission-model`, 18 testes em `tests/Feature/CatiSubmissionModelTest.php`) |
| B — ingestão de material | **feito** (14 testes em `tests/Feature/CatiSourceIngestionTest.php`) |
| C — checklist determinístico | **feito** (15 testes em `tests/Feature/CatiRequirementsTest.php`) |
| D — saídas em texto | **feito** (10 testes em `tests/Feature/CatiRenderTest.php`) |
| HTTP + Blade | **feito** (22 testes em `tests/Feature/CatiSubmissionHttpTest.php`) — 18 rotas, 5 controllers, 7 View Components com slot, 2 páginas, `cati-chat.js`, entrada na sidebar |
| E — entrevista | **feito** (15 testes em `tests/Feature/CatiChatServiceTest.php`) |

**A Fase 1 está completa** — 96 testes, e o app inteiro passa (641).
`/submissions` está na sidebar em Governança.

Para rodar localmente: `php artisan migrate` (**nunca** `migrate:fresh`) e
`composer dev`. Sem um worker de fila rodando, a entrevista fica em "preparando
a próxima pergunta…" até o teto do polling — é o `composer dev` que sobe o
worker junto.

Duas armadilhas encontradas fechando a camada HTTP:

- **`scopeBindings()` só funciona quando o pai tem a relação correspondente.**
  `submissions/{submission}/chat/messages/{message}/apply` estava dentro do
  grupo escopado e dava 500: o Laravel tenta resolver `{message}` por
  `Submission::messages()`, que não existe nem deveria — uma mensagem está a
  dois saltos (submission → chat → message). A rota saiu do grupo e o
  controller confere a posse explicitamente.
- **Um `factory()` que deriva o slug de uma variável local ignora o override do
  teste.** `Submission::factory()->create(['name' => 'CATI SKBridge'])` gerava
  o slug a partir do nome aleatório da própria factory. O slug agora é uma
  closure sobre os atributos já resolvidos. Rode `php artisan migrate` para criá-las no banco de desenvolvimento
(**nunca** `migrate:fresh`).

Uma decisão do Bloco D que é de correção, não de estilo:

- **O item "Diagramas de arquitetura anexados" do checklist nunca é marcado
  automaticamente.** Ele afirma que ANEXOS existem (desenho da solução + C4
  C1/C2), não que a seção foi escrita. Na Fase 1 não há diagrama nenhum, então
  derivá-lo do estado da seção `architecture` colocaria uma afirmação de
  conformidade falsa na frente do comitê. Saiu sempre desmarcado até a Fase 3
  (2026-08-23), que deu à submissão os desenhos em si — agora é derivado de
  quantos dos quatro slots estão preenchidos
  (`SubmissionRequirements::diagramsComplete()`), e continua sendo uma
  afirmação sobre ANEXOS: não marca porque a seção de arquitetura ficou boa.
  Ver `docs/cati-fase-3.md`.

Duas decisões tomadas no Bloco C que valem mais que o código:

- **Uma regra de desvio só dispara quando é especificamente interessante.** As
  regras baseadas em palavra-chave exigem que a seção já tenha conteúdo: numa
  submissão nova tudo está em branco, e disparar todas soterraria as duas que
  importam sob sete que só repetem "nada preenchido" — coisa que o checklist de
  seções já diz. Disparam em branco apenas as regras em que o vazio é
  *surpreendente* dado o que o catálogo sabe (integrações existentes sem
  impacto descrito) ou em que o formulário se cala mas o comitê pergunta sempre
  (alternativas avaliadas).
- **Um fato carrega as seções que ele INFORMA**, em vez de ser arquivado sob
  uma delas — o fornecedor aparece no resumo, no modelo de operação e nos
  custos, e um mapeamento 1:1 jogaria isso fora. (Desvio do que este plano
  dizia no §5; o índice por seção não sobreviveu ao contato com os dados.)
- `AttributeOption::labelFor()` devolve **null** para um valor sem linha
  correspondente em `attribute_options`, o que apagava um fato que está no
  registro. Agora cai para o valor cru da coluna.

Três coisas descobertas lendo `.pptx` de verdade no Bloco B — todas invisíveis
num arquivo de teste pequeno e erradas em qualquer deck real:

- **A ordem dos slides vem de `p:sldIdLst` em `ppt/presentation.xml`**, resolvida
  pelos relacionamentos — não do nome do arquivo. A numeração das partes reflete
  ordem de criação, então um deck reordenado reporta o slide errado e a
  procedência ("veio do slide 7") deixa de ser verificável.
- **As notas são ligadas por RELACIONAMENTO.** No deck de referência, `slide3`
  aponta para `notesSlide1`. Parear por número atribui as notas ao slide errado.
- **O texto das notas precisa ser lido só do placeholder `body`.** Ler a parte
  inteira traz o cabeçalho, a data e o número do slide do notes master: medido no
  deck do SKBridge, todos os 13 slides voltavam com a nota
  `New Style Office / 8/11/2026 / 3` — e o deck **não tem nenhuma nota real**,
  apesar de ter seis notes slides.

Verificado ponta a ponta contra o deck real: 13 slides, 10.207 caracteres,
nenhum falso positivo do scanner de credenciais (o que valida não sinalizar IP
interno — o deck é cheio deles, de propósito).

Duas coisas descobertas construindo o Bloco A, ambas já corrigidas e cobertas
por teste:

- **`pluck()` numa relação Eloquent aplica os casts do model.** `sections()->pluck('key')`
  devolve instâncias de `SubmissionSectionKey`, não string — um `in_array($key->value, …, true)`
  contra isso nunca casa. `Submission::ensureSections()` usa `toBase()->pluck('key')`
  para ler o valor cru. Vale para qualquer coluna com cast de enum.
- **Default de coluna não chega na instância criada por `firstOrCreate()`.**
  O `state` precisa ser passado explicitamente, senão o model devolvido tem
  `state` nulo até ser relido do banco.

---

## 1. Definição de pronto

Um arquiteto consegue, sem abrir o PowerPoint e sem escrever nada duas vezes:

1. Criar uma submissão ligada (opcionalmente) a uma Solution do inventário.
2. Soltar o material bruto — deck antigo, PDF, imagem, link, ou uma referência
   a uma Solution/Integration/página de documentação já existente.
3. Ver um checklist que **já sabe** categoria, diretoria, fornecedor, ambiente,
   nuvem, criticidade, integrações existentes e donos — apresentados como fatos
   a confirmar, nunca como perguntas.
4. Conversar com o assistente para preencher só as lacunas, recebendo rascunhos
   por seção extraídos do material anexado.
5. Confirmar cada seção (rascunho da IA → confirmado por humano, com marca visível).
6. Copiar o texto completo do chamado no Leo Resolve, com o checklist final já
   marcado a partir do estado real das seções, e baixar o documento em Markdown.

Prova de que funcionou, antes de existir qualquer slide: **cai o número de
submissões que voltam por informação faltando.**

---

## 2. Ordem de construção

Cinco blocos. Os quatro primeiros são determinísticos e testáveis isoladamente;
o chat — a única peça não determinística — entra por último, sobre um modelo de
conteúdo já validado.

| Bloco | Entrega | Testável sozinho? |
|---|---|---|
| A | Enums, migrations, models, policy, factories | sim |
| B | Ingestão de material + extração de texto | sim (fixture `.pptx`) |
| C | Checklist determinístico + regras de desvio | sim (puro) |
| D | Saídas: Markdown + texto do chamado | sim (string) |
| E | Entrevista (chat, job, polling, aplicar rascunho) | com o agente fingido |

A UI acompanha cada bloco: A e B rendem a página de detalhe; C e D rendem os
cards; E rende o painel lateral do chat.

---

## 3. Bloco A — modelo de dados

### Enums (`app/Enums/`)

**`SubmissionStatus`** — `draft`, `in_review`, `submitted`, `approved`,
`approved_with_conditions`, `rejected`, `withdrawn`. Métodos `label()` (PT-BR) e
`tone()`, seguindo o mapa de tons já usado nos badges da Solution.

> ⚠️ Ao emitir o valor num atributo lido por variante arbitrária do Tailwind
> (`group-data-[status=...]`), **troque `_` por `-`**: o Tailwind converte `_`
> em espaço no valor da variante e a regra silenciosamente nunca casa.

**`SubmissionSectionKey`** — as onze seções, com `label()`, `mandatory()`,
`order()` e `question()` (a pergunta-semente que a entrevista usa quando não tem
nada melhor a perguntar):

| key | seção | obrigatória no chamado |
|---|---|---|
| `summary` | Resumo da Proposta | sim |
| `current_state` | Cenário Atual | não (slide 2) |
| `objectives` | Objetivos | não (slide 3) |
| `domains_data` | Domínios e Dados | não (slide 3 do template) |
| `architecture` | Arquitetura de Solução | sim |
| `operating_model` | Modelo de Operação | não (slide 5) |
| `benefits_risks` | Benefícios e Riscos | sim |
| `legacy_impact` | Impactos em Integrações e Legados | sim |
| `standards` | Padrões Adotados | sim |
| `plan_costs` | Plano de Implementação e Custos | sim |
| `alternatives` | Alternativas Avaliadas | não |

As seis obrigatórias são exatamente as do formulário do Leo Resolve; as outras
cinco existem porque o template do deck as pede. Uma única lista serve aos dois
— é o ponto inteiro da proposta.

**`SubmissionSectionState`** — `empty`, `drafted` (proposta pela IA, não
confirmada), `confirmed` (humano assinou embaixo). O estado é o que a marcação
visível e o checklist do chamado leem.

### Migrations (`php artisan make:migration`, uma por assunto)

`submissions`
: `name`, `slug` (unique), `solution_id` nullable → `solutions` nullOnDelete,
  `requester_person_id` nullable → `people` nullOnDelete, `created_by_id` →
  `users`, `status` (string, default `draft`, index), `ticket_reference`
  nullable, `committee_date` nullable date, timestamps. Índice composto
  `(status, committee_date)`.

`submission_sections`
: `submission_id` cascadeOnDelete, `key`, `content` text nullable (Markdown),
  `state` default `empty`, `provenance` json nullable, `updated_by_id` nullable
  → `users` nullOnDelete, timestamps, **unique `(submission_id, key)`**.

`submission_sources`
: `submission_id` cascadeOnDelete, `kind` (`upload`|`link`|`inventory`),
  `label`, `url` nullable, `media_id` nullable → `media` nullOnDelete,
  `nullableMorphs('reference')` (Solution/Integration/DocumentationPage),
  `extracted_text` longText nullable, `extraction_state`
  (`pending`|`done`|`failed`|`skipped`), `extraction_note` nullable, timestamps.

`submission_chats`
: `user_id` → `users`, `submission_id` cascadeOnDelete, timestamps.

`submission_messages`
: `submission_chat_id` cascadeOnDelete, `role`, `content` text, `drafts` json
  nullable, `source_ids` json nullable, `meta` json nullable, `applied_at`
  nullable timestamp, timestamps.

`cati_guidelines` e `cati_examples`
: espelham `flowspec_guidelines` (`title, slug, content, source, is_active`) e
  `flowspec_examples` — aqui como `name, slug, summary, sections json, tags
  json, source, is_active`, onde `sections` é o texto extraído da submissão
  aprovada, por chave de seção.

> **Divergência deliberada do chat de documentação:** lá o rascunho é uma coluna
> `draft` de texto, porque a resposta propõe uma página inteira. Aqui um turno
> pode propor rascunho para mais de uma seção ao mesmo tempo, então é
> `drafts` json — lista de `{key, markdown}`.

> **Nunca rode `migrate:fresh` no banco de desenvolvimento.** Valide migrations
> pelos testes (`LazilyRefreshDatabase` usa outro banco).

### Models

Todos com `$fillable` explícito (`$guarded = []` é proibido) e tipo de retorno
em toda relação.

- `Submission` — `implements HasMedia`, `use InteractsWithMedia`, constante
  `SOURCES_COLLECTION = 'submission_sources'`. Relações: `solution()`,
  `requester()`, `createdBy()`, `sections()`, `sources()`, `chats()`. Helpers:
  `section(SubmissionSectionKey $key)`, `scopeFilter(Builder, array $filters)`
  (busca por nome/solução, `status`, `solution`, `sort`) — o mesmo contrato de
  `Solution::scopeFilter()`, para que a contagem de resultados e os chips de
  filtro reutilizem a query em vez de reimplementá-la.
- `SubmissionSection`, `SubmissionSource`.
- `SubmissionChat` — copie `DocumentationChat`: `REPLY_STALL_SECONDS = 660`,
  `isAwaitingReply()` e `awaitsReplyFor(?SubmissionMessage $last)`. É o guarda
  contra o registro que fica `pending` para sempre quando o worker morre no meio
  (reinício do `composer dev` é o caso comum).
- `SubmissionMessage`.
- `CatiGuideline`, `CatiExample`.

### Coleção de mídia

`submission_sources` é a **quinta** coleção do app — as outras quatro (`avatar`,
`context_documents`, `docs`, e o `docs` compartilhado por `Integration`) não
servem: aqui a regra de validação é `file` (não `image`), aceita `pptx`/`docx`,
e o arquivo é servido por controller próprio, não por `files.show`.

Regra das requests: `['required','file','mimes:pdf,pptx,docx,txt,md,csv,json,png,jpg,jpeg,webp,svg','max:20480']`
— notação de array, nunca pipe. Sem conversões registradas.

### Policy

`SubmissionPolicy` (`viewAny`, `view`, `create`, `update`, `delete`), registrada
por descoberta. **Toda ação de controller que muda dado chama `$this->authorize()`.**

---

## 4. Bloco B — ingestão de material

### Extratores (`app/Support/Cati/`)

`PptxTextExtractor`
: `ZipArchive` → itera `ppt/slides/slide{n}.xml` em **ordem numérica** (`slide10`
  não pode vir antes de `slide2` — ordenação natural, não `sort()` de string),
  parseia com `simplexml_load_string(..., LIBXML_NONET)`, registra o namespace
  DrawingML e junta os runs `a:t` por parágrafo `a:p`. Lê também
  `ppt/notesSlides/notesSlide{n}.xml` (as notas do apresentador costumam ter o
  raciocínio que o slide esconde). Devolve `list<array{slide:int, text:string, notes:?string}>`.

`DocxTextExtractor`
: mesmo desenho sobre `word/document.xml` e os runs `w:t`.

`SourceTextExtractor`
: despachante por extensão. `txt/md/csv/json` entram direto; `pptx`/`docx`
  passam pelos extratores; **`pdf` e imagens não são extraídos** — vão como
  anexo nativo ao modelo (`Laravel\Ai\Files\LocalDocument` / `LocalImage`),
  exatamente como `ContextDocumentResolver` já faz. Marque esses como
  `extraction_state = 'skipped'` com nota, não como falha.

### Aviso de conteúdo sensível

`App\Support\Cati\SensitiveTextScanner` — **novo**. `CredentialScrubber` do
flowSpec não serve direto: a API pública dele é `violations(array $document)`,
feita para um flowSpec estruturado, não para texto livre. Reaproveite a *ideia*
(fragmentos de chave sensíveis + padrões de valor), mas escreva um scanner que
**sinaliza trechos e avisa o usuário** em vez de reescrever silenciosamente o
material dele — apagar um IP no meio de um documento de arquitetura sem avisar é
pior que o problema. O aviso aparece no card de material e no prompt.

### Action e controller

`App\Actions\Cati\IngestSubmissionSource` (DI por construtor, `handle()`):
valida, guarda a mídia, extrai, escaneia, persiste `SubmissionSource`. Chamado
por `SubmissionSourceController@store`.

Para arquivos grandes, extraia em job; para o tamanho de deck que existe hoje
(~1 MB) é síncrono e simples. Comece síncrono.

---

## 5. Bloco C — checklist determinístico

### `App\Support\Cati\SubmissionRequirements`

Espelha `App\Support\Documentation\DocumentationRequirements` — mesma forma de
retorno, mesma filosofia: nunca bloqueia nada, é informativo, e alimenta o
prompt para que o assistente **não pergunte o que já sabe**.

`for(Submission $s): array<string, list<array{key,label,satisfied,source,value?}>>`
indexado por chave de seção, com três origens:

- `attribute` — fato já conhecido do inventário quando há Solution: `category`,
  `directorate`, `vendor_company_id`, `environment`, `cloud`, `criticality`,
  `support_type`, `contract_status`. **Reportado como fato, nunca como lacuna.**
- `structural` — integrações existentes da Solution (com protocolo e direção),
  donos ligados pelo pivô, material anexado, referência do chamado.
- `content` — cada seção tem conteúdo? está confirmada? É o único item que o
  usuário precisa mesmo fechar.

### `App\Support\Cati\DeviationRules`

`for(Submission $s): list<array{key,question,why,severity}>` — as perguntas que
o comitê realmente faz, escritas como regras sobre dados que o app já tem. Os
valores vêm de `attribute_options`, então use os reais:

| condição | pergunta gerada |
|---|---|
| `cloud` ∉ {`gcp`} | aderência ao M2C: por que não GCP, e por quanto tempo |
| `environment` = `saas` sem menção a dado sensível em `standards` | qual dado trafega e onde reside |
| integração transacional fora do padrão Digibee | qual a exceção e quem aprovou |
| `criticality` ∈ {`critical`,`high`} e `plan_costs` sem plano de contingência/rollback | como se volta atrás |
| `contract_status` de contratado sem `vendor_company_id` | quem é o fornecedor |
| `legacy_impact` vazia com integrações existentes na Solution | quais integrações mudam |

O LLM **não descobre** essas perguntas: ele as verbaliza no contexto do caso.
Regra determinística, teste unitário, zero custo de token.

---

## 6. Bloco D — saídas em texto

Duas Actions puras, cada uma devolvendo `string`:

`App\Actions\Cati\RenderSubmissionMarkdown`
: documento completo — título, tabela de metadados, as onze seções na ordem do
  enum, e um apêndice de procedência (de qual arquivo/slide/campo veio cada
  seção, lido de `submission_sections.provenance`).

`App\Actions\Cati\RenderTicketText`
: exatamente o formato do chamado no Leo Resolve — os sete blocos cercados, na
  ordem do formulário —, com o **checklist final marcado a partir do estado real
  das seções** (`state === confirmed` → `[x]`). Ninguém mais marca caixinha à mão.

Servidas por `SubmissionExportController` (`markdown`, `ticket`) como download
`text/markdown` e como painel de copiar. Para o botão de copiar, **reutilize
`docs-copy.js` tal como está**: basta renderizar os hooks que ele já lê
(`data-ak-docs-copy` no botão e `data-ak-docs-markdown` numa `<textarea>`
escondida). Módulo novo, nenhum.

---

## 7. Bloco E — a entrevista

### Serviços (`app/Services/Cati/`)

`SubmissionContextResolver` → `SubmissionContext`
: monta o contexto de um turno: fatos da Solution, `SubmissionRequirements`,
  `DeviationRules`, texto das fontes (respeitando orçamento de caracteres),
  anexos nativos (PDF/imagem), o que ficou de fora (**sinalizado, nunca
  descartado em silêncio**), diretrizes ativas e N exemplos do corpus. É a
  fusão de `FlowspecContextResolver` com `ContextDocumentResolver`.

`SubmissionChatPromptBuilder`
: `systemPrompt()` e `turnPrompt()`. Regras que o prompt precisa carregar:
  responder em PT-BR; **nunca perguntar o que está nos fatos**; uma pergunta por
  vez; propor rascunho sempre que houver material que sustente; e proibição de
  inventar número — custo, prazo e dimensionamento se perguntam, não se estimam.

`SubmissionChatReply` (DTO)
: `content` (texto já sem os blocos de rascunho), `drafts`
  (`list<array{key, markdown}>`), `meta` (provider/modelo/tokens, fontes usadas,
  omitidas, snapshot do checklist).

`SubmissionChatService::generate(SubmissionMessage $userMessage): SubmissionChatReply`
: uma chamada — `agent(instructions: $this->prompts->systemPrompt())->prompt(...)`,
  igual aos dois chats existentes. Sem laço de correção (isso é do flowSpec, que
  valida JSON).

**Convenção do bloco de rascunho** — a mesma cerca de 4 crases do assistente de
documentação, com uma info string identificando a seção:

    ````rascunho:summary
    …markdown da seção…
    ````

Quatro crases porque o conteúdo interno frequentemente tem blocos de 3.

### Job

`App\Jobs\GenerateSubmissionChatReply` — copie a forma de
`GenerateDocumentationChatReply`, que já resolveu tudo isto:

- `$timeout = 240`, `$tries = 25`, `$maxExceptions = 3`, `backoff() = [10,30,60]`.
  O `$tries` alto existe porque cada bloqueio do `WithoutOverlapping` volta para
  a fila e consome uma tentativa — é espera, não falha; quem limita falha real é
  `$maxExceptions`.
- `middleware()`: `WithoutOverlapping($chatId)->expireAfter(270)->releaseAfter(30)`
  — uma conversa é sequencial, dois turnos simultâneos quebram a premissa de
  "um turno pendente por vez" que o polling assume.
- `isSuperseded()` consultado em `handle()` **e** em `failed()`.
- `failed()` persiste uma mensagem de assistente em PT-BR com
  `meta.status = 'failed'`, e loga a exceção completa só no servidor — mensagem
  de erro de provider pode conter URL, headers e fragmento de chave, e `meta` é
  trilha de auditoria exportável.

> `retry_after` da conexão precisa ser maior que `$timeout`. Hoje
> `QUEUE_CONNECTION=database` com `retry_after=900` — folgado. Mas `predis` está
> instalado e a conexão `redis` está com `retry_after=90`, menor que os 240 do
> job: se alguém trocar a conexão, este job (e os dois que já existem) passam a
> ser reexecutados no meio da geração. Suba `REDIS_QUEUE_RETRY_AFTER` junto com
> a troca, se ela acontecer.

### Controller `SubmissionChatController`

`panel`, `store`, `status`, `apply`.

- `status` precisa ser **barato enquanto pendente**: só monte e renderize o slot
  do thread depois que a resposta existir. Um poll a cada 2,5 s para um job de
  minutos não pode custar uma query+render por tique.
- `apply` **diverge** do chat de documentação de propósito: lá o "Aplicar" é
  bookkeeping e o Markdown entra no editor pelo cliente. Aqui não há editor — o
  apply **grava a seção no servidor** (`content`, `state = drafted`,
  `provenance` apontando para a mensagem) e responde com o slot de seções.
  Confirmar é um segundo gesto, explícito, que leva a seção a `confirmed`.

### JS

`resources/js/modules/cati-chat.js` — cópia dirigida de `docs-chat.js`, com
hooks `data-ak-cati-chat-*`: `-input`, `-send`, `-scroll`, `-status`, `-poll`,
`-draft`, `-view-draft`, `-apply`, `-review-template`, `-review-body`,
`-review-close`, `-resume`. Reaproveite `docs-diff.js` para revisar o rascunho
em diff antes de aplicar. O poll precisa de **teto de tentativas com Toast** —
nunca fique polando em silêncio se o worker estiver morto. Registre em
`window.globalModules` como `catiChat`.

---

## 8. UI

### Páginas

`resources/views/submissions/index.blade.php`
: catálogo com filtros (status, solução, período) e busca, no padrão de
  `/solutions`: `Submissions\Index` (slot `submissions-index-slot`),
  `Submissions\ResultsCount` e `Submissions\FilterChips` — os três sempre
  devolvidos juntos em toda resposta AJAX, senão o contador e os chips ficam
  velhos enquanto a grade atualiza. Criar pela side panel.

`resources/views/submissions/show.blade.php`
: a bancada — **reformulada em 2026-08-22 para ser a entrevista, não um painel**
  (ver "A bancada é a conversa" abaixo). `Submissions\DetailHeader` (slot
  `submission-detail-header-slot`), agora enxuto: nome, status e os quatro
  campos numa linha só, todos `x-ui.inline-edit` apontando para
  `submissions.field.update`. Abaixo, uma barra com `Submissions\StageStrip` +
  o seletor de abas, e três painéis montados ao mesmo tempo:
  **Preparação** (thread + `x-submissions.composer` + `Submissions\Progress` +
  `Submissions\Sources`), **Documento** (`Submissions\Sections` + exportações) e
  **Comitê** (`Submissions\Checklist` + `Submissions\PreReview` +
  `Submissions\Deliberation` + o formulário de deliberação).

Cada seção é um card com o chip de estado, a leitura em Markdown
(`x-ui.markdown` + `.ak-rich-text`) e `x-ui.inline-edit type="textarea"` para
editar — nada nesta página parece formulário até alguém pedir para editar.

**Estados vazios** com `x-ui.empty-state` + ilustração unDraw, não linha de
texto cinza.

### A bancada é a conversa (2026-08-22)

A Fase 1 entregou tudo o que o comitê precisa, mas numa tela em que a
entrevista era um widget de 360px com um textarea de duas linhas, ao lado de
seis cards abertos ao mesmo tempo. Nada ali dizia "responda e eu escrevo" —
dizia "preencha este formulário". A reformulação mantém cada peça e muda a
hierarquia:

- **Três abas, os três painéis sempre montados.** Um slot devolvido por uma
  mutação precisa aterrissar mesmo com a aba escondida: `ajax-slot.js` troca
  por id e ignora em silêncio um id que não está no DOM, então desmontar a aba
  inativa deixaria ela velha até o reload. O painel `Preparação` **não** nasce
  com `hidden` — `tabs.js` esconde todos e reabre esse sincronamente no
  `init()`, e sem JS é nele que a pessoa cai.
- **`App\Support\Cati\SubmissionStages`** — Material → Entrevista → Revisão →
  Comitê, derivado do registro, nunca marcado à mão. "Atual" é a primeira etapa
  não concluída **depois** da última concluída: anexar material é opcional, e
  "primeira não concluída" prenderia o ponteiro em `material` para sempre numa
  submissão com o documento escrito.
- **`Submissions\Progress`** saiu do `Checklist`: as 11 seções com estado e os
  fatos do catálogo ficam ao lado da conversa; conformidade, itens estruturais
  e as perguntas do comitê ficam na aba `Comitê`. Toda mutação que mexe em
  seção devolve `Sections` + `Progress` + `Checklist` + `StageStrip` juntos —
  três deles vivem em abas que podem não estar visíveis.
- **Colar texto longo vira anexo**, como no cliente do Claude
  (`SubmissionSourceKind::Text`, limiar em `services.cati.paste_threshold_chars`).
  Colado como mensagem, um documento de arquitetura soterraria a conversa, seria
  reenviado literalmente como histórico a cada turno e não teria como ser
  removido; como material ele é uma linha que alguém lê, confere contra
  credencial e apaga. Anexar (arquivo, link, colagem, arrastar-e-soltar) é
  trabalho do composer — o card "Material" virou só a lista.

Duas armadilhas pagas na reformulação, ambas invisíveis no código:

- **`<form>` dentro de `<form>` é descartado pelo parser.** Cada chip de
  material carrega seu próprio form DELETE escondido; com os chips dentro do
  form da mensagem, o parser some com a tag interna e reparenteia os filhos —
  `getElementById()` devolve null e `ajax-post.js` morre em
  `new FormData(null)`. Por isso a caixa arredondada é uma `<div>` e o
  `<form>` da mensagem é filho dela.
- **`sources` sem `.media` só quebra com DOIS anexos.** `Builder::hydrate()`
  arma o guard de lazy loading apenas quando `count($items) > 1`, então um
  único material carrega `media` em silêncio e a página parece certa; o segundo
  vira 500. Um teste com um anexo só não é teste de regressão nenhum — o que
  existe hoje anexa dois.

### O prompt da entrevista (2026-08-23)

Reformulado depois de a bancada ficar pronta, e por um motivo específico: o
módulo já calculava três coisas que eram descartadas antes de chegar ao
modelo.

- **`SubmissionSectionKey::question()`** chegava só na mensagem de abertura. A
  entrevista via `domains_data — Domínios e dados` e adivinhava o resto — de
  novo a cada turno. Agora cada seção vai com a pergunta que precisa responder,
  o estado, o texto atual e a marca `[OBRIGATÓRIA]` / `[só no deck]`, sob um
  aviso de que texto escrito não é o mesmo que pergunta respondida.
- **O mapa fato → seções** que `SubmissionRequirements` monta de propósito. Ia
  como `label: value` e o modelo redescobria a cada turno que o fornecedor
  informa resumo, modelo de operação e custos. Agora vai
  `- Fornecedor: Acme → informa: summary, operating_model, plan_costs`.
- **Os achados do scanner de credenciais.** A UI já marcava; o prompt inlinava
  o texto cru sem aviso nenhum. Um rascunho que copia um `client_secret` para
  dentro de uma seção não é problema cosmético: aprovar promove as seções para
  a documentação da solução e o renderer imprime no slide.

Regras novas no system prompt, todas por falha observada e não por estilo:
"não sei" é resposta e encerra o assunto (registre a lacuna, não reformule a
pergunta, não invente valor); não afirme ausência que ninguém declarou;
material e pessoa em conflito → a pessoa vence, mas nomeie a divergência;
nunca reproduza credencial, descreva o mecanismo; seção é prosa completa, a
versão de slide é outra passada; e quando parar.

**O histórico agora é aparado** (`services.cati.history_budget_chars`, padrão
40k). Era a única parte do prompt que crescia sem limite sendo reenviada a cada
turno — e entrevista longa aqui é o caso normal, não o extremo, então a falha
chegava no meio de uma submissão que alguém preencheu a tarde inteira. Apara do
mais antigo, mantém sempre o turno mais recente, e **avisa** quantas mensagens
saíram: conversa que esqueceu o próprio começo em silêncio lê como assistente
perdendo o fio.

Verificado com dois turnos reais contra o modelo, não só por teste de string.
No primeiro, uma mensagem curta ("confirmo as notas; custo eu não sei ainda")
produziu rascunho de 6 seções, com o segredo do OAuth2 descrito como mecanismo
em vez de copiado, e `plan_costs` registrando a pendência em vez de estimar.
Esse mesmo turno mostrou o furo que virou a regra da ausência: o modelo tinha
escrito "não há tráfego de dados pessoais sensíveis" sem ninguém ter dito isso.
No segundo turno, com a regra, virou "mapeamento de dados pessoais ainda não
realizado".

### Navegação

Sem entrada na sidebar a página fica órfã. Em `layout.blade.php`, no grupo
`Governança`:

```php
['route' => 'submissions.index', 'label' => 'Comitê de Arquitetura',
 'icon' => 'clipboard-document-check', 'active' => 'submissions.*'],
```

---

## 9. Rotas

Todas nomeadas, caminho em inglês, dentro do grupo autenticado.

| método | caminho | nome |
|---|---|---|
| GET | `submissions` | `submissions.index` |
| GET | `submissions/create` | `submissions.create` |
| POST | `submissions` | `submissions.store` |
| GET | `submissions/{submission}` | `submissions.show` |
| GET | `submissions/{submission}/edit` | `submissions.edit` |
| PATCH | `submissions/{submission}` | `submissions.update` |
| PATCH | `submissions/{submission}/field` | `submissions.field.update` |
| DELETE | `submissions/{submission}` | `submissions.destroy` |
| PATCH | `submissions/{submission}/sections/{section}` | `submissions.sections.update` |
| POST | `submissions/{submission}/sections/{section}/confirm` | `submissions.sections.confirm` |
| POST | `submissions/{submission}/sources` | `submissions.sources.store` |
| GET | `submissions/{submission}/sources/{source}` | `submissions.sources.show` |
| DELETE | `submissions/{submission}/sources/{source}` | `submissions.sources.destroy` |
| GET | `submissions/{submission}/chat` | `submissions.chat.panel` |
| POST | `submissions/{submission}/chat/messages` | `submissions.chat.messages.store` |
| GET | `submissions/{submission}/chat/{chat}/status` | `submissions.chat.status` |
| POST | `submissions/{submission}/chat/messages/{message}/apply` | `submissions.chat.messages.apply` |
| GET | `submissions/{submission}/export/markdown` | `submissions.export.markdown` |
| GET | `submissions/{submission}/export/ticket` | `submissions.export.ticket` |

Duas coisas:

- **`submissions/create` tem que ser declarada antes de `submissions/{submission}`**,
  senão o binding tenta resolver uma submissão de slug `create`. É a mesma
  armadilha já anotada em `routes/web.php` sobre `flowspec/{chat}`.
- As rotas aninhadas (`sections`, `sources`) vão num
  `Route::scopeBindings()->group(...)`: sem isso,
  `DELETE submissions/{a}/sources/{source}` apaga o material da submissão `{b}`.

Form Requests para tudo (`StoreSubmissionRequest`, `UpdateSubmissionRequest`,
`UpdateSubmissionFieldRequest`, `UpdateSubmissionSectionRequest`,
`StoreSubmissionSourceRequest`, `StoreSubmissionChatMessageRequest`) — nada de
`$request->validate()` no controller. No `field.update`, toda regra é
`sometimes` (o header manda só o campo confirmado), `slug` fica de fora de
propósito (renomear não pode mudar a URL da página que está sendo renderizada)
e `prepareForValidation()` normaliza `''` → `null`.

---

## 10. Testes (Pest, `LazilyRefreshDatabase`, em `tests/Feature`)

| arquivo | o que prova |
|---|---|
| `CatiSubmissionCrudTest` | criar/editar/apagar, slug preservado no update, escopo das rotas aninhadas |
| `CatiSubmissionInlineFieldUpdateTest` | `field.update` devolve o slot do header; regra não é mais estrita que a do painel |
| `CatiSourceIngestionTest` | upload de `.pptx` fixture → texto extraído por slide, na ordem certa (inclusive `slide10` depois de `slide9`); PDF marcado `skipped`, não `failed` |
| `CatiSubmissionRequirementsTest` | fato do inventário nunca vira lacuna; seção vazia vira lacuna |
| `CatiDeviationRulesTest` | uma regra por linha da tabela do §5, com os valores reais de `attribute_options` |
| `CatiTicketRenderTest` | as sete seções na ordem do formulário; checklist marcado a partir de `state` |
| `CatiMarkdownRenderTest` | as onze seções na ordem do enum + apêndice de procedência |
| `CatiChatTest` | `store` cria mensagem e despacha job; `status` **não** monta o slot enquanto pendente; `apply` grava a seção e devolve o slot |
| `CatiChatServiceTest` | agente fingido: parsing do bloco de 4 crases com info string, drafts por seção, meta preenchida |
| `CatiChatStallTest` | `freezeTime()`, mensagem antiga além de `REPLY_STALL_SECONDS` reabre o compositor |
| `CatiSubmissionRenderTest` | a página renderiza os hooks `data-ak-*` (o bug do `ComponentAttributeBag`/`@json` não aparece em teste de controller) |

Regras deste repo que valem para os testes:

- `assertJsonValidationErrors()` **não funciona aqui** — `bootstrap/app.php`
  reformata `ValidationException` para `{message, title, type}`, sem chave
  `errors`. Asserte `->assertStatus(422)->assertJson(['type' => 'warning'])` e
  depois `expect($response->json('message'))->toContain(...)`.
- Teste cujo assunto é janela de tempo ou TTL começa com `$this->freezeTime()`.
- Se uma alteração em Blade parecer invisível para o teste **e** para o
  navegador, rode `php artisan view:clear` — view compilada velha já enganou
  este repo antes, e o sintoma pior é um teste que **passa** contra markup
  que você acabou de apagar.

---

## 11. Configuração

Em `config/services.php`, no padrão dos dois blocos que já existem:

```php
'cati' => [
    'provider'             => env('CATI_AI_PROVIDER', 'gemini'),
    'model'                => env('CATI_AI_MODEL', 'gemini-3.6-flash'),
    'timeout'              => env('CATI_AI_TIMEOUT', 180),
    'max_examples'         => env('CATI_MAX_EXAMPLES', 3),
    'max_sources'          => env('CATI_MAX_SOURCES', 12),
    'doc_budget_chars'     => env('CATI_DOC_BUDGET_CHARS', 60000),
    'max_attachment_bytes' => env('CATI_MAX_ATTACHMENT_BYTES', 20971520),
],
```

`env()` só aqui dentro, nunca no código da aplicação. Chave de API sai de
`config/ai.php` do pacote, como nos outros dois.

---

## 12. Armadilhas deste repositório

Todas já custaram tempo aqui pelo menos uma vez:

1. Atributo com JSON: **sempre** `attr="{{ json_encode($x) }}"`. `@json()` não
   compila dentro de tag de componente e `:attr=` não compila em tag HTML comum
   — nos dois casos a falha é invisível no fonte.
2. Nunca escreva a **tag** de um componente dentro de comentário Blade — o
   compilador de tags não sabe o que é comentário e vira invocação real.
3. Nunca ecoe um `ComponentAttributeBag` na área de atributos de uma tag
   `<x-...>` — a tag deixa de compilar e sai verbatim no HTML, sem erro.
4. `Model::shouldBeStrict()` **não** protege fetch de linha única — job que
   recebe um model por `SerializesModels` e caminha por relação não dispara
   exceção nenhuma. `loadMissing()` explícito no topo dos métodos de job/serviço.
5. `x-forms.button` nunca com `type="button"` junto de `data-ak-ajax` — o clique
   continua funcionando e o Enter morre em silêncio.
6. Botão que fica fora do `<form>` precisa de `form="id-do-form"`.
7. `ajaxModule.init()` devolve **Promise**, não XHR.
8. Nada de `<button>`/`<input>`/`<select>` cru: use os `x-forms.*`.

---

## 13. Fora de escopo da Fase 1

Não construa agora, e não crie coluna "para depois" — migration é barata e uma
coluna nula por três meses não é:

- `chain_asis` / `chain_tobe` e qualquer render de diagrama (Fase 3 — feita em
  2026-08-23 como `submission_diagrams`, quatro tipos, `docs/cati-fase-3.md`).
- Exportador `.pptx`, deck spec, validador de spec (Fase 2).
- `decision`, `conditions`, `decided_at` (Fase 4).
- Pré-revisão adversarial e conformidade automática (Fase 4).
- Promoção do TO BE de volta para o inventário (Fase 4).
