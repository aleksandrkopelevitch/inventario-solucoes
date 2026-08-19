# CATI — Fase 2: o deck

Continuação de `cati-fase-1.md` (registro, checklist, entrevista e saídas em
texto, tudo pronto). Aqui o registro vira `.pptx`.

## Estado

| Peça | Situação |
|---|---|
| Template corporativo | **feito** — `resources/cati/cati-template.pptx`, gerado por `scripts/build_template.py`, seis layouts, zero slides |
| `MarkdownToBlocks` (seção → bullets/tabelas) | **feito** |
| `BuildDeckSpec` (submissão → spec determinístico) | **feito** |
| `DeckSpecValidator` | **feito** |
| Diagrama publicado pelo canvas a cada "Salvar" | **feito** — `Integration::DIAGRAM_COLLECTION` |
| Renderizador Python (python-pptx) | **feito** — `scripts/render_deck.py` + `RenderSubmissionDeck` |
| Rota + botão "Baixar deck" | **feito** — `submissions.export.deck` |
| Slides de diagrama (imagem do canvas + link) | **feito** |
| Passe de condensação por LLM | **feito** — `SlideCondenser` + `CondenseSubmissionForSlides` |

## Decisões

### Os diagramas entram como IMAGEM, não como formas nativas

A proposta original previa desenhar os quatro slides de diagrama como
autoshapes e conectores. Está descartado, e a razão não é custo — é
consistência com a tese do módulo.

Formas nativas criariam um **segundo lugar onde o diagrama se edita**. Alguém
empurra uma caixa no PowerPoint durante a reunião e o deck passa a discordar do
inventário, em silêncio — exatamente a deriva que este módulo existe para
eliminar. Imagem + link mantém o canvas como autoridade: uma superfície de
edição, várias visões.

O que se aceita em troca: não dá para rearranjar o diagrama no meio da reunião.
O link responde por isso, e o canvas é uma superfície melhor para essa edição
do que o PowerPoint.

### O canvas publica a própria imagem ao salvar

`integration-viz.js` já tinha exportação (`captureDiagramCanvas()`, lado maior
1600 px, fontes embutidas, recorte pelo bounding box). Depois de um "Salvar"
bem-sucedido, ele agora publica esse PNG em
`solutions.integrations.diagram.store`, guardado em
`Integration::DIAGRAM_COLLECTION` (`singleFile()`).

Três detalhes que não são acidentais:

- **Fire-and-forget.** Capturar é caro e pode falhar (fonte, imagem colada que
  não carregou). Nada disso pode transformar um salvamento que deu certo em
  erro na cara do usuário: falhou, o deck usa a imagem anterior e o próximo
  "Salvar" tenta de novo.
- **Endpoint separado, não dentro de `saveLayout()`.** O `SolutionIntegrationController`
  carrega a invariante de que `saveLayout()` escreve *só* `viz_layout` — enfiar
  uma escrita de mídia ali borraria a linha que o próprio docblock dele traça.
- **A imagem é derivada, nunca entrada.** Um teste trava isso: publicar um
  diagrama não pode mexer em `chain`, `viz_layout` nem nas colunas derivadas.

### O renderizador é python-pptx, chamado por processo

Não um serviço HTTP: um script chamado via Symfony Process. A fidelidade visual
vem do template (um placeholder herda fonte, corpo, cor e posição do layout),
então o renderizador não estiliza nada — ele posiciona texto, tabelas e
imagens. python-pptx entra por **validade** e pelas tabelas, não por aparência.

Instalação (PEP 668 barra `pip` global no Ubuntu 24.04):

```
sudo apt install -y python3-venv
python3 -m venv .venv-cati && .venv-cati/bin/pip install python-pptx
```

### Os layouts não têm placeholder de corpo — a geometria vem medida do deck real

Eu tinha afirmado que o renderizador não calcularia geometria nenhuma, porque
um placeholder herda tudo do layout. Errado: `Fundo branco com título` tem
**só o placeholder de título**, uma faixa fina no topo. É por isso que todo
slide de conteúdo dos decks reais carrega uma caixa de texto desenhada à mão.

Então a geometria do corpo foi **medida em um deck aprovado** em vez de
inventada: corpo em (0.5, 0.96) 12,33 × 5,91 pol; em slide de tabela, uma
introdução de 0,61 de altura e a tabela a partir de 1,71. Os números vêm de um
slide feito à mão, o que é o mais próximo de "corporativo" que dá para chegar.

Dois corolários que já morderam:

- **As duas layouts escuras não têm placeholder nenhum**, e uma caixa de texto
  simples herda a cor de corpo do master — escura, invisível sobre `#144227`.
  Capa e encerramento definem branco explicitamente; slides de conteúdo herdam.
- **A identidade da capa estava desenhada no slide 1, não no layout.** A
  primeira versão do template produzia um retângulo verde pelado. Ver
  `resources/cati/README.md`.

### O texto do slide é OUTRO texto, guardado ao lado do original

Uma seção é escrita para ser LIDA — tem o argumento inteiro, em prosa. Um slide
tem meia dúzia de linhas curtas, lidas a seis metros enquanto alguém fala por
cima. São dois textos, e o deck usava o primeiro literalmente.

`submission_sections.slide_content` guarda o segundo, em Markdown (o mesmo
`MarkdownToBlocks` converte os dois, e uma pessoa consegue ler e corrigir).
`slide_source_hash` prende essa versão ao `content` de onde ela saiu: quando os
dois divergem, a seção foi editada depois e **o deck volta a usar o texto
completo** — resumo de um parágrafo que não existe mais é pior que o parágrafo.

Duas decisões que valem mais que o código:

- **É o único lugar do módulo com laço de correção, e ele se justifica.** Na
  entrevista, a resposta é prosa que um humano lê antes de aceitar. Aqui a
  saída vai direto para um slide que ninguém relê antes da reunião — então o
  que volta é MEDIDO (`SlideTextValidator`: 6 linhas, 120 caracteres por linha,
  um nível de aninhamento) e a seção que não coube é pedida de novo, dizendo o
  que estava errado.
- **Seção que não cabe depois da última tentativa fica sem resumo**, e o deck
  imprime o texto completo. Verboso, porém verdadeiro; publicar um resumo
  truncado seria pior.

Roda em job (`CondenseSubmissionForSlides`), não no download: o deck sai em
menos de um segundo e uma chamada de modelo ali colocaria um spinner de 30
segundos na frente de um arquivo que já estava pronto.

## Antes de construir em cima

Ninguém aqui consegue abrir PowerPoint nem LibreOffice. O primeiro deck gerado
tem que ser aberto **no PowerPoint de verdade** antes de qualquer coisa ser
construída sobre o renderizador — um prompt de reparo descoberto três fatias
depois é caro.
