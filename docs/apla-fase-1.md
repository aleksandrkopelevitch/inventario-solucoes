# APLA — Fase 1: verificar a rota antes de construir em cima dela

Plano do **Agente Autônomo de Ciclo de Vida de Pipelines** (APLA): transformar
o Especialista em Integrações de um gerador de flowSpec que alguém cola no
canvas em um agente que ingere, faz deploy em `test`, gera e roda uma bateria
de testes sintéticos, diagnostica a falha, corrige e repete até passar — e só
então promove para `prod`.

A especificação SDD completa (subsistemas, máquina de estados, roadmap de 5
fases) está na mensagem que originou este documento. Aqui fica o que a
especificação **não** podia saber: o que a plataforma realmente oferece, o que
o gerador de hoje realmente produz, e em que ordem isso pode ser construído
sem descobrir quatro subsistemas depois que a premissa estava errada.

---

## Estado

| Bloco | Situação |
|---|---|
| Reconhecimento — `digibeectl`, export real, docs | **feito** (2026-09-04) |
| A — resolução de credencial + cliente HTTP + probe read-only | **feito** — 17 testes em `tests/Feature/DigibeeDesignProbeTest.php`, suíte inteira verde (1371) |
| A′ — **rodar** o probe contra o tenant | **feito** (2026-09-04) — as três rotas respondem, e o pipeline volta com as 34 chaves (§ O que o probe respondeu) |
| A″ — verificar os **verbos de escrita** | **feito, e agora com o token escopado** — cria, faz upsert e relê idêntico (§ O que o token escopado respondeu) |
| B — modo de ingestão no normalizador/validador | **feito** — 36 testes em `tests/Feature/FlowspecIngestionTest.php` (§ O que a ingestão escreve) |
| C — síntese de `triggerSpec` | **feito** — 19 testes em `tests/Feature/DigibeeTriggerSpecTest.php` (§ O que o triggerSpec sintetiza) |
| D — runner de deploy (pela API) | **feito** — 16 testes em `tests/Feature/DigibeeDeployTest.php`; o corpo foi probado contra a plataforma e três coisas mudaram por causa disso (§ O que a verificação do corpo encontrou). Falta um deploy que complete: o `apla-probe` precisa de uma versão publicada |
| E — matriz de testes sintéticos + avaliador de asserções | **feito** — 41 testes em `tests/Feature/FlowspecTestMatrixTest.php`, as 201 do export constroem sem erro, e a bateria aparece na conversa do F8 (§ O que a matriz produz, § O contrato de resposta, § A bateria na tela) |
| F — loop de auto-correção com evidência de runtime | não começado |
| G — portão de promoção para `prod` | não começado |

**A Fase 1 entrega uma constatação, não um comportamento.** Ela responde uma
única pergunta — "é possível escrever um flowSpec num pipeline pela API?" — e
todo o resto do roadmap depende da resposta. Construir a matriz de testes
antes disso é o erro clássico deste tipo de projeto, com um agravante: aqui o
erro custa uma tentativa de deploy num realm que roda 201 integrações vivas.

Para rodar o que existe:

```
php artisan digibee:design:probe --diagnose     # não faz nenhuma chamada de rede
php artisan digibee:design:probe                # três GETs, nada mais
```

---

## As duas decisões

**Topologia: no droplet, com um usuário de realm restrito.** O agente roda
dentro do app, na fila, e a credencial mora em variáveis de ambiente
criptografadas.

**Ingestão: verificar a Design API primeiro.** As rotas
`/design/realms/{realm}/pipelines` não são publicadas em lugar nenhum, então
elas são confirmadas empiricamente antes de qualquer código de escrita.

A primeira decisão **reverte uma fronteira que este repositório documenta como
sendo de segurança** (`AGENTS.md` § *`digibeectl` never runs on the server*, e
o docblock de `App\Support\Digibee\DigibeectlClient`): "o artefato viaja, a
credencial não". Essa regra foi escrita sobre a credencial de login
interativo, cujo alcance chega a criar e **apagar** deployments em produção — e
continua valendo para ela. O que muda é que existe uma credencial mais estreita
possível:

- A tabela de operações do próprio `digibeectl` documenta **permissões por
  operação**: `PIPELINE:READ`, `DEPLOYMENT:CREATE`,
  `DEPLOYMENT:CREATE:REDEPLOY`, `DEPLOYMENT:DELETE`, `CONFIGURATION:READ`,
  `CONFIGURATION:UPDATE`.
- `digibeectl config set` autentica com um par `--auth-key`/`--secret-key`, não
  com um login interativo.

Logo, uma credencial dedicada e estreita é um **objeto de risco diferente**
daquele sobre o qual a regra foi escrita, e é isso que torna a reversão
defensável.

**Duas coisas desta redação original saíram erradas, e a seção § A credencial é
um TOKEN do digibeectl tem a versão certa.** Não é um "usuário de realm" e não
precisa de quem administra o realm: é um **token do `digibeectl`**, criado em
Administration → Digibeectl com lista de permissões e expiração próprias — ou
seja, a plataforma já oferece de fábrica exatamente a credencial escopada que
esta decisão pressupõe. E "criar deployment em `test`" **não é escopável**:
`DEPLOYMENT:CREATE` vale para todos os ambientes. O que fecha o loop sem
alcance em produção é `DEPLOYMENT:CREATE:REDEPLOY` (esse sim por ambiente) mais
uma pessoa fazendo o primeiro deploy.

O droplet, vale lembrar, é compartilhado com outros dois apps.

`digibee:pipelines:pull` **continua fora do servidor** de qualquer forma: ele
segue precisando da credencial ampla.

---

## O que o reconhecimento encontrou

Seis constatações, todas medidas contra o `digibeectl` instalado, a
documentação espelhada e os 201 pipelines do export local (`storage/app/private/digibee-pipelines/`).

### 1. `digibeectl` já cobre tudo menos a operação que importa

Verbos suportados, com permissão documentada: `create deployment` (com
`--redeploy`, `--wait`, `-e prod`, `--pipeline-size`, `--consumers`,
`--replicas`), `get deployment` (com `--status`), `set deployment`
(`--rollback`, `--restore`), `delete deployment`, `get metrics`,
`get deployment-history`, `get pipeline --allspecs`.

Mas **`create pipeline` aceita só `--name`, `--description` e `--project`** —
cria uma casca vazia. Não existe flag para subir um flowSpec, e isso está
confirmado tanto no `--help` do binário quanto na tabela de operações publicada
pela Digibee.

Consequência que essa constatação teve **antes** da decisão de topologia: usar
o CLI para deploy, status, métricas e histórico, e a rota não documentada só
para o upsert. **Com a decisão de rodar no droplet, isso não se sustenta**, e a
correção é do plano, não da constatação.

O `digibeectl` existe para Linux (`tar.gz`, instalado por
`curl -s .../install.sh | bash`), então não é impossível — é caro do jeito
errado. Colocá-lo no droplet significa um binário de terceiro instalado por
pipe-para-bash num host compartilhado com outros dois apps, atualizado por
fora, mais uma SEGUNDA cópia da credencial no arquivo de config dele (com
chave de encriptação e passphrase próprias, `digibeectl config set`), para
depois o agente falar com a plataforma por subprocesso em vez de por HTTP.

Então o driver é **a API para tudo**, e a consequência de verdade é outra: as
rotas de runtime precisam ser verificadas igual às de design. O probe confirmou
`GET /runtime/realms/{realm}/deployments`; o `POST` que cria deployment é tão
não verificado quanto o de design. O CLI segue sendo a ferramenta da estação de
trabalho — é o que `digibee:pipelines:pull` usa, e continua sendo o jeito certo
ali.

### 2. `meta` e `position` não existem em pipeline armazenado

Dos 201 documentos: **0** têm chave `meta` no topo e **0** steps carregam
`position`. A geometria do canvas mora em `metadata.canvas`
(`{nodes, edges}`, e sem coordenada nenhuma), presente em apenas 40 deles.

Ou seja: o `meta: {<stepId>: {position: {x, y}}}` que o gerador produz hoje —
junto com `DigibeeFlowspecNormalizer::fillMissingPositions()` e a regra do
validador que exige posição fora de tracks de for-each — é um construto de
**área de transferência**, não de persistência. O auto-layout do §3.2.3 não tem
evidência de ser necessário no caminho de ingestão; ele é necessário no caminho
de colagem, que continua existindo.

### 3. A troca da branch raiz inverte a primeira regra do validador

Os 201 pipelines vivos têm branch `start` (201 de 201). O gerador é
**obrigado** a emitir exatamente uma `disconnected-root:<uuid>` — regra 1 do
system prompt e primeira checagem de `DigibeeFlowspecValidator::validate()` —
precisamente porque a saída de hoje é para colar.

Detalhe que ninguém esperaria: `disconnected-root:` **não aparece no export**.
Os specs desconectados persistidos usam `disconnected-start` (50 ocorrências).
A convenção atual bate com o formato do clipboard, não com o armazenado.

Como as pessoas vão continuar colando, isso é uma **chave de modo** atravessando
normalizador, validador e system prompt — não uma reescrita. Os dois modos
precisam coexistir e ser testados separadamente.

### 4. O documento gerado é 2 chaves de um objeto de 34

