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

## Decisões

### O volante já estava fechado do lado da topologia

O plano original previa promover um grafo TO BE, pertencente à submissão, para
a topologia real. Esse requisito **deixou de existir** quando os diagramas
passaram a ser imagens do canvas VIVO: o arquiteto edita o `chain` do próprio
inventário enquanto prepara a submissão, então a topologia já está promovida no
instante em que é desenhada. O que sobrou para promover é a prosa e a decisão.

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

## O que sobrou

- Ressalvas são registradas e listadas, mas ainda não dá para marcar uma como
  cumprida pela interface (o modelo já guarda `done`).
- A prévia não roda sozinha ao submeter; é um botão.
