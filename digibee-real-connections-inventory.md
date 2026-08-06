# Inventário de conexões reais — pipelines Digibee (via digibeectl)

Levantamento feito escaneando o export local já existente em
`/mnt/c/Users/alexandre.kopelevitc/digibee-flowspecs` (182 pipelines reais,
puxadas anteriormente via `digibeectl` pelo mesmo mecanismo do
`flowspec-catalog-audit.php`). **Não fiz nenhuma chamada ao vivo na Digibee**
— só li os arquivos JSON já exportados no disco.

**Esse export é de 29/06/2026** (mtime dos arquivos) — hoje é 03/08/2026, ou
seja, tem ~5 semanas. Pipelines criadas/alteradas desde então não aparecem
aqui. Se quiser dados frescos: `php flowspec-catalog-audit.php --refresh`
re-puxa tudo via `digibeectl` (usa a credencial interativa do dev, escopo de
produção — só rode se você mesmo quiser disparar isso).

## Achado importante: já existem pipelines de teste de conectividade

Antes de qualquer lista, isto muda a recomendação: **já existem pipelines
dedicadas testando conectividade de 4 sistemas**, de autoria de outra pessoa,
bem antes desta conversa:

| Pipeline | Sistema | Protocolo real usado | Trigger |
|---|---|---|---|
| `Common/health-check-viasoft` | Viasoft | `rest-connector-v2` GET em `global.url-base-viasoft` | **Schedule, a cada 10 min, 8h-19h** (`0 */10 8-19 ? * *`) |
| `Projetos 2020-2021/teste-conectividade-sap` | SAP | `sap-connector`, RFC `RFC_READ_TABLE` (não é HTTP) | nenhum (manual/rascunho) |
| `default/teste-conexao` | RabbitMQ | `rabbitmq-connector` direto (não é HTTP) | evento (`teste-conexao`), não agendado |
| `Projetos 2020-2021/sftp-test-connection` | SFTP | `sftp-connector` direto (não é HTTP), host literal `10.158.1.37` | nenhum (manual/rascunho) |

`health-check-viasoft` já roda de verdade, agendada, e tem seu **próprio**
mecanismo de notificação (`event-publisher-connector` — publica um evento
interno "notification", não e-mail/Teams diretamente). Isso é exatamente o
`F9`/health-check que o `CLAUDE.md` do Inventário registra como
"descontinuado" — mas isso só descreve o que foi removido **do lado do
Inventário** (a tabela `health_check_pipelines` + coluna
`integrations.health_check_url`); a pipeline Digibee em si aparentemente
continua ativa, de forma independente. **Confirme se ainda está ativa antes
de adicionar Viasoft na lista do monitor ISOL** — senão são dois sistemas
de alerta rodando em paralelo pro mesmo endpoint.

As outras três (SAP/RabbitMQ/SFTP) confirmam algo mais estrutural: **cada
protocolo não-HTTP usa seu próprio conector, incompatível com o loop
genérico REST do monitor ISOL.** SAP via `sap-connector` (RFC/SOAP), RabbitMQ
via `rabbitmq-connector`, SFTP via `sftp-connector` — nenhum aceita um GET
simples. Incluir esses sistemas no monitor ISOL exigiria um branch dedicado
por protocolo, não apenas uma linha a mais em `isol-monitor-targets`.

## Inventário completo, por sistema

### HTTP/REST (compatíveis com o monitor ISOL atual)