Todo pipeline do export carrega 34 chaves (`folderId` em 3, virando 35). O
gerador produz duas, e uma delas é o construto de clipboard do item 2.

A que falta e o §3.4 **depende** é `triggerSpec`: 114 dos 201 são de categoria
*Web Protocols*, e o spec desses triggers carrega `basicAuth`, `jwt`,
`keyAuth`, `methods`, `addCors`, `requestSizeLimit`. Sem sintetizar isso, o
runner de testes não sabe nem a URL nem o modo de autenticação do endpoint que
ele mesmo acabou de fazer deploy. `canvasVersion` também não é universal: 2 em
160, `0` em 41.

Uma coisa que **não** divergiu: `params.onProcess`/`onException` apontando para
`<id>-onProcessTrack` bate com a realidade (404 steps usam exatamente isso).

### 5. A URL do §3.4 aponta para produção

A especificação diz que um pipeline implantado é chamado em
`https://api.godigibee.io/pipeline/{realm}/{environment}/v1/{pipelineName}`.
A referência do trigger REST da própria Digibee diz outra coisa, e três páginas
independentes concordam (referência do REST, how-to de mTLS, boas práticas de
nomenclatura):

```
https://test.godigibee.io/pipeline/{realm}/v{n}/{pipeline-name}   TEST
https://api.godigibee.io/pipeline/{realm}/v{n}/{pipeline-name}    PROD
```

Duas diferenças, e a primeira é de segurança: **o ambiente é o HOST**, não um
segmento de path, e `v{n}` é a versão MAJOR do pipeline, não um `v1` literal.

Escrito do jeito da especificação, o segmento sobrando dá 404 — e a "correção"
óbvia, apagar o segmento que sobra, manda toda chamada do ambiente `test` para
**produção**, com o relatório dizendo test. Por isso `runtime_hosts` é um mapa
sem default e um ambiente fora dele é **recusado**
(`DigibeeApiException::unknownEnvironment`), em vez de resolver para o host que
sobrou.

### 6. O guard-rail do §5 está no verbo errado

"Nunca chamar `DELETE /pipelines`" é razoável e insuficiente. O verbo
destrutivo aqui é **`create deployment -e prod`**: promoção é o que alcança
tráfego real. Por isso a proteção é uma lista de ambientes permitidos em
configuração (`services.digibee.design.deployable_environments`, hoje só
`test`), e não uma condicional no código do agente — abrir produção passa a ser
um ato explícito de configuração, não um argumento que o agente pode escolher.

---

## O que o probe respondeu

Rodado em 2026-09-04, com a credencial interativa do `digibeectl` (não com o
usuário restrito, que ainda não existe). As três rotas do §3.3 respondem
**200**, e o documento de detalhe volta com as **34 chaves** — todas as 11 de
`ROUND_TRIP_KEYS` inclusive `flowSpec`, `triggerSpec`, `metadata` e
`canvasVersion`.

| rota | status | resposta |
|---|---|---|
| `GET /design/realms/{realm}/pipelines` | 200 | 1801 itens, 29 chaves cada |
| `GET /design/realms/{realm}/pipelines/{id}` | 200 | 34 chaves |
| `GET /runtime/realms/{realm}/deployments?environment=test` | 200 | 110 itens |

**A ingestão é alcançável**: existe um documento legível cuja forma dá para
espelhar num write. Três coisas que a resposta revelou e que mudam o desenho
dos blocos seguintes:

- **A listagem devolve 1801 itens, e cada um traz o `flowSpec` inteiro
  embutido.** O export local tem 201 pipelines — a diferença é que o
  `digibeectl` exporta o mais recente de cada um, por projeto, enquanto esta
  rota expõe cada `versionMajor`/`versionMinor`. Consequência prática: essa
  rota **não** serve para "achar o pipeline que eu vou atualizar" sem filtro;
  uma listagem inteira é um download de megabytes de flowSpec para ler um
  nome. Qualquer coisa que resolva pipeline por nome precisa filtrar do lado do
  servidor, e descobrir como se faz isso é parte do Bloco B.
- **O detalhe é um superconjunto da listagem**, não uma forma diferente: os 5
  campos a mais são `projectId`, `projectName`, `configurations`,
  `isTracingEnabled` e `tracingSamplingRate`. `projectId` estar só no detalhe
  importa, porque é ele que diz onde um pipeline novo nasce.
- **O deployment traz `activeConfiguration`, `accounts` e
  `environmentParameters`.** São exatamente os três que o runner de testes vai
  precisar para saber com que credencial e com que parâmetros o pipeline
  implantado está rodando — e são também a razão de o §3.3 não precisar de rota
  de métrica nenhuma para diagnosticar um deploy que subiu degradado.

E uma constatação sobre a credencial, que é a mais incômoda: **o JWT tem 1008
caracteres e sai da sessão interativa do `digibeectl`.** Um token de sessão
desses é curto de vida. Se a credencial de serviço acabar sendo isto e não um
par de chaves, o agente precisa de renovação antes de ter qualquer autonomia
(§ Antes de construir em cima).

---

## O que o A″ respondeu

Rodado em 2026-09-04 contra o projeto `isol`, com a credencial interativa.

**Criar funciona, e o `projectId` é IGNORADO EM SILÊNCIO.**
`POST /design/realms/{realm}/pipelines` com `{name, description, projectId}`
responde **200** e cria exatamente um pipeline, sem duplicata (`?name=`
confirma): `apla-probe`, id `4d775d68-4cd6-4686-b155-0ec24936832f`.

Só que ele **não nasce no projeto pedido**. Mandado para `isol`
(`2aa7ab0a-…`), o pipeline foi para `default` (`ee1803d8-…`) — e `isol` continua
reportando `amountOfPipelines=0`. Este é o **segundo** lugar onde esta API
aceita `projectId` e descarta: a listagem faz o mesmo (devolve os 1803). Os dois
respondem 200 fazendo outra coisa que não o pedido, que é a forma que custa uma
tarde.

A flag do CLI é `--project` ("project name or id"), então o campo do corpo tem
outro nome, ou o projeto entra na URL
(`POST /projects/{projectId}/pipelines` seria a forma REST óbvia). Não foi
chutado: cada tentativa errada de create é mais um pipeline de rascunho num
realm onde nada apaga pipeline, e a captura do canvas responde isso junto com o
verbo de update.

**Dívida de limpeza deste experimento:** um `apla-probe` vazio em `default`
(não em `isol`), que sai só pelo canvas.

Duas coisas da resposta que o Bloco B tem de respeitar:

- **A resposta do create é um ENVELOPE**, `{pipeline, configurations}` — não o
  documento de 34 chaves que o GET devolve. O id sai de `pipeline.id`, e a
  primeira versão deste script leu `$body['id']` e recebeu vazio exatamente por
  isso.
- **Um pipeline nasce em v0.0 com `draft: true`**, `flowSpec: null`,
  `canvasVersion: 0` e `metadata: {}` — a mesma casca vazia do CLI. O v0 tem
  consequência para `PipelineTestSuite::endpointUrl()`, que assume 1: um
  pipeline só é chamável depois de implantado, então falta saber se o deploy
  sobe a versão antes de mudar esse default.

**Atualizar É `POST` na COLEÇÃO com o `id` no corpo — verificado.**
`POST /design/realms/{realm}/pipelines` mandando o documento lido de volta com
o `id` e um `flowSpec` novo responde **200**, a contagem por nome **fica em 1**
(atualizou, não duplicou) e o `flowSpec` relido é **byte-idêntico** ao enviado.
O §3.3 estava certo sobre isso, e agora está provado em vez de suposto: **a
ingestão fecha.**

Dois detalhes que vêm com ela:

- **A raiz `start` foi ACEITA**, o que fecha a pergunta aberta da constatação 3:
  um documento ingerido pela API se enraíza em `start`, como os 201 do tenant —
  não em `disconnected-root:<uuid>`, que é o formato de colagem. O Bloco B tem
  as duas pontas confirmadas.
- **O upsert não sobe versão**: continua `v0.0` com `draft: true`. Escreve o
  rascunho. Falta saber se o deploy é o que versiona, e é o que decide o default
  de `versionMajor` em `PipelineTestSuite::endpointUrl()`.

**O caminho anterior, registrado para ninguém repetir:** atualizar não é `PUT`
nem `PATCH`. Ambos em
`/design/realms/{realm}/pipelines/{id}` respondem **405 Method Not Allowed** —
e 405, não 404, é a notícia boa: o recurso EXISTE (o GET nele funciona) e só o
verbo está errado.

A dedução seguinte não deu certo, e vale registrar para ninguém repetir: um 405
tem de anunciar os métodos aceitos (RFC 7231 §6.5.5) e esta API é claramente
Spring (problem details `about:blank`), que manda `Allow`. Só que **o gateway
remove o header** — `OPTIONS` responde 500 nas três rotas e nenhuma resposta
405 traz `Allow`. Não há como ler os verbos do servidor.

Sobrou `POST`, e **um probe sem handler eliminou a ambiguidade** em vez de
chutar. O truque é a ordem de despacho do Spring — casa método, DEPOIS negocia
content-type, DEPOIS liga o corpo — então um POST com `Content-Type: text/plain`
distingue "rota aceita POST" (415, recusado na negociação) de "rota não aceita
POST" (405) sem o handler nunca executar:

