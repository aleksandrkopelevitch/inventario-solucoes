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
| A″ — verificar os **verbos de escrita** | **create verificado**, update NÃO — `PUT`/`PATCH` dão 405 (§ O que o A″ respondeu) |
| B — modo de ingestão no normalizador/validador | não começado, desbloqueado por A′ |
| C — síntese de `triggerSpec` | não começado |
| D — runner de deploy (via `digibeectl`) | não começado |
| E — matriz de testes sintéticos + avaliador de asserções | **feito** — 26 testes em `tests/Feature/FlowspecTestMatrixTest.php`, e as 201 do export constroem sem erro (§ O que a matriz produz) |
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

**Atualizar não é `PUT` nem `PATCH`.** Ambos em
`/design/realms/{realm}/pipelines/{id}` respondem **405 Method Not Allowed** —
e 405, não 404, é a notícia boa: o recurso EXISTE (o GET nele funciona) e só o
verbo está errado.

A dedução seguinte não deu certo, e vale registrar para ninguém repetir: um 405
tem de anunciar os métodos aceitos (RFC 7231 §6.5.5) e esta API é claramente
Spring (problem details `about:blank`), que manda `Allow`. Só que **o gateway
remove o header** — `OPTIONS` responde 500 nas três rotas e nenhuma resposta
405 traz `Allow`. Não há como ler os verbos do servidor.

Sobrou `POST`, em dois sabores, e **nenhum dos dois foi tentado de propósito**:

- `POST /pipelines/{id}` — semântica não adivinhável. Num Spring, um
  `@PostMapping("/pipelines/{id}")` pode ser "duplica", "sobe versão" ou
  "implanta" com a mesma naturalidade que "atualiza".
- `POST /pipelines` com o `id` no corpo (o upsert que o §3.3 afirma) — se for
  create-only, cria um segundo `apla-probe`, num realm onde **nada apaga
  pipeline** (ver abaixo).

O caminho de risco zero para a resposta definitiva é capturar a requisição real
do canvas no devtools: como o path já está certo e só o verbo falta, uma
requisição capturada resolve.

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

## Ordem de construção

A ordem não é a do roadmap da especificação, por um motivo: o **Bloco E**
(matriz de testes e avaliador de asserções) é lógica local pura, não depende de
nenhuma credencial nem de nenhuma rota, e é metade do valor da feature. Com A′
feito, ele e A″ podem andar em paralelo — e A″ é o único que precisa de uma
autorização a mais, porque escreve.

1. ~~**A′ — rodar o probe.**~~ Feito: as três rotas respondem e o pipeline
   volta com as 34 chaves (§ O que o probe respondeu).
2. **A″ — verificar os verbos de escrita** (design create, design update,
   runtime deploy), num pipeline de rascunho que nunca sobe. É o que separa "a
   rota existe" de "o loop fecha", e é a única tarefa do plano que muda algo no
   tenant.
3. ~~**E — matriz de testes.**~~ Feito: `BuildPipelineTestMatrix` mais os
   value objects em `App\Support\Digibee\Testing`. Sem rede, sem credencial.
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

O que a matriz **não** deriva, e é o próximo ganho óbvio: a forma da SAÍDA. Um
`json-generator` ou `jslt` no fim da branch de entrada nomeia as chaves da
resposta literalmente, então o caminho feliz poderia asserir mais do que "voltou
um corpo". Hoje ele asserta só isso, deliberadamente, porque é a única
afirmação de conteúdo honesta a partir do documento.

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
- [x] Matriz de testes derivada do flowSpec, com o que não dá para derivar
      reportado como dívida de cobertura em vez de payload inventado.

---

## Bloqueios

O bloqueio de leitura caiu; sobraram dois, e o segundo é novo.

**1. Falta o verbo que ESCREVE o flowSpec.** Criar está verificado (§ O que o
A″ respondeu); `PUT` e `PATCH` em `/pipelines/{id}` dão 405 e o `Allow` não
chega. Falta a captura de uma requisição de Salvar do canvas no devtools —
método, URL e as chaves de topo do payload. É a operação que o loop de
auto-correção repete, então é a que decide se o loop fecha.

Continua sem verificação, e é da API (não do CLI, ver constatação 1):
`POST /runtime/realms/{realm}/deployments` faz deploy? O probe confirmou só o
`GET` dessa rota.

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