| Sistema | Base/global var | Pipelines (contagem) |
|---|---|---|
| **VTEX** | `leomadeiras.myvtex.com`, `api.vtex.com`, `leomadeiras.vtexpayments.com.br`, `global.url-api-vtex` | ~10 (materiais-vtex-dispatcher, vtex-sap-pedidos*, zlmi-*-dispatcher, atualiza-preco*) |
| **Promob** | `global.url-base-promob` | promob2020manager-webhooks-order, promoberp-baixa-titulo, promoberp-nota-* |
| **CWS** | `global.url-base-cws` / `global.cws-url` | cws-reuse-token, api-atualiza-pedido, api-cadastro-cliente, evt-*-cws, cag |
| **SVL** | `global.url-token-svl`, `global.url-svl-amigoleo` | get-token-svl, evt-insert-nota-svl |
| **Viasoft** | `global.url-base-viasoft`, `global.url-token-viasoft` | health-check-viasoft (já monitorado!), get-token-viasoft, sch-unica-*-viasoft |
| **BigQuery** | `global.url-bigquery-projects` + `global.bigquery-project-id`, literal `bigquery.googleapis.com` | sch-processa-update-*, evt-process-*-bq-*, evt-insert-bq |
| **Clearsale** | `global.url-clearsale`, `global.url-get-token-clearsale` | webhook-digibee |
| **Braspag** | `global.url-braspag` | webhook-digibee |
| **SCL** | `global.url-base-sap-api`, `global.url-scl-callback` | scl-criapedido, scl-criapedidoreturn |
| **Amigo Leo** | `global.url-amigo-leo` | zfl-cadastro-amigo-leo |
| **SAP-Leucotron** (HTTP, não RFC) | `global.url-base-sap-leucotron` | api-leozinha (extrato, nota, boleto, consulta cliente) |
| **Digibee (própria plataforma)** | literal `core.godigibee.io` | execute-redeploy-pipeline, listar-redeploy-consumo, token-digibee |
| **ViaCEP** (API pública) | literal `viacep.com.br` | sap-buscar-ordens, status-pedidos — baixo valor monitorar (não é sistema interno) |

### Não-HTTP (fora do escopo do monitor ISOL atual — precisam de branch dedicado por protocolo)

| Sistema | Protocolo/conector | Global vars | Já tem teste? |
|---|---|---|---|
| **SAP** (RFC) | `sap-connector`, operation RFC | `sap-servidor`, `sap-client-id`, `sap-instance-number` | sim — `teste-conectividade-sap` (não agendado) |
| **SAP** (SOAP) | `soap-connector-v2`/`v3` | `url-sap-soap-*` (pedido, log, saldo-nota-credito, segunda-via-boleto-nf), `sap-unica`, `url-sap-ecc`, literal `leosapeccq.leomadeiras.com.br:8000` | não |
| **RabbitMQ** | `rabbitmq-connector` | `rabbitmq-host-name`, `rabbitmq-port`; literal `svl-rabbitmq-qa.leomadeiras.com.br` (parece QA) | sim — `teste-conexao` (evento, não agendado) |
| **SFTP** | `sftp-connector` | `sftp-host`, `sftp-port`; literal `10.158.1.37:2222` (parece rascunho) | sim — `sftp-test-connection` (não agendado) |
| **LDAP/Active Directory** | `ldap-connector` | literal `vmdcleo02.leomadeiras.com.br` | não (mas existe `api-ad-unlock` que já usa) |

## `isol-monitor-targets` — testado de verdade, não só listado

Antes de virar JSON final, testei (GET simples, sem autenticação — exatamente
o que o `rest-connector-v2` do monitor ISOL faz) os candidatos HTTP mais
óbvios, pra não entregar uma lista que já nasce alarmando à toa:

| URL testada | Status real | Serve pro monitor ISOL (2xx-ou-alerta)? |
|---|---|---|
| `https://viacep.com.br/ws/04547-130/json/` | **200** | sim |
| `https://leomadeiras.myvtex.com/` (raiz) | 302 (redirect) | não — dispararia falha todo run |
| `https://leomadeiras.myvtex.com/api/catalog_system/pub/category/tree/1` | 400 | não |
| `https://leomadeiras.myvtex.com/api/catalog_system/pvt/brand/list` (sem auth) | 400 | não |
| `https://bigquery.googleapis.com/` (raiz) | 404 | não |
| `https://core.godigibee.io/graphql` (sem auth) | 401 | não |