| | |
|---|---|
| `POST /pipelines/{id}` | **405** — some junto com PUT/PATCH: esse path é GET-only |
| `POST /projects/{id}/pipelines` | **405** — mas 405, não 404: o path EXISTE |
| `POST /pipelines` | funciona (foi o create) |

Duas conclusões. A primeira é que **não existe rota de update pendurada no
recurso individual**, o que deixa `POST` na COLEÇÃO como último candidato de pé
— exatamente o que o §3.3 documenta ("Upsert Pipeline", payload completo com
`id`). Deixou de ser um chute entre vários para ser o único que sobrou, e o
custo de estar errado é um segundo rascunho vazio no projeto que já tem um para
limpar.

A segunda parecia um ganho para o Bloco B e não é: **`/projects/{id}/pipelines`
é uma rota mapeada, mas o `GET` nela responde 403.** Existe e esta credencial
não alcança. Então resolver pipeline por **nome** (`?name=`, honrado) segue
sendo o caminho, e o descarte silencioso do `projectId` segue sem solução.

### O deploy é recusado por PERMISSÃO do TOKEN

`POST /runtime/realms/{realm}/deployments` responde **403
`INSUFFICIENT_PERMISSIONS`** — e num formato de erro diferente (o erro default
do Spring Boot, `{timestamp, error, message, errorCode, path}`, não o problem
details RFC 7807 do design), então é outro serviço. O Spring Security roda antes
do dispatcher, o que é por que veio 403 e não 405/415.

