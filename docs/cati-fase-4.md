# CATI — Fase 4: o lado do comitê

Fecha o ciclo. Fase 1 preparou a submissão, Fase 2 gerou o deck; aqui o comitê
delibera e o que foi aprovado volta para o catálogo.

## Estado

| Peça | Situação |
|---|---|
| Conformidade determinística (`ConformanceChecks`) | **feito** |
| Deliberação registrada, com ressalvas rastreáveis | **feito** |
| Promoção do aprovado para a documentação da solução | **feito** |
| Prévia adversarial (`PreReviewService`) | **feito** |
| Handoff de topologia aprovada (`ApprovedTopology`) | **feito** (2026-08-24) |

## Decisões

### O volante do lado da topologia — fechado, reaberto pela Fase 3, fechado de novo

Este documento afirmava que promover um grafo TO BE **deixou de ser
requisito**, porque os diagramas eram imagens do canvas VIVO: o arquiteto
editava o `chain` do próprio inventário enquanto preparava a submissão, então a
topologia já estava promovida no instante em que era desenhada.

**A Fase 3 tornou isso falso** (2026-08-23). A submissão passou a ter desenhos
PRÓPRIOS (`SubmissionDiagram`), cujo `afterChainMutation()` é vazio de
propósito — proposta não escreve no catálogo, porque proposta pode ser
reprovada. Consequência: um TO BE aprovado ficava só na submissão, o catálogo
não era tocado, e a deriva voltou exatamente onde este módulo existe para
eliminá-la.

Fechado em 2026-08-24 por `App\Models\ApprovedTopology`, e a forma importa:

- **Aprovar não escreve topologia.** O TO BE é um grafo livre: pode descrever
  várias integrações, ou uma que ainda não existe. Escolher o alvo é juízo
  humano, e uma aprovação que adivinhasse sobrescreveria topologia real com um
  chute.
- **Aprovar registra uma pendência**, com SNAPSHOT do chain — aplica-se o que o
  comitê abençoou, não o que o desenho virou depois.
- **Ela é visível nos dois lados**: card na aba Comitê da submissão e aviso na
  página da Solution, acima da lista de integrações que está mostrando o
  cenário anterior. Silêncio foi o que causou a deriva.
- **Dois desfechos, e não são a mesma afirmação**: APLICADA ("o catálogo agora
  diz isto") e JÁ REFLETIDA ("o catálogo já estava certo"). Colapsar as duas
  perderia a única distinção que interessa a quem audita.
- **Aplicar passa pelo contrato da Fase 3** (`ChainCanvas::writeChain()` +
  `afterChainMutation()`), que é onde `SyncIntegrationFromChain` roda. Atribuir
  `chain` direto seria o único lugar do app onde topologia muda sem
  participants/source/target/direction irem atrás.

Uma armadilha do roteador: `scopeBindings()` resolve `{topology}` por uma
relação PLURAL (`Submission::topologies()`), e a relação é `HasOne` — submissão
é deliberada uma vez. Um `HasMany` que só pode ter uma linha seria uma mentira
escrita para agradar o router, então essas duas rotas ficam fora do grupo
escopado e o controller confere a posse (mesma decisão já tomada para
`chat/messages/{message}/apply`).

Verificado no navegador ponta a ponta: aprovar → card + aviso na Solution →
aplicar → aviso some e a integração aparece na lista com o resumo derivado
`ZZ QA Loop -> ERP`, que é `ChainLabeler` lendo colunas que só existem se o
sync rodou.

### Silêncio é pergunta, nunca infração

`ConformanceChecks` classifica em `Ok` / `Não informado` / `Exceção ao padrão` /
`Sem dados`. Uma seção que não menciona observabilidade não está violando nada
— está calada, e o comitê trata isso como pergunta. Marcar vermelho faria a
tabela gritar em toda submissão, e uma tabela que sempre grita não é lida.

### Uma fonte só para os sinais de padrão

`DeviationRules` tinha a própria cópia dos conjuntos de palavras-chave e a
própria noção de "coberto". Agora as perguntas de padrão são **derivadas dos
veredictos**: existe pergunta exatamente quando existe check fora do verde. As
duas não podem mais divergir a ponto do checklist perguntar sobre algo que a
tabela já marcou verde.

Um comportamento preservado de propósito na refatoração: uma pergunta de "não
informado" só dispara quando a seção **tem conteúdo**. Numa submissão nova tudo
está em branco, e perguntar sobre todas soterra as úteis. Uma **exceção**
dispara sempre — estar fora da nuvem alvo é desvio independente do que as
seções digam.

### A prévia adversarial recebe a análise automática para NÃO repeti-la

O prompt leva a tabela de conformidade e as perguntas já derivadas, com
instrução explícita de não repetir nenhuma. Sem isso o modelo gasta a vez
reescrevendo o checklist. O que se pede a ele é o que a regra não enxerga:
argumento que não fecha, número sem base, risco que o texto implica e nunca
nomeia, contradição entre seções, escopo que cresce em silêncio.

Ela **nunca reescreve nada** — devolve achados anexados à submissão, ordenados
do pior para o menor, e quem decide o que fazer é uma pessoa.

### Ressalva se fecha na própria página

`Submissions\Deliberation` virou slot próprio para que marcar uma ressalva como
cumprida (e reabri-la) não recarregue a página. Indexada por posição: uma
ressalva não tem identidade própria — é uma linha que o comitê ditou, e a lista
só é reescrita inteira por uma nova deliberação. O card mostra quantas seguem
em aberto, que é a única coisa que alguém quer saber três semanas depois.

### A prévia roda ao submeter, não só no botão

Os achados valem mais no intervalo entre submeter e a reunião — exatamente
quando ninguém lembra de apertar um botão. Dispara na **transição** para
`submetida`, e só nela: salvar de novo um registro já submetido não pode custar
outra chamada de modelo. O botão continua ali para uma re-rodada deliberada.

## O que sobrou

- Nada bloqueante.
- **Lição de processo, não de código:** este documento afirmou por duas fases
  que a promoção de topologia "deixou de ser requisito". A afirmação era
  verdadeira quando escrita e ficou falsa por causa de uma decisão tomada em
  outro arquivo, três meses depois. Um argumento que depende de um mecanismo
  ("porque o canvas é o do inventário") envelhece quando o mecanismo muda —
  vale reler as justificativas de "não precisamos disso" quando o motivo delas
  for mexido.