**Achado real, não hipotético**: nenhum sistema de negócio próprio da Leo
Madeiras (VTEX, BigQuery, Digibee) responde 2xx sem autenticação real — o que
faz sentido, são APIs protegidas. Só `viacep.com.br` (utilitário público de
terceiro) passa limpo. Isso quer dizer que **o desenho atual do monitor ISOL
(uma lista genérica + GET sem auth + "qualquer coisa fora de 2xx é falha")
só serve de verdade para dependências públicas/sem-auth** — pra qualquer
sistema próprio autenticado, ele vai alarmar em toda rodada, mesmo saudável,
porque a resposta é 401/403/400 por falta de credencial, não porque o
sistema caiu.

`isol-monitor-targets` pronto pra colar (só o que passou no teste real, e só
pro loop genérico sem-auth):

```json
[
  { "id": "viacep", "name": "ViaCEP (dependência de sap-buscar-ordens/status-pedidos)", "url": "https://viacep.com.br/ws/04547-130/json/" }
]
```

Promob, CWS, Clearsale, Braspag, Amigo Leo, VTEX e SAP-Leucotron ficam de
fora dessa lista **por enquanto** — não porque não valham monitorar, mas
porque monitorá-los de verdade exige autenticação real por sistema, o que
não cabe no modelo "uma lista + um account sem-auth genérico". Decisão de
escopo pra você: manter fora por ora, ou pedir os próximos branches
autenticados (mesmo padrão usado abaixo pra SAP/SVL/BigQuery/SCL/Viasoft).

## SAP, SVL, BigQuery, SCL e Viasoft (Construshow) — já têm branch dedicado no monitor

Diferente da lista genérica acima, estes 5 sistemas **não** entram em
`isol-monitor-targets` — cada um ganhou um branch próprio, autenticado, no
`digibee-connection-monitor.flowspec.json` (ver `digibee-connection-monitor.md`,
seção "Passo a passo — branches autenticados por sistema"), replicando o
mecanismo de auth real que a pipeline de produção de cada sistema já usa:

| Sistema | Mecanismo replicado | Pipeline de produção real que serviu de referência | Account reaproveitado |
|---|---|---|---|
| SAP | RFC `RFC_READ_TABLE` (leitura `T000`) | `Projetos 2020-2021/teste-conectividade-sap` (rascunho, nunca agendado) + `sap-user` confirmado em `status-pedidos`/`sap-buscar-ordens` | `sap-user` |
| SVL | Login OAuth2 client-credentials | `Common/get-token-svl` | `svl-auth` |
| BigQuery | GET REST autenticado (list datasets) | `Construshow/sch-processa-update-clientes` (não `google-bigquery-sql-connector` — ver nota abaixo) | `bigquery-auth` |
| SCL / SAP S4 | Reachability do host (GET raiz, sem path de negócio) | N/A — desenhado assim de propósito, ver nota | `sap-s4-scl` |
| Viasoft / Construshow | Login do zero (`AUser`/`APassword` + `StatusLogin`) | `Common/get-token-viasoft` | `viasoft-auth` |

**Nota BigQuery**: apesar do catálogo ter `google-bigquery-sql-connector`, a
única pipeline real do tenant usando esse connector (`Common/add-log-bq-event`)
é um rascunho com params vazios — os writes de produção de verdade usam
`rest-connector-v2` puro contra a REST API do BigQuery. O branch do monitor
replica isso, não o connector dedicado.

**Nota SCL**: os dois endpoints reais de SCL (`scl-criapedido`,
`scl-criapedidoreturn`) criam/consultam pedidos de verdade no SAP — nenhum é
seguro para chamar num scheduler recorrente. O branch do monitor testa só a
reachability do host (`{{ global.url-base-sap-api }}` raiz, sem path), uma
checagem deliberadamente mais fraca — confirma "o host responde", não "o
fluxo de pedido funciona". Ver `digibee-connection-monitor.md` para o
raciocínio completo.

**Nota Viasoft**: `Common/health-check-viasoft` já roda agendada e ativa
(10min, 8h-19h) testando o **token em cache** — o branch novo do monitor ISOL
testa o **login do zero**. São checagens diferentes, mas cobrem a mesma
pergunta ("Viasoft está de pé?") por dois caminhos independentes. Vale
decidir se as duas devem continuar coexistindo ou se uma delas deveria ser
aposentada.