**Não é o usuário que falta permissão, é o token** — e a distinção é a resposta
inteira, ver § A credencial é um TOKEN do digibeectl. A primeira redação desta
seção concluía que o 403 era evidência CONTRA a frase do `AGENTS.md` que
sustenta a fronteira da credencial ("o alcance dela chega a criar e apagar
deployment em produção"). Estava errado, e a lista de permissões da plataforma é
o que mostra: `DEPLOYMENT:CREATE` é "deploy pipelines in **all environments**".
Qualquer token que implanta, implanta em produção. A frase do `AGENTS.md` está
certa; o que estava errado era supor que dava para escopar deploy em `test`.

**Nada apaga um pipeline.** `digibeectl delete` cobre `api-mgmt-credentials` e
`deployment`, não pipeline, e não foi probada nenhuma rota DELETE. É o que
transforma "chutar o nome do campo" de barato em caro: cada chute errado é um
rascunho permanente até alguém abrir o canvas.

Duas descobertas read-only do caminho, que o Bloco B usa:

- **`GET /design/realms/{realm}/projects` funciona** — 16 projetos, cada linha
  com `amountOfPipelines`. `isol` é `2aa7ab0a-fd48-4f88-b343-1afe446ac672`.
- **`?name=` é honrado; `?projectId=` é IGNORADO EM SILÊNCIO.** O segundo
  devolveu os 1803 itens em vez de dar erro — o mesmo descarte silencioso que o
  create faz com o mesmo campo, o que sugere que `projectId` simplesmente não é
  o nome dele em nenhuma das duas rotas. Resolver pipeline por NOME é o
  caminho, e evita o download de 1803 flowSpecs embutidos.

---

## A credencial é um TOKEN do digibeectl, e o deploy não é escopável

Isto responde de uma vez "que usuário a API usa" e corrige o que este documento
afirmava sobre o 403 do deploy.

**Não é um usuário.** O arquivo do `digibeectl` é um **token** criado no painel
em **Administration → Digibeectl → Create**, com título, **uma lista explícita
de permissões** e uma expiração (de 1 hora a 1 ano); a plataforma gera a chave
de encriptação, você define a senha do arquivo e baixa. É esse arquivo que vira
`~/.digibeectl/config.json` — o array de topo que encontramos é uma entrada por
token. Então a identidade das chamadas é o TOKEN, não o login de quem o criou:
o usuário pode ter permissão de deploy em `test` e o token não ter, que é
exatamente o caso aqui. `digibeectl get user-permissions -o` lista o que o token
corrente tem.

**E a correção.** Este plano dizia que o 403 era evidência contra a frase do
`AGENTS.md` que sustenta a fronteira da credencial. A lista de permissões diz o
contrário — a frase está certa:

- `DEPLOYMENT:CREATE` — "deploy pipelines in **all environments**"
- `DEPLOYMENT:DELETE` — "delete deployments in **all environments**"
- `DEPLOYMENT:CREATE:REDEPLOY` — "redeploy pipelines in **the selected
  environment**"

**Não existe permissão de deploy só em `test`.** Qualquer token que implanta,
implanta em produção — que é literalmente o que o `DigibeectlClient` afirma. O
desenho "credencial restrita a deploy em test" que a decisão de topologia
pressupõe **não é construível como estava escrito**.

> **Errado, e corrigido em 2026-09-11.** Isto vale para a tabela de permissões
> por PAPEL; o ACL de um token escopa por ambiente
> (`DEPLOYMENT:CREATE{ENV=TEST}`). Ver § O token ESCOPA deploy por ambiente. O
> parágrafo fica como registro do raciocínio que a evidência derrubou.

A terceira linha é a saída, e é melhor que a proposta original:
**`DEPLOYMENT:CREATE:REDEPLOY` é escopado por ambiente.** Então uma pessoa faz
o PRIMEIRO deploy do pipeline em `test`, e o agente só **redeploya** — que é
exatamente o que o loop de correção faz de qualquer forma, já que toda iteração
é um redeploy do mesmo pipeline. Fecha o loop com zero alcance em produção, e
faz `deployable_environments` deixar de ser o único guarda-corpo.

A lista do token do agente fica:

| permissão | por quê |
|---|---|
| `PIPELINE:READ` | ler o pipeline de volta, resolver por nome |
| `PIPELINE:CREATE` + `PIPELINE:UPDATE` | o create e o upsert verificados |
| `DEPLOYMENT:READ` | acompanhar o status do deploy |
| `DEPLOYMENT:CREATE:REDEPLOY` | redeploy, só em `test` |
| `CONFIGURATION:READ` | a coluna de permissões do próprio CLI exige para deploy |

Sem `DEPLOYMENT:CREATE`, sem `DEPLOYMENT:DELETE`, sem `PIPELINE:DELETE`.

Uma consequência operacional: **esses tokens expiram**, no máximo em um ano. Uma
credencial no droplet que morre calada no meio do loop merece data de expiração
monitorada, não um 401 de surpresa.

---

## A API, como referência

Tudo abaixo foi observado contra o realm real em 2026-09-04, com a credencial
interativa do `digibeectl`. Nada aqui é documentado pela Digibee — é o
resultado do A′/A″, e existe para o Bloco B não redescobrir.

Host: `core.godigibee.io` (o `endpoint` da config do `digibeectl`). O canvas é
servido de `www.godigibee.io` e chama esse outro.

| rota | verbo | resultado |
|---|---|---|
| `/design/realms/{realm}/pipelines` | GET | 200 — **1803 itens com o `flowSpec` embutido em cada um** (expõe cada versão). `?name=` é honrado; `?projectId=` é ignorado em silêncio |
| `/design/realms/{realm}/pipelines` | POST | 200 — **cria** sem `id`, **faz upsert** com `id`. Descarta `projectId`. Responde o envelope `{pipeline, configurations}`, não o documento |
| `/design/realms/{realm}/pipelines/{id}` | GET | 200 — 34 chaves, superconjunto das 29 da listagem (adiciona `projectId`, `projectName`, `configurations`, `isTracingEnabled`, `tracingSamplingRate`) |
| `/design/realms/{realm}/pipelines/{id}` | PUT / PATCH / POST | **405** — o path é GET-only |
| `/design/realms/{realm}/projects` | GET | 200 — 16 projetos, cada um com `amountOfPipelines` |
| `/design/realms/{realm}/projects/{id}/pipelines` | GET | **403** — mapeada, fora do alcance desta credencial |
| `/design/realms/{realm}/projects/{id}/pipelines` | POST | 405 |
| `/runtime/realms/{realm}/deployments` | GET | 200 — 110 em `test`; carrega `activeConfiguration`, `accounts`, `environmentParameters` |
| `/runtime/realms/{realm}/deployments` | POST | **403 `INSUFFICIENT_PERMISSIONS`** |
| qualquer uma | OPTIONS | 500, e o gateway remove o header `Allow` |

`DELETE` nunca foi probado, em nenhuma rota, de propósito.

Quatro coisas de forma que não estão na tabela:

- **Dois formatos de erro, dois serviços.** O design responde problem details
  RFC 7807 (`{type: "about:blank", title, status, detail, instance}`); o runtime
  responde o erro default do Spring Boot
  (`{timestamp, status, error, message, errorCode, path}`). Quem for tratar erro
  dos dois lados precisa ler os dois — `DigibeeApiException::fromResponse()` já
  tenta `message` e `error.message` por isso.
- **O detalhe do pipeline EMBUTE o objeto `realm` inteiro**, com informação de
  licença. E ela importa para o Bloco D: `licenseModel` é
  `CONSUMPTION_BASED_MODEL`, e no `digibeectl` as flags `--minReplicas` /
  `--maxReplicas` são descritas como "valid for Consumption Based Model realms"
  — então são essas, e não `--replicas`, que valem aqui. O `cluster` é
  `digibee-production-1`.
- **Um pipeline novo nasce `v0.0` com `draft: true`**, e o upsert **não sobe
  versão** — escreve o rascunho. O que versiona (provavelmente o deploy) segue
  sem observação, e é o que decide o default de `versionMajor` em
  `endpointUrl()`.
- **A config do `digibeectl` é um ARRAY de topo** de objetos de conta, cada um
  com o seu `endpoint`, `currentRealm`, `jwt` e `apikey` — não um objeto com os
  campos na raiz, que é o que o §3.1 sugere. `DigibeeAuthResolver` acha por
  busca aninhada e REPORTA o caminho JSON que usou (`0.jwt`), que foi como essa
  forma apareceu sem ninguém abrir o arquivo. O JWT tem ~1000 caracteres e sai
  de sessão interativa; a apikey, 32.

### O que ainda não se sabe

- **Como um pipeline vai para um projeto** pela API. `projectId` é aceito e
  descartado em duas rotas. Sabe-se agora que associar pipeline a projeto é uma
  OPERAÇÃO com permissão própria (`PROJECT:UPDATE:LINK-WITH-PIPELINE`, de papel)
  e que o CLI filia no `create pipeline --project` — então o contorno é criar a
  casca no projeto certo e só fazer upsert. A rota da API segue desconhecida, e
  deixou de estar no caminho crítico.
- ~~**Quem pode criar deployment.**~~ Respondido: um token com
  `DEPLOYMENT:CREATE{ENV=TEST}`, que existe e está em uso.
- ~~**O que versiona um pipeline**, e portanto o `v{n}` da URL de runtime.~~
  Deixou de importar para a URL: a plataforma REPORTA o endereço em
  `deploymentStatus.trigger` e o runner usa esse (§ O deploy). Continua sem
  resposta como pergunta sobre versionamento, e continua sem consumidor.
- **Se `POST /pipelines` valida o `flowSpec`.** O upsert aceitou um `start` com
  um step; um documento inválido pode passar igual e só quebrar no canvas — que
  é exatamente a falha que o `DigibeeFlowspecValidator` existe para pegar antes.

---

## Ordem de construção

A ordem não foi a do roadmap da especificação, por um motivo que se pagou: o
**Bloco E** (matriz de testes e avaliador de asserções) é lógica local pura,
não depende de nenhuma credencial nem de nenhuma rota, e é metade do valor da
feature — então ele andou em paralelo com A″, o único bloco que precisava de
autorização a mais, porque escreve.

Os quatro primeiros itens estão feitos e ficam abaixo como registro — os dois
últimos deles porque **B + C não precisavam de credencial nova**: o modo de
ingestão é lógica local e resolver pipeline por nome é leitura já verificada.
O que sobra (D, F, G) espera pelo token com a lista do § A credencial é um
TOKEN do digibeectl, e não por código: o loop de auto-correção precisa de um
deploy para ter sinal de runtime, e o portão de `prod` precisa de tudo verde
em `test` antes.

1. ~~**A′ — rodar o probe.**~~ Feito: as três rotas respondem e o pipeline
   volta com as 34 chaves (§ O que o probe respondeu).
2. ~~**A″ — verificar os verbos de escrita.**~~ Feito para o design: cria e
   atualiza (upsert por `POST` na coleção), com o `flowSpec` byte-idêntico na
   volta — é o que separa "a rota existe" de "o loop fecha" (§ O que o A″
   respondeu). De pé fica só a metade de runtime: o `POST` de deployment
   responde 403 para a credencial interativa, e destravá-lo é criar o token com
   a lista do § A credencial é um TOKEN do digibeectl, não escrever código.
3. ~~**E — matriz de testes.**~~ Feito: `BuildPipelineTestMatrix` mais os
   value objects em `App\Support\Digibee\Testing`. Sem rede, sem credencial.
4. ~~**B + C — modo de ingestão.**~~ Feito: `App\Enums\FlowspecTarget` no
   normalizador e no validador, `IngestFlowspec` + `DigibeeDesignClient` para
   o upsert verificado, e `SynthesizeTriggerSpec` a partir dos 183 exemplos
   reais do export (§ O que a ingestão escreve, § O que o triggerSpec
   sintetiza). A chave de modo NÃO chegou ao system prompt, e a seção explica
   por que isso ficou melhor assim.
5. ~~**D — runner de deploy.**~~ Feito, menos uma verificação: o corpo do POST
   segue sem prova contra a plataforma (§ O deploy). Pela API, não pelo
   `digibeectl`: a constatação 1
   e a decisão de topologia já descartaram instalar um binário de terceiro no
   droplet, e o CLI segue sendo a ferramenta da estação de trabalho (é o que
   `digibee:pipelines:pull` usa). O que se perde na troca é o `--wait`, então o
   polling do §3.3 passa a ser nosso — contra o
   `GET /runtime/realms/{realm}/deployments` que o probe já confirmou, e que
   devolve `activeConfiguration`, `accounts` e `environmentParameters` sem
   precisar de rota de métrica. O `POST` da mesma rota é o que falta verificar
   (403 para a credencial interativa, § Bloqueios), e num realm
   `CONSUMPTION_BASED_MODEL` os parâmetros de escala são os equivalentes de
   `--minReplicas`/`--maxReplicas`, não de `--replicas`.
6. **F — loop de auto-correção.** É a única parte que já existe pela metade:
   `FlowspecGenerationService` já normaliza, valida e re-prompta com os erros
   concretos, até `max_attempts`. O que muda não é o loop, é o **sinal** —
   hoje validação estática, aqui a resposta HTTP, o log e a métrica.
7. **G — portão de `prod`.** Só depois de tudo verde em `test`.

---

## O que a matriz produz

`App\Actions\Flowspec\BuildPipelineTestMatrix` deriva a bateria do §3.4 a
partir de um `{meta, flowSpec}`. Duas decisões carregam o bloco, e a segunda é
a que o §3.4 pede ao contrário.

**O contrato de entrada sai das referências `{{ message.* }}`.** Um flowSpec
nunca declara a própria entrada, mas todo campo que ele LÊ do payload aparece
como referência Double Braces em algum lugar dele. Medido sobre o export: 163
dos 201 pipelines leem ao menos um campo (a maioria entre 2 e 8), então para
quatro em cinco o corpo da requisição é derivado, não chutado. Os valores são
placeholders que NOMEIAM o campo (`"<cpf>"`), e o caso do caminho feliz sai
`blocked` listando o que precisa de valor real — ninguém inventa um CPF que
exista no SAP, e uma suíte que finge isso reporta problema de dado como defeito
de pipeline.

**Ela se recusa a fabricar payload de roteamento.** O §3.4 pede "payloads
engineered to trigger each choice condition", e para quase toda condição isso
não é computável: um `choice` que decide por
`#{body.RETURNING.STATUS} != '200'` depois de uma chamada REST está decidindo
sobre a resposta de outro sistema, e nenhum corpo de requisição força aquilo.
O movimento tentador — emitir um payload plausível de qualquer forma — é o pior
disponível, porque o caso então roda, pega o caminho feliz, e reporta a branch
como coberta.

O número que fecha essa discussão: construindo a matriz das 201 do export,
**dos 1205 casos de cobertura de branch apenas 3 eram sintetizáveis** (0,2%).
Fabricar os outros 1202 seria fabricar 1202 falsos verdes.

O subconjunto que É solúvel foi resolvido: igualdade simples sobre um campo do
corpo, num `choice` que nada antes reescreveu (`#{body.tipo} == 'X'` ou
`$.[?(@.tipo == 'X')]`). `PASS_THROUGH` tem só `log-connector` de propósito —
praticamente todo conector substitui o `message`, e errar para o lado
permissivo aqui é justamente afirmar que um payload dirige uma branch que ele
não alcança.

Medição completa das 201, sem nenhum erro de construção:

| | |
|---|---|
| casos gerados | 2500 (mediana 11 por pipeline, máximo 61) |
| executáveis sem ajuda | 1052 (42%) |
| casos de branch | 1205, sintetizados 3 (0,2%) |
| casos de tratamento de erro | 83 |
| pipelines que não leem entrada | 38 |

Três coisas menores que já custaram uma decisão:

- **`StatusExpectation` aceita `!5xx`, e isso é uma expectativa completa.** Para
  um corpo malformado, ninguém sabe se o pipeline correto responde 400, 422 ou
  200 com objeto de erro — a plataforma e o autor decidem isso juntos. O que é
  defeito nos três mundos é um **500** não tratado. Exigir status exato ali
  faria a categoria inteira reportar discordâncias sobre um contrato que ninguém
  escreveu, e um loop de auto-correção alimentado com isso corrige pipeline que
  está certo.
- **`JsonPath` recusa o que não suporta, em vez de não casar em silêncio.** As
  condições de `choice` são filtros JsonPath completos, então essa sintaxe vai
  ser colada numa asserção mais cedo ou mais tarde — e tratada como "não casou"
  ela transforma um `exists` em falso silencioso e um `missing` em passe
  silencioso. Errado pelo motivo errado é pior que não suportado.
- **O contrato de entrada é lido só do `flowSpec`.** Um pipeline lido da
  plataforma traz `metadata.disconnectedFlowSpecs` — blocos que alguém deixou
  no canvas, cujas referências inflariam o contrato com campos que nada vivo lê.

A forma da SAÍDA era o que a matriz não derivava, e passou a derivar — com uma
armadilha no meio que valia a medição (§ O contrato de resposta).

---

## O que a ingestão escreve

`App\Actions\Digibee\IngestFlowspec` mais `App\Support\Digibee\DigibeeDesignClient`.
O caminho é curto — normaliza, valida, resolve por nome, lê o documento de 34
chaves, devolve com o `flowSpec` trocado, relê e compara — e cada passo existe
por um motivo que já custou algo.

**A chave de modo é `App\Enums\FlowspecTarget`, e ela NÃO chegou ao system
prompt.** A previsão da constatação 3 era que os dois formatos atravessariam
normalizador, validador *e* prompt. Atravessam os dois primeiros; o terceiro
não, porque a diferença é mecânica — renomear uma branch e apagar `meta` — e um
segundo contrato de geração dobraria a superfície que toda regressão de prompt
precisa cobrir. O modelo continua emitindo UMA forma (colagem) e a ingestão
converte.

Renomear a branch de entrada é seguro por uma razão específica: ela é a única
branch que nenhum step referencia. `choice` aponta para nomes de branch e um
track de for-each se chama pelo id do próprio step; a entrada é a que ninguém
cita. Por isso é uma troca de chave e não uma reescrita de grafo — e a branch
renomeada continua PRIMEIRA, porque é onde os documentos armazenados a põem.

Seis coisas que a ingestão faz e que não são obviedades:

- **Valida ANTES de escrever, e recusa em qualquer erro.** A pergunta aberta da
  Fase 1 — se `POST /pipelines` valida `flowSpec` — segue aberta, e a resposta
  provável é "não": o upsert aceitou um documento de um step sem reclamar. Um
  documento inválido então é armazenado com sucesso e quebra quando alguém abre
  o canvas, que é exatamente a falha que o `DigibeeFlowspecValidator` existe
  para pegar primeiro.
- **Resolve por NOME, com o filtro no servidor e a igualdade no cliente.**
  `?name=` é honrado, mas nada publicado diz se ele casa exato ou por prefixo —
  e um prefixo devolveria `zfl-cadastro-cliente-v2` para
  `zfl-cadastro-cliente`, com a ingestão escrevendo o flowSpec no pipeline
  errado. Filtrar no servidor é o que evita baixar 1801 flowSpecs embutidos;
  conferir o nome no cliente é o que evita escrever no vizinho.
- **Recusa o `upsert` sem `id`.** É a mesma rota do create, então um documento
  sem `id` não falha: ele cria um segundo pipeline com o mesmo nome, e nada na
  plataforma apaga pipeline. Criar também é explícito (`create: true`), pelo
  mesmo motivo.
- **Relê e compara.** A rota responde 200 para create, para upsert e para um
  campo descartado em silêncio (`projectId`, duas vezes), então 200 não é
  evidência de escrita. A verificação é a mesma que o A″ fez à mão: o
  `flowSpec` relido tem de ser idêntico ao enviado.
- **`metadata.canvas` e `metadata.integrityHash` são REMOVIDOS.** Os dois são
  uma leitura do flowSpec que acabou de ser substituído, e o primeiro é o
  perigoso: ele embute o grafo de nós ANTIGO, nó de trigger incluído, então
  carregá-lo adiante desenharia o pipeline anterior sobre o novo. Apagar é
  seguro por medida, não por otimismo — 161 dos 201 pipelines do tenant não têm
  nenhuma das duas chaves, ou seja, a ausência é o estado comum.
- **Os contadores derivados viajam DESATUALIZADOS, e isso é dito no relatório.**
  Recalcular `counters` e `metadata.componentsCount` exigiria decidir o que a
  plataforma conta como step, capsule e subFlow — três definições que ninguém
  aqui verificou —, e um número confiantemente errado é pior que um número
  velho.

E uma que é sobre autoridade, não sobre forma: **um `triggerSpec` que já existe
é preservado.** Ele carrega o modo de autenticação e os métodos que alguém
configurou; sobrescrever isso ao trocar um flowSpec é mudar quem pode chamar o
pipeline sem ninguém ter pedido. Substituir é um pedido explícito.

O comando é `digibee:flowspec:ingest`, com `--dry-run` (resolve, valida e
relata sem escrever) e confirmação antes de qualquer escrita. Fica fora de
`routes/console.php` pelo mesmo motivo que o probe: uma pessoa roda, quando
quer.

---

## O que o triggerSpec sintetiza

`App\Actions\Flowspec\SynthesizeTriggerSpec` mais
`App\Support\Digibee\TriggerSpec`. Os defaults saem dos 183 triggers
armazenados do export, e as duas decisões interessantes são onde a medição
**não** foi seguida:

- **Content types vão para JSON**, embora o corpus diga XML com folga
  (`text/xml, application/xml` em 51 dos 65 triggers `http`). Essa maioria é um
  fato sobre o legado SOAP, não um default para o que está sendo escrito agora.
  O vocabulário do tenant é autoridade sobre como uma chave se CHAMA, nunca
  sobre o que um pipeline novo deveria falar.
- **`timeout` vai para 30000**, o default da plataforma, e não para os 90s e
  900s que o corpus tem de sobra. Um timeout generoso é uma decisão sobre um
  sistema específico.

Três coisas que a síntese recusa, e é aí que ela se parece com a matriz de
testes:

- **Um cron.** Um agendamento não sai do flowSpec, e chutá-lo não falha: roda,
  na hora errada, contra o que o pipeline toca. Vai para `missing` e o
  `triggerSpec` sai sem a chave.
- **Um nome de evento.** Um nome inventado escuta um evento que ninguém
  publica: o pipeline sobe, reporta saudável e nunca executa.
- **Um caminho REST.** Sem `uris`, o trigger responde no caminho default da
  plataforma — que é o que 32 dos 46 specs armazenados fazem. Um caminho
  inventado publica um endpoint num endereço que ninguém combinou, e o runner
  chama o default e toma 404.

Duas armadilhas de forma que só aparecem medindo:

- **`name` não é `type` no scheduler.** Todo outro tipo escreve o próprio tipo
  ali; o scheduler escreve o PRESET do canvas (`custom-scheduler` 25,
  `5min-scheduler` 7, `30min-scheduler` 5) — e o preset não amarra o cron (um
  `5min-scheduler` roda `0 0 23 ? * * *`). A síntese sempre diz
  `custom-scheduler`, o único dos três que não afirma nada sobre o horário ao
  lado.
- **`uris` não é chave do trigger `http`**, em nenhum dos 66 specs armazenados
  — é do `rest`. Herdar do construtor comum escreveria uma chave que o canvas
  não lê.

E uma regra que é de segurança: **`DigibeeTriggerAuth::None` existe e nunca é
default.** A convenção do tenant é "autenticado" (`basicAuth` em 55 dos 56
`http` que têm a chave, `keyAuth` em 21 dos 28 `rest`), então é ela que vale
quando ninguém escolhe. Um endpoint aberto é uma decisão dita em voz alta, não
um valor que chega porque faltou argumento — e os três flags saem como um
CONJUNTO com exatamente um `true`, porque dois seria uma pergunta sobre
precedência que ninguém respondeu.

---

## O que validar os 201 encontrou

Rodar o `DigibeeFlowspecValidator` contra os pipelines que o tenant realmente
roda é uma checagem que a era só-de-colagem não tinha motivo para fazer. Ela
achou **duas regras que não descreviam nada real**, e as duas importam para o
Bloco F: o sinal daquele loop inclui validar um pipeline lido DE VOLTA da
plataforma, então uma regra falsa manda o modelo "corrigir" pipeline que já
estava certo — a mesma forma da condição `simple` de `choice` e das chaves de
data do scrubber, as duas vezes anteriores em que isso aconteceu.

| | antes | depois |
|---|---|---|
| pipelines limpos (target `platform`) | 88 de 201 | **188 de 201** |

- **O track de exceção é OPCIONAL.** `params.onProcess` é uma branch real em
  404 das 404 referências do corpus; `params.onException` simplesmente não está
  lá em 296 das 384 — e nunca aponta para um nome inexistente. Exigir os dois
  rejeitava 101 dos 201 pipelines vivos. Uma referência PRESENTE apontando para
  branch que não existe continua erro: isso é typo, não omissão.
- **`iterators` e `replica` são escopos Double Braces documentados**, e os dois
  faltavam. `{{iterators.<for-each-alias>.current}}` é como a referência do
  próprio For Each lê o item da iteração, e `{{replica.instance_variable_name}}`
  é o padrão do guia de multi-instância. Enquanto faltavam, um corpo de
  for-each escrito do jeito documentado voltava para o modelo como "escopo
  desconhecido" até as tentativas acabarem — e a regra 6 do system prompt
  ensinava a lista curta, então o modelo nem podia acertar.

Os 13 que continuam falhando são achados sobre os pipelines, não sobre o
validador: 29 credenciais literais (todas dentro de
`metadata.disconnectedFlowSpecs`, blocos que alguém deixou no canvas — 3
pipelines falham SÓ por isso), 2 `doubleBracesAlias` duplicados, 2 aliases
inexistentes e 3 choices com problema de condição. Vale registrar que um
pipeline lido de volta pode falhar a validação por causa de lixo de canvas que
o flowSpec vivo não usa, o que é uma decisão a tomar no Bloco F: validar o
documento inteiro ou só o `flowSpec` conectado.

---

## O contrato de resposta

Um pipeline nunca declara o que devolve, mas um `json-generator` ou um `jslt`
no fim de uma branch **nomeia as chaves literalmente** — é a única fonte
honesta para o caminho feliz asserir mais que "voltou um corpo".
`ShapeTemplate` lê essas chaves e
`BuildPipelineTestMatrix::responseContract()` decide o que dá para afirmar.

**A armadilha é que a forma mais comum do corpus não é uma resposta.** 105 dos
178 terminais que declaram forma emitem `{code, body, Content-Type}`, e a
referência do trigger HTTP da Digibee é explícita sobre os três: `code` é o
status que o endpoint retorna, `body` é o corpo (e precisa ser string),
`Content-Type` é o tipo dele. Ou seja, é o ENVELOPE do trigger — quem chama
nunca vê esses nomes. Asserir `$.code` teria falhado contra a forma mais comum
do tenant inteiro, e num loop de auto-correção isso é pior que inútil: manda
reescrever pipeline que está certo.

Três regras seguram a afirmação:

- **Um terminal desconhecido anula o contrato.** Se alguma branch termina numa
  chamada REST ou num Object Store, a resposta daquele caminho é desconhecida —
  e uma afirmação que vale para as outras três é uma afirmação que falha toda
  vez que a quarta rodar.
- **Só a interseção é afirmada.** O caminho feliz pega UMA branch e ninguém
  sabe qual, então uma chave só entra se TODOS os terminais a nomeiam.
- **O status só é estreitado quando todos concordam.** `code` é literal em 55
  dos 105 envelopes; um pipeline com branch de sucesso e de erro discorda
  (200 × 500) e a expectativa de família (`2xx`) fica de pé.

O ganho medido sobre as 201: **18 pipelines passam a asserir chaves reais (30
asserções novas) e 1 ganha status exato**. É bem menos que os 70 com forma
derivável, e a diferença é justamente a honestidade — nos 51 envelopes o `body`
é `{{ TOSTRING(...) }}`, que o documento genuinamente não descreve.

**O tipo da resposta também é afirmado agora, e a fonte não é a que eu esperava.**
`Content-Type` é literal nos 105 envelopes, o que parecia dar a afirmação de
graça — mas o que importa é a concordância POR PIPELINE, e aí são **3 de 201**:
o normal é a branch de sucesso responder JSON e a de erro responder XML, e o
caminho feliz não sabe qual pegou.

Quem resolve é o TRIGGER. `triggerSpec.responseContentTypes` restringe toda
resposta que o endpoint pode dar, então quando ele declara exatamente um tipo a
afirmação vale para qualquer branch. No legado isso quase não acontece (53 specs
listam três tipos, 2 listam um), mas vale para **tudo que este app gera**, já
que `SynthesizeTriggerSpec` emite exatamente um — a afirmação cresce com os
pipelines que a gente escreve, que é o lado certo.

Três detalhes que a comparação exige:

- **Compara MEDIA TYPE, não o header.** A plataforma manda
  `application/json;charset=UTF-8` e o flowSpec declara `application/json`;
  igualdade no valor cru reprova toda resposta correta por causa de um charset.
- **Header ausente com afirmação de pé é FALHA**, não "não deu para checar": o
  parser de quem chama precisa do tipo, e "o pipeline não mandou nenhum" é
  exatamente o defeito que isso existe para pegar.
- **A afirmação anda só no caminho feliz.** Os casos de erro e de contrato
  aceitam faixa de status de propósito (`!5xx`), e parte dessas respostas vem do
  gateway da plataforma e não do fluxo — asserir o tipo declarado contra um erro
  de gateway reprova por algo que o pipeline não fez.

---

## O runner, sem a rede

`App\Actions\Digibee\RunPipelineTestSuite` é a metade do Bloco D que não
precisa de deploy nenhum para estar correta: a matriz vem do Bloco E, a
avaliação vem de `PipelineTestCase::evaluate()`, e no meio há uma chamada HTTP
por caso. Escrito e testado contra `Http::fake()`, ele deixa o Bloco D como
"apontar para um deployment" em vez de "construir um subsistema".

Quatro propriedades, cada uma um jeito de o relatório mentir:

- **Recusa ambiente fora de `deployable_environments`.** Uma bateria sintética
  é tráfego hostil de propósito — payload malformado, campo faltando, o que a
  branch exigir — e os pipelines que ela acerta escrevem em SAP, VTEX e
  BigQuery. "Pode implantar aqui" e "pode disparar isso aqui" são a mesma
  pergunta, então compartilham UMA lista em vez de duas para manter em sincronia.
- **Nunca envia um caso BLOQUEADO.** Eles carregam placeholders que NOMEIAM o
  campo (`"<cpf>"`) — exatamente o que o Bloco E se recusou a passar por dado.
  Mandar isso põe valor inventado num sistema real e reporta a recusa como
  defeito do pipeline.
- **Não repete.** O cliente de design repete falha transitória porque uma
  leitura é idempotente; um caso de teste não é. Um 500 aqui é o SINAL, e
  re-disparar um POST que já rodou pela metade duplica o que ele escreveu antes
  de falhar.
- **Distingue "recusado na porta" de "falhou".** Se todos os casos voltarem
  401/403 e nenhuma credencial foi passada, `SuiteRun::refusedForCredentials()`
  diz isso. É a entrada mais enganosa que esta feature pode dar a um modelo: um
  muro de 401 é idêntico a um pipeline que rejeita tudo.

**E agora ela tem quem a dispare.** `digibee:pipeline:test` resolve o pipeline,
constrói a matriz a partir do documento armazenado (inclusive o
`triggerSpec`, de onde sai a afirmação sobre o tipo da resposta), pega a URL do
deployment e roda os casos executáveis. Por um tempo o runner não tinha chamador
nenhum — o mesmo problema que a matriz tinha antes de virar painel: escrito,
testado e inalcançável.

A credencial do endpoint (`EndpointCredential`) **não é a do design**, e não sai
de configuração: quem consome um pipeline implantado usa Basic Auth, API key ou
um JWT que a plataforma emitiu, e isso pertence a quem é dono daquela
integração. Mandar o token do design para o host de runtime seria despachar uma
credencial de realm inteiro para outro serviço. O comando **pergunta** o valor
com entrada escondida (`secret()`) em vez de aceitá-lo por flag: uma flag põe a
credencial no histórico do shell e no `ps`. `--auth=none` é como se diz em voz
alta que o endpoint é aberto.

---

## A bateria na tela

`BuildPipelineTestMatrix` existia desde o Bloco E **sem nenhum leitor**: derivava
uma mediana de 11 casos por documento e nada no app mostrava um, então a dívida
de cobertura que ela reporta com tanto cuidado ("preencha valores reais para:
cpf") só era visível de dentro do PHP. Os casos BLOQUEADOS são o ponto — um caso
que alguém ainda deve só serve se alguém ler.

`x-flowspec.test-matrix` é um `<details>` na própria mensagem que produziu o
flowSpec: cobertura no resumo, um item por caso (categoria, método, status
esperado, tipo de resposta, branch que cobre, e o motivo quando está bloqueado)
e o documento `testSuite` do §3.4 inteiro com "Copiar bateria" — é o artefato
que viaja entre gerador, runner e loop, então ele é oferecido inteiro e não como
uma leitura enfeitada de si mesmo.

Duas escolhas:

- **Só documento VALIDADO ganha bateria.** Um documento com pendências também
  constrói uma (a matriz é tolerante de propósito), mas os casos descrevem um
  fluxo que o validador já recusou — oferecer isso é oferecer plano de teste
  para o que ninguém pode implantar.
- **Nenhuma URL é renderizada.** O nome do pipeline sai do título da conversa
  em slug, porque nada foi ingerido ainda; montar `endpointUrl()` a partir
  desse palpite seria mostrar um endereço que não existe.

---

## O que o token escopado respondeu

Rodado em 2026-09-11 com um token criado em Administration → Digibeectl, com
cinco permissões e nada além delas. As três rotas de leitura respondem **200**,
o documento volta com as 34 chaves, e o upsert **escreveu e releu idêntico** no
`apla-probe` — sem duplicar: o realm continua com exatamente um pipeline com
esse nome. **A metade de design fecha sob credencial escopada, de dentro do
app.**

Três achados, e cada um corrige alguma coisa que este plano afirmava.

### O esquema do header depende de QUAL credencial é

A sessão interativa do `digibeectl` vai **crua** no `Authorization` — está na
definição de pronto desta fase e continua verdade. Um TOKEN do digibeectl é o
oposto exato: cru responde **401**, `Bearer ` responde **200**. Medido com um
GET por variante contra o realm real:

| header | resposta |
|---|---|
| `Authorization: <jwt>` + `apikey` | 401 |
| `Authorization: Bearer <jwt>` + `apikey` | **200** |
| `Authorization: <jwt>` sozinho | 401 |
| `apikey` sozinho | 401 |
| `Bearer <jwt>` + `x-api-key` | 401 |

Custou uma rodada de 401 que parecia credencial errada, e não era. Quem decide
agora é o próprio token: o payload de um token ACL carrega `useTokenACL: true`,
uma sessão não — então `DigibeeCredentials::headers()` escolhe o esquema lendo
o JWT em vez de ler uma flag de configuração, cuja falha seria um 401 silencioso
que se lê como "a credencial está errada".

### O token ESCOPA deploy por ambiente — a § da credencial estava errada

A seção § A credencial é um TOKEN do digibeectl conclui que "não existe
permissão de deploy só em `test`", a partir da tabela de permissões por serviço
("DEPLOYMENT:CREATE: deploy pipelines in **all environments**"). **Isso vale
para papéis, não para tokens.** O ACL do token real traz:

```
PIPELINE:READ, PIPELINE:CREATE, CONFIGURATION:READ,
DEPLOYMENT:READ{ENV=TEST}, DEPLOYMENT:READ{ENV=PROD},
DEPLOYMENT:CREATE:REDEPLOY{ENV=TEST}, DEPLOYMENT:CREATE{ENV=TEST}
```

`{ENV=TEST}` é o que a tabela de papéis não expressa. Consequência direta: **o
desenho "uma pessoa faz o primeiro deploy, o agente só redeploya" não é mais
necessário** — o agente pode criar o deployment também, confinado a `test`. O
guarda-corpo continua sendo `deployable_environments` mais o ACL do token, agora
em duas camadas independentes em vez de uma.

`php artisan digibee:design:probe --diagnose` imprime o ACL, então "esta
credencial pode fazer o que eu vou pedir" é uma pergunta respondível **offline**,
antes de um 403 responder em produção.

### `PIPELINE:UPDATE` não existe para token, e não faz falta

A lista de permissões do token espelha as OPERAÇÕES do `digibeectl`, e o CLI tem
`get pipeline` e `create pipeline` — operação de update não existe, que é
exatamente por que esta feature usa a API de design. O upsert é `POST` na mesma
coleção do create, então quem autoriza é `PIPELINE:CREATE`: verificado com o
write acima, com o mesmo token que não tem `PIPELINE:UPDATE` nenhum.

Vale o mesmo para o projeto: não há permissão de token para associar pipeline a
projeto (`PROJECT:UPDATE:LINK-WITH-PIPELINE` é de papel, não de token), e o CLI
filia no `create pipeline --project`. O caminho limpo é criar a casca no projeto
certo à mão e deixar o agente só fazer upsert em pipeline que já existe — o que
contorna o `projectId` descartado em silêncio em vez de resolvê-lo.

### Uma nota operacional

O token vale **até 2036-09-10**. Isso responde a dúvida de "§ Antes de construir
em cima" sobre renovação — não precisa de nenhuma — e levanta outra: é uma
credencial de dez anos morando em `.env`, que faz deploy em `test`. No droplet
ela tem de ser variável de ambiente CRIPTOGRAFADA, e não há expiração para
limitar um vazamento.

---

## O deploy

`App\Actions\Digibee\DeployPipeline` mais `digibee:pipeline:deploy`. Implanta,
espera a plataforma estabilizar e devolve um `DeploymentReport` — que é o que o
Bloco F vai ler para decidir se o pipeline merece ser testado, corrigido, ou se
nem subiu.

**A plataforma DIZ a URL, e é ela que vale.** `deploymentStatus.trigger` volta
como uma string JSON com uma lista chave/valor:

```
[{"key":"endpoint","value":"https://test.godigibee.io/pipeline/leomadeiras/v1/zfl-bloq-desbloq-cliente"}]
```

Isso confirma a constatação 5 (o ambiente é o HOST, `v{n}` é a versão major) e
faz melhor que confirmar: o runner passa a receber essa URL em vez de compor
uma. Compor continua existindo como fallback para pipeline ainda não implantado,
mas a falha que o mapa `runtime_hosts` existe para evitar — montar o endereço
errado e chamar outro ambiente com o relatório dizendo este — deixa de ser
possível quando a plataforma já respondeu qual é.

Quatro propriedades, e as duas primeiras são o guarda-corpo que o §5 da
especificação pediu no verbo errado:

- **Ambiente fora de `deployable_environments` é RECUSADO**, antes de qualquer
  chamada. Abrir produção é uma edição de configuração feita por uma pessoa,
  nunca um argumento que o agente escolhe.
- **O TOKEN é perguntado primeiro.** O ACL vem dentro do próprio JWT, com
  escopo de ambiente (`DEPLOYMENT:CREATE{ENV=TEST}`), então "esta credencial
  não pode implantar aí" é respondível antes da requisição em vez de virar um
  403 depois. Uma sessão interativa não declara papéis e fica por conta da
  plataforma — chutar ali recusaria um deploy que funcionaria.
- **A espera tem teto e reporta o que viu.** "Não estabilizou em N segundos" é
  um TERCEIRO resultado, diferente de recusado e de quebrado; juntar os três é
  como um loop de correção começa a reescrever pipeline que só estava lento.
- **`availableReplicas: "0/0"` não é falha.** É o estado normal de 81 dos 111
  deployments de `test` — autoscaling estacionou o pipeline. Ler isso como
  defeito reprovaria três quartos do tenant.

Vocabulário de status observado nos 111: `SERVICE_ACTIVE` 108, `SERVICE_ERROR`
2, `DELETING` 1. `DeploymentStatus::Unknown` existe porque essas strings não são
documentadas: um status novo tem de ler como "não estabilizado, não saudável",
nunca derrubar o loop e muito menos passar por sucesso.

### O que a verificação do corpo encontrou (2026-09-11)

O corpo foi probado contra a plataforma, e o que ele ensinou vale mais que o
formato: **nesta rota um 403 e um 404 podem ser informação sobre o PAYLOAD.**

**1. O ambiente é QUERY, não corpo.** A forma óbvia — `environment` dentro do
corpo, espelhando `--environment` do CLI — respondeu **403 "Access denied"**. E
não era permissão: a checagem roda antes do handler e é escopada por ambiente,
então uma requisição cujo ambiente o servidor não enxerga é avaliada contra nada
e negada. O mesmo valor em `?environment=` passou direto para o handler. O
sinal de que o 403 era de payload: ele não mudava com o ambiente —
`environment=test` e `environment=nao-existe` deram negações idênticas.

**2. O pipeline se chama `pipelineId`** — e as duas mensagens dizem qual é
qual, ao contrário de como eu li na primeira vez. Com `pipelineId` o handler
RESOLVE o pipeline: chegou a validar o trigger dele ("Could not redeploy this
pipeline due to an invalid trigger spec - missing type"), o que só consegue
tendo encontrado o pipeline. Com `id` ele nunca resolve nada (404 "No such
entity"), qualquer que seja o valor.

**3. O segundo id é `runtimeConfigurationId`, e ele saiu do BINÁRIO.** Com
`pipelineId` e um trigger válido, a rota responde 500 "The given id must not be
null". Quinze formas não acharam o campo — `id`, `configurationId`,
`configuration.id`, `activeConfiguration.id`, os ids das `configurations` do
pipeline, o id do deployment vivo, sozinhos e combinados.

Adivinhar não era o caminho, e capturar no devtools também não precisou ser: o
`digibeectl` é um binário **Go**, Go embute os literais de string, e o CLI monta
esse corpo por concatenação. `strings` no executável imprime o template em
pedaços:

```
{"pipelineId": "     ,"runtimeConfigurationId": "     ,"replicaInstanceName": "
,"allowAllUsers": true     ,"owner": "
```

Com `{pipelineId, runtimeConfigurationId}` a rota para de reclamar de id. Os
outros três campos não foram necessários. **Vale guardar o método**: um binário
Go publicado é documentação de API que ninguém escreveu — `strings` nele também
devolveu as tags `json:` de todos os modelos e as rotas
(`/runtime/realms/`, `/deployments?environment=`, `?pipelineName=&deploymentId=`).

**4. A configuração de runtime é o SEGUNDO lugar por onde produção entra.** Um
pipeline tem seis configurações — três tamanhos × dois ambientes — e cada uma
carrega `environment.name` (`test`/`prod`). Quem as nomeia é
`GET /design/realms/{realm}/pipelines/{id}/configurations`; o array
`configurations` do próprio documento só traz id e versão, sem nome e sem
ambiente, então de lá não dá para saber qual id é qual.

O deploy escolhe por tamanho **e** ambiente, e recusa quando não acha
exatamente uma. Mandar o id da configuração de `prod` numa requisição apontada
para `test` seria, na melhor hipótese, incoerente — e o guarda-corpo de
`deployable_environments` não pega isso, porque o ambiente da query continuaria
dizendo `test`.

Isso também explica o "seis configurações" que este documento já tinha
estranhado duas vezes: não é acúmulo, é 3 × 2.

**Uma inferência anterior caiu no caminho.** Este documento afirmou que "só
versão publicada implanta", a partir de os 111 deployments serem todos v1 — e o
canvas implantou uma **v0.2**. O que a plataforma recusa não é a v0: é outra
coisa. O guarda que eu tinha construído sobre essa leitura recusava um deploy
legítimo e foi removido; "os 111 são v1" descrevia o parque, não uma regra.

Também apareceu um status novo, `REDEPLOY`, que não estava na amostra dos 111 —
que é precisamente o argumento para `DeploymentStatus::Unknown` existir.

### A bateria deu VERDE contra um pipeline que não existia

O primeiro `digibee:pipeline:test` real reportou **"Bateria verde"** contra um
pipeline não implantado. Três coisas em cadeia, e as três eram minhas:

1. A suíte **compôs uma URL `/v0/`**, porque `versionMajor` saiu do documento
   (0) e nada recusava isso. Esse endereço não existe.
2. Tudo respondeu **404**.
3. Os casos negativos esperam `!5xx` — e 404 não é 5xx —, então **passaram**. O
   caso que prova alguma coisa (o caminho feliz) estava BLOQUEADO esperando um
   CPF real, ou seja, os únicos casos que rodaram foram os que não afirmam nada.

É exatamente o falso verde que o Bloco E se recusa a produzir com payload
inventado, chegando pela porta dos fundos. Os três elos estão fechados:
`endpointUrl()` recusa `versionMajor < 1` (um pipeline sem versão publicada não
tem URL), `SuiteRun::nothingAnswered()` trata "todos os casos 404" como nada
respondendo — um 404 isolado continua sendo resposta legítima de um pipeline —,
e "verde" agora exige que o caminho feliz tenha RODADO
(`provenByHappyPath()`); caso contrário o relatório diz que os negativos
passaram e que nada mostrou o pipeline funcionando.

### Uma versão é uma LINHA, com id próprio

Salvar no canvas não alterou a v0.0: criou a **v0.1 como outro documento, com
outro id**. Por isso a listagem de design devolve 1801 itens para ~200
pipelines — cada `versionMajor.versionMinor` é uma linha. `latestByName()`
escolher a maior versão é o comportamento certo, e foi o que resolveu para a
v0.1 recém-salva.

### Por que o `apla-probe` não sobe — e o que NÃO é a causa

A v1.0 foi promovida e implantada pelo canvas, e o deployment fica em
`STARTING` com 0/1 réplicas e "Pipeline Configuration is invalid
… Node is expected to be an object node". Nada responde na URL, que é o que a
bateria (corretamente) reportou como "nada respondendo".

A pista concreta está no `activeConfiguration` do deployment, comparado com o de
um pipeline que roda:

| | `apla-probe` | saudável |
|---|---|---|
| `fallback` | **null** | `{failureThreshold, replicas}` |
| `scalerTrigger` | **null** | lista de 2 |
| `cooldownPeriod` | null | 300 |
| `initialCooldownPeriod` | null | 180 |
| `pollingInterval` | null | 3 |
| `useCachedMetrics` | null | true |
| `autoscaling` | false | true |

`fallback: null` onde se espera um objeto casa exatamente com o erro do Jackson.
Ou seja: o pipeline foi implantado com uma configuração de escala **em branco**,
e o engine não consegue lê-la. É configuração de deploy, não conteúdo do
flowSpec.

E a lista nomeada confirma: **as seis configurações do `apla-probe` têm
`cooldownPeriod: null` e `autoscaling: false`**, enquanto as de um pipeline que
roda têm 300 e `true`. Por isso nem o canvas nem a API conseguem subi-lo — o
deploy pela API chega ao controlador e morre lá ("Error deploying to
controller"), que é a mesma parede por outro caminho. Falta alguém preencher a
configuração de deploy do pipeline; nenhuma rota de escrita para isso foi
procurada.

**E uma correção de algo que este documento afirmou.** Eu escrevi que o
`apla-probe` tinha acumulado "seis `configurations`, uma por upsert". Errado:
**todo pipeline do tenant tem exatamente seis** — `token-digibee`,
`zfl-bloq-desbloq-cliente` e `get-token-cws` também. Seis é o conjunto padrão da
plataforma, e as nossas escritas não multiplicaram nada. O que difere é que as
seis do `apla-probe` têm `cooldownPeriod: null` e as dos outros não — ou seja,
elas nunca foram configuradas, não foram poluídas.

---

## Definição de pronto da Fase 1

- [x] Credencial resolvida por ambiente primeiro, arquivo do `digibeectl`
      depois, com a origem de cada campo reportada e **nenhum valor** impresso.
- [x] JWT enviado no `Authorization` com o esquema que a credencial pede:
      **cru** para sessão interativa, **`Bearer`** para token escopado. As duas
      metades foram medidas contra o realm, e cada uma responde 401 com o
      esquema da outra (§ O esquema do header depende de QUAL credencial é).
- [x] Probe só faz GET, e isso é propriedade da classe, não do argumento.
- [x] 401, 403 e 404 são três notícias diferentes, reportadas juntas numa
      rodada, em vez de a primeira encerrar a execução.
- [x] Um 200 que não devolve `flowSpec` é reportado como loop inalcançável.
- [x] Probe rodado contra o tenant, com a forma real das respostas anexada
      aqui (§ O que o probe respondeu).
- [x] Verbos de escrita de design verificados: cria, atualiza (upsert por
      `POST` na coleção) e o `flowSpec` sobrevive byte-idêntico.
- [ ] Deploy verificado. **Não é mais bloqueio de permissão** — o token traz
      `DEPLOYMENT:CREATE{ENV=TEST}` e o Bloco D está construído e testado; o que
      falta provar é o CORPO do POST, que custa um deploy real em `test`
      (§ O que o deploy ainda NÃO provou).
- [x] Matriz de testes derivada do flowSpec, com o que não dá para derivar
      reportado como dívida de cobertura em vez de payload inventado.

---

## Bloqueios

O bloqueio de leitura caiu; sobraram quatro.

**Atualização de 2026-09-11: o token existe, e os bloqueios 1, 2 e 4 caíram.**
As leituras e o upsert foram verificados com ele (§ O que o token escopado
respondeu), e o ACL traz `DEPLOYMENT:CREATE{ENV=TEST}` — então nem o deploy nem
o primeiro deploy dependem mais de alguém. O que sobra do bloqueio 3 é a filiação
a projeto, contornada criando a casca no projeto certo. O texto abaixo é o
registro de como o problema se apresentava.

**1. O deploy precisa de um token novo, e de uma pessoa no primeiro deploy.**
Resolvido no desenho, não no código: § A credencial é um TOKEN do digibeectl
explica por que o 403 acontece, por que "deploy só em test" não existe como
permissão, e por que `DEPLOYMENT:CREATE:REDEPLOY` mais um primeiro deploy
humano é a saída. O que falta é criar o token com essa lista e verificar o
`POST /runtime/.../deployments` com ele.

**1b. A redação antiga desta seção dizia o contrário e ficou errada.** A metade de design
está verificada — cria e atualiza, com round-trip byte-idêntico. O que falta é
`POST /runtime/realms/{realm}/deployments`, e ele responde **403** para a
credencial interativa. Isso não se resolve escrevendo código: é uma pergunta
para quem administra o realm — *quem pode criar deployment em `test`, e essa
permissão pode ser concedida a um usuário de serviço?*

Se a resposta for "ninguém fora de um time específico", os Blocos D e G mudam
de forma e a autonomia do agente para na ingestão. Continua sendo a maior parte
do valor (gerar, ingerir, e a matriz de testes para alguém rodar), mas é uma
feature diferente da que o §1.2 descreve.

**2. Três rotas respondem 403 para esta credencial** —
`GET /projects/{id}/pipelines`, `POST /runtime/.../deployments`, e o filtro por
projeto que sobra sem solução. A credencial interativa é bem mais estreita do
que este plano assumiu, o que é boa notícia para a fronteira de segurança e má
para o roadmap: reforça que um usuário de serviço restrito é viável, e levanta a
dúvida de se `DEPLOYMENT:CREATE` é concedível.

**3. O nome do campo de projeto.** O create aceita e descarta `projectId`, e
`/projects/{id}/pipelines` não é a rota alternativa (403). Um `apla-probe` vazio
segue em `default` até alguém apagar pelo canvas.

**4. O usuário de realm restrito ainda não existe.** O que o probe provou, ele
provou com a credencial interativa ampla. Que a rota responda 200 para um
desenvolvedor não diz nada sobre ela responder para um usuário limitado a
`PIPELINE:READ` + `DEPLOYMENT:CREATE` em `test` — e é esse usuário que a
decisão de topologia pressupõe. Enquanto ele não existir, nada disso vai para
o droplet.

## Antes de construir em cima

- ~~**`AGENTS.md` ainda afirma a regra absoluta**~~ — feito em 2026-09-11: a
  seção § `digibeectl` never runs on the server ganhou a distinção entre a
  credencial de login (que segue proibida no servidor, e é a que
  `digibee:pipelines:pull` usa) e o token escopado (que é outro objeto de
  risco). O binário continua fora do servidor; o que mudou é que existe uma
  credencial estreita para a API.
- **O probe não está em `routes/console.php`, e não deve entrar.** Nada nele é
  periódico: roda uma vez, por uma pessoa, quando a credencial ou a rota muda.
- ~~**Uma sessão do `digibeectl` é curta.**~~ Respondido, e melhor do que se
  temia: o token escopado vale **até 2036**, então não há renovação a construir.
  A preocupação inverteu de sinal — é uma credencial de dez anos, sem expiração
  que limite um vazamento, e por isso variável de ambiente CRIPTOGRAFADA no
  droplet.
