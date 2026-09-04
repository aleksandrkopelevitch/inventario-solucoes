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
| A — resolução de credencial + cliente HTTP + probe read-only | **feito** — 13 testes em `tests/Feature/DigibeeDesignProbeTest.php`, suíte inteira verde (1237) |
| A′ — **rodar** o probe contra o tenant | **feito** (2026-09-04) — as três rotas respondem, e o pipeline volta com as 34 chaves (§ O que o probe respondeu) |
| A″ — verificar o **verbo de escrita** | **não começado** — só GET foi verificado (§ Bloqueios) |
| B — modo de ingestão no normalizador/validador | não começado, desbloqueado por A′ |
| C — síntese de `triggerSpec` | não começado |
| D — runner de deploy (via `digibeectl`) | não começado |
| E — matriz de testes sintéticos + avaliador de asserções | não começado, **não depende de A′** |
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

Logo, um usuário de realm dedicado, limitado a ler pipelines e criar deployment
em `test`, é um **objeto de risco diferente** daquele sobre o qual a regra foi
escrita. É isso que torna a reversão defensável, e é exatamente isso que
precisa ser confirmado com quem administra o realm antes de qualquer coisa ir
para o droplet — que, vale lembrar, é compartilhado com outros dois apps.

`digibee:pipelines:pull` **continua fora do servidor** de qualquer forma: ele
segue precisando da credencial ampla.

---

## O que o reconhecimento encontrou

Cinco constatações, todas medidas contra o `digibeectl` instalado e contra os
201 pipelines do export local (`storage/app/private/digibee-pipelines/`).

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

Consequência para o §3.3 da especificação: o `DigibeeDriver` **não** deve
falar REST com a plataforma para deploy, status, métricas e histórico — isso
tudo já tem interface suportada, e um wrapper (`DigibeectlClient`) neste
repositório. A rota não documentada é para **uma** operação: o upsert do
flowSpec.

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

### 5. O guard-rail do §5 está no verbo errado

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

## Ordem de construção

A ordem não é a do roadmap da especificação, por um motivo: o **Bloco E**
(matriz de testes e avaliador de asserções) é lógica local pura, não depende de
nenhuma credencial nem de nenhuma rota, e é metade do valor da feature. Com A′
feito, ele e A″ podem andar em paralelo — e A″ é o único que precisa de uma
autorização a mais, porque escreve.

1. ~~**A′ — rodar o probe.**~~ Feito: as três rotas respondem e o pipeline
   volta com as 34 chaves (§ O que o probe respondeu).
2. **A″ — verificar o verbo de escrita**, contra uma casca vazia criada só para
   isso. É o que separa "a rota existe" de "o loop fecha", e é a única tarefa
   do plano que muda algo no tenant.
3. **E — matriz de testes.** Schema de caso de teste (§3.4), avaliador de
   asserções por JsonPath, gerador da matriz a partir do flowSpec (caminho
   feliz, uma por condição de `choice`, uma por track de exceção). Sem rede.
4. **B + C — modo de ingestão.** Chave de modo no normalizador/validador
   (`start` × `disconnected-root:`), envelope `metadata`, e síntese de
   `triggerSpec` — que tem 183 exemplos reais no export para aprender a forma.
5. **D — runner de deploy.** Via `digibeectl`, não via REST. `--wait` já
   resolve o polling do §3.3, e `get deployment --status` o diagnóstico.
6. **F — loop de auto-correção.** É a única parte que já existe pela metade:
   `FlowspecGenerationService` já normaliza, valida e re-prompta com os erros
   concretos, até `max_attempts`. O que muda não é o loop, é o **sinal** —
   hoje validação estática, aqui a resposta HTTP, o log e a métrica.
7. **G — portão de `prod`.** Só depois de tudo verde em `test`.

---

## Definição de pronto da Fase 1

- [x] Credencial resolvida por ambiente primeiro, arquivo do `digibeectl`
      depois, com a origem de cada campo reportada e **nenhum valor** impresso.
- [x] JWT enviado **cru** no `Authorization` (sem `Bearer`) — `withToken()`
      responderia 401 num token perfeitamente válido.
- [x] Probe só faz GET, e isso é propriedade da classe, não do argumento.
- [x] 401, 403 e 404 são três notícias diferentes, reportadas juntas numa
      rodada, em vez de a primeira encerrar a execução.
- [x] Um 200 que não devolve `flowSpec` é reportado como loop inalcançável.
- [x] Probe rodado contra o tenant, com a forma real das respostas anexada
      aqui (§ O que o probe respondeu).
- [ ] Verbo de escrita verificado — o probe confirma a LEITURA, e é isso.

---

## Bloqueios

O bloqueio de leitura caiu; sobraram dois, e o segundo é novo.

**1. O verbo de escrita não foi verificado.** O probe confirma que a rota
existe e que um pipeline volta legível — não que um flowSpec entra por ela. O
§3.3 supõe `POST /design/realms/{realm}/pipelines` como upsert, e isso segue
sendo suposição. Verificar exige um write contra o design de produção, que é
uma classe de risco diferente de três GETs, então o experimento tem que ser
contido: criar uma casca vazia com o verbo suportado
(`digibeectl create pipeline -n apla-probe -p <projeto de rascunho>`), fazer
POST de um flowSpec trivial nela, ler de volta e conferir. Um pipeline novo,
vazio e nunca implantado é o único alvo aceitável — nunca um dos 201 que
rodam.

**2. O usuário de realm restrito ainda não existe.** O que o probe provou, ele
provou com a credencial interativa ampla. Que a rota responda 200 para um
desenvolvedor não diz nada sobre ela responder para um usuário limitado a
`PIPELINE:READ` + `DEPLOYMENT:CREATE` em `test` — e é esse usuário que a
decisão de topologia pressupõe. Enquanto ele não existir, nada disso vai para
o droplet.

## Antes de construir em cima

- **`AGENTS.md` ainda afirma a regra absoluta** ("never runs on the server"), e
  o bloco novo em `config/services.php` já documenta a reversão. Os dois não
  podem discordar: quando o usuário restrito existir, a seção do `AGENTS.md`
  precisa ganhar a distinção entre a credencial de login e a de serviço. Até
  lá, a regra como está escrita é a que vale.
- **O probe não está em `routes/console.php`, e não deve entrar.** Nada nele é
  periódico: roda uma vez, por uma pessoa, quando a credencial ou a rota muda.
- **Uma sessão do `digibeectl` é curta.** Se a credencial de serviço acabar
  sendo um JWT com validade de horas em vez de um par de chaves, o agente
  precisa de renovação antes de ter qualquer autonomia — um loop de
  auto-correção que morre em 401 no meio da terceira tentativa é pior que
  nenhum.
