<?php

namespace App\Services\Documentation;

use App\Enums\SceneType;
use App\Support\Documentation\BeforeAfterScene;
use App\Support\Documentation\BlockVault;
use App\Support\Documentation\ComparisonScene;
use App\Support\Documentation\SceneIcons;
use App\Support\Documentation\SecretText;
use App\Support\Documentation\StepScene;
use App\Support\Documentation\TreeScene;

/**
 * Prompts for the animated scenes of a page — one system prompt per SceneType,
 * sharing the frame (JSON only, words only, nothing the page does not say).
 *
 * The model is asked for CONTENT only — which steps, in what order, which one
 * matters most — and never for a coordinate, a colour or a size. Those belong
 * to docs-scene.js, and keeping them out of the request is the whole reason a
 * fast model is enough here: it is choosing words, not composing a picture.
 *
 * Shares no contract with the Documentation Assistant, for the reason the
 * diagram draft gives (DiagramDraftPromptBuilder): that one returns prose with a
 * block that replaces the page; this returns a JSON object the editor drops
 * into ONE block, and the page is not written by the server at all.
 */
class SceneDraftPromptBuilder
{
    public function systemPrompt(SceneType $type): string
    {
        $icons = SceneIcons::promptList();

        $body = match ($type) {
            SceneType::Steps       => $this->stepsPrompt(),
            SceneType::BeforeAfter => $this->beforeAfterPrompt(),
            SceneType::Tree        => $this->treePrompt(),
            SceneType::Compare     => $this->comparePrompt(),
        };

        return <<<PROMPT
        Você lê uma página de documentação técnica da Leo Madeiras e resume o
        que ela descreve numa FIGURA ANIMADA que vai dentro da própria página.
        Você escolhe só as PALAVRAS; o desenho é feito por um programa.

        Responda APENAS com um objeto JSON dentro de um bloco ```json. Nenhum
        texto antes ou depois — quem lê a sua resposta é um programa.

        {$body}

        ÍCONES: cada item pode ter um `icon` — opcional, e sempre um destes
        nomes, escrito exatamente assim: {$icons}.
        Use quando o ícone ajuda a reconhecer o item num relance (uma pessoa,
        um documento, um banco de dados, um pagamento). Nem todo item precisa
        de um; na dúvida, deixe sem. Nunca invente um nome fora da lista.

        Escreva em português, com os nomes de sistemas exatamente como a página
        os escreve.

        REGRA MAIS IMPORTANTE: só o que a página diz. Não complete com o que
        costuma existir, não invente sistemas, prazos, números ou responsáveis.
        PROMPT;
    }

    private function stepsPrompt(): string
    {
        $min = StepScene::MIN_STEPS;
        $max = StepScene::MAX_STEPS;
        $title = StepScene::MAX_TITLE;
        $detail = StepScene::MAX_DETAIL;
        $caption = StepScene::MAX_CAPTION;

        return <<<PROMPT
        A FIGURA É UM FLUXO EM ETAPAS: cards numerados, um depois do outro,
        ligados por setas — o processo que a página descreve, na ordem.

        FORMATO:

        ```json
        {
          "caption": "Da solicitação do cliente ao crédito no SAP",
          "steps": [
            {"title": "Cliente solicita a devolução", "detail": "Pelo site ou pela loja, informando o pedido e o motivo.", "icon": "pessoa"},
            {"title": "Loja confere a mercadoria", "detail": "A devolução só segue se o produto voltar em condições.", "icon": "loja"},
            {"title": "Nota fiscal de devolução", "detail": "Emitida no SAP e enviada à SEFAZ.", "highlight": true, "icon": "nota-fiscal"},
            {"title": "Crédito ao cliente", "detail": "Estorno no cartão ou vale-troca.", "icon": "dinheiro"}
          ]
        }
        ```

        REGRAS:
        - Entre {$min} e {$max} etapas, NA ORDEM em que acontecem. Agrupe passos
          miúdos; uma etapa é algo que alguém reconheceria como uma fase.
        - `title`: curto, no máximo {$title} caracteres, começando pelo que
          acontece ("Pedido entra no SAP", não "Etapa de entrada"). Sem número —
          a figura numera sozinha.
        - `detail`: uma frase de até {$detail} caracteres dizendo quem faz, onde
          ou como. Pode ficar vazio ("") se a página não disser nada além do
          título.
        - `highlight`: true em NO MÁXIMO UMA etapa — a que a página trata como a
          mais crítica, onde costuma falhar ou o ponto de decisão. Na dúvida,
          nenhuma.
        - `caption`: uma linha de até {$caption} caracteres que diga do quê ao
          quê o fluxo vai. Pode ficar vazio.

        Se a página NÃO descreve uma sequência de etapas (é uma referência de
        campos, uma lista de contatos, um glossário…), não force: responda
        `{"error": "<em uma frase, por que não há um fluxo aqui>"}`.
        PROMPT;
    }

    private function beforeAfterPrompt(): string
    {
        $min = BeforeAfterScene::MIN_CHANGES;
        $max = BeforeAfterScene::MAX_CHANGES;
        $aspect = BeforeAfterScene::MAX_ASPECT;
        $side = BeforeAfterScene::MAX_SIDE;
        $label = BeforeAfterScene::MAX_LABEL;
        $caption = BeforeAfterScene::MAX_CAPTION;

        return <<<PROMPT
        A FIGURA É UM ANTES → DEPOIS: duas colunas, como ERA e como FICA, uma
        linha para cada aspecto que muda — o que a página descreve como mudança
        (um AS IS e um TO BE, uma migração, a versão nova de um processo, um
        sistema que substitui outro).

        FORMATO:

        ```json
        {
          "caption": "O pedido do Leomob com a integração ao Leo360",
          "from": "Hoje",
          "to": "Com a integração",
          "changes": [
            {"aspect": "Criação do pedido", "before": "Vendedor digita o pedido à mão no SVL.", "after": "O 2020 Manager envia um webhook e o pedido nasce sozinho.", "highlight": true, "icon": "carrinho"},
            {"aspect": "Confirmação de pagamento", "before": "Conferida por e-mail com o financeiro.", "after": "O SAP avisa SVL e Leomob pelo RabbitMQ.", "icon": "dinheiro"},
            {"aspect": "Etiquetas de produção", "before": "", "after": "Exportadas pelo PromobERP junto com o pedido.", "icon": "etiqueta"},
            {"aspect": "Planilha de controle", "before": "Atualizada à mão todo dia.", "after": "", "icon": "planilha"}
          ]
        }
        ```

        REGRAS:
        - Entre {$min} e {$max} mudanças. Uma por ASPECTO, e só aspectos que
          de fato mudam — o que fica igual não entra.
        - `aspect`: o assunto da linha, curto, no máximo {$aspect} caracteres
          ("Pagamento", "Cadastro de vendedores").
        - `before` e `after`: uma frase de até {$side} caracteres cada, dizendo
          COMO é naquele mundo — quem faz, onde, de que jeito.
        - `before` vazio ("") quer dizer que aquilo é NOVO; `after` vazio quer
          dizer que deixa de existir. Os dois vazios, ou os dois iguais, não é
          uma mudança — não escreva.
        - `from` e `to`: os nomes das duas colunas, até {$label} caracteres —
          como a página chama os dois momentos ("Hoje" e "Com o Leo360", "AS IS"
          e "TO BE"). Na falta de algo melhor, "Antes" e "Depois".
        - `highlight`: true em NO MÁXIMO UMA mudança — a principal, a que a
          página trata como o motivo da mudança. Na dúvida, nenhuma.
        - `caption`: uma linha de até {$caption} caracteres dizendo o que muda.
          Pode ficar vazio.

        Se a página NÃO descreve uma mudança (só explica como as coisas são,
        sem um "antes" e um "depois"), não force: responda
        `{"error": "<em uma frase, por que não há uma mudança aqui>"}`.
        PROMPT;
    }

    private function treePrompt(): string
    {
        $min = TreeScene::MIN_NODES;
        $max = TreeScene::MAX_NODES;
        $children = TreeScene::MAX_CHILDREN;
        $label = TreeScene::MAX_LABEL;
        $detail = TreeScene::MAX_DETAIL;
        $caption = TreeScene::MAX_CAPTION;

        return <<<PROMPT
        A FIGURA É UMA ÁRVORE: um item no topo e, abaixo dele, o que ele contém
        ou organiza — a hierarquia que a página descreve (um sistema e seus
        módulos, as camadas de uma arquitetura e o que vive em cada uma, uma
        classificação, uma área e suas frentes).

        FORMATO (aninhado: cada item pode ter `children`):

        ```json
        {
          "caption": "Como o SAP S/4HANA se organiza na Leo",
          "root": {
            "label": "SAP S/4HANA",
            "detail": "ERP corporativo",
            "icon": "servidor",
            "children": [
              {"label": "SD — Vendas", "icon": "carrinho", "children": [
                {"label": "Pedidos ZMOB", "detail": "Pedidos do Leomob"},
                {"label": "Faturamento", "icon": "nota-fiscal"}
              ]},
              {"label": "MM — Materiais", "icon": "produto", "children": [
                {"label": "Dados mestres", "highlight": true}
              ]},
              {"label": "FI — Financeiro", "icon": "dinheiro"}
            ]
          }
        }
        ```

        REGRAS:
        - UM item no topo (`root`). No MÁXIMO 3 níveis: o topo, os filhos dele
          e os filhos dos filhos. Netos não têm filhos.
        - Entre {$min} e {$max} itens no total, e no máximo {$children} filhos
          por item. Agrupe o que for miúdo; a árvore mostra a ESTRUTURA, não
          cada detalhe.
        - `label`: curto, até {$label} caracteres — o nome da coisa, como a
          página escreve.
        - `detail`: opcional, até {$detail} caracteres, só quando ajuda a
          entender o item. Na maioria dos itens, deixe de fora.
        - `highlight`: true em NO MÁXIMO UM item — o que a página trata como o
          mais importante. Na dúvida, nenhum.
        - `caption`: uma linha de até {$caption} caracteres dizendo o que a
          árvore organiza. Pode ficar vazio.
        - Só pai → filho de verdade ("contém", "é parte de", "é um tipo de").
          Uma sequência no tempo não é uma árvore.

        Se a página NÃO descreve uma hierarquia (é um processo passo a passo,
        uma comparação, um texto corrido), não force: responda
        `{"error": "<em uma frase, por que não há uma hierarquia aqui>"}`.
        PROMPT;
    }

    private function comparePrompt(): string
    {
        $minOptions = ComparisonScene::MIN_OPTIONS;
        $maxOptions = ComparisonScene::MAX_OPTIONS;
        $minCriteria = ComparisonScene::MIN_CRITERIA;
        $maxCriteria = ComparisonScene::MAX_CRITERIA;
        $name = ComparisonScene::MAX_NAME;
        $detail = ComparisonScene::MAX_DETAIL;
        $criterion = ComparisonScene::MAX_CRITERION;
        $value = ComparisonScene::MAX_VALUE;
        $caption = ComparisonScene::MAX_CAPTION;

        return <<<PROMPT
        A FIGURA É UMA COMPARAÇÃO LADO A LADO: as OPÇÕES que a página compara
        (alternativas de solução, ferramentas para o mesmo trabalho, planos de
        um fornecedor) em colunas, e os CRITÉRIOS em linhas — cada célula diz
        como aquela opção se sai naquele critério.

        FORMATO:

        ```json
        {
          "caption": "Como isolar as integrações do SAP e da Viasoft",
          "options": [
            {"name": "Opção A", "detail": "Ajustes na pipeline atual", "icon": "configuracao"},
            {"name": "Opção B", "detail": "Uma pipeline por ERP desde o SVL", "icon": "integracao", "highlight": true}
          ],
          "criteria": [
            {"name": "Isolamento entre ERPs", "icon": "escudo", "values": [
              {"text": "Filas ainda compartilhadas", "mark": "bad"},
              {"text": "Total, do trigger à entrega", "mark": "good"}
            ]},
            {"name": "Esforço", "icon": "relogio", "values": [
              {"text": "Baixo, já em produção", "mark": "good"},
              {"text": "Médio: duas pipelines novas", "mark": "partial"}
            ]}
          ]
        }
        ```

        REGRAS:
        - Entre {$minOptions} e {$maxOptions} opções e entre {$minCriteria} e
          {$maxCriteria} critérios — os que a página de fato usa para comparar.
        - Opção: `name` curto (até {$name} caracteres), `detail` opcional (até
          {$detail}). Critério: `name` curto (até {$criterion}).
        - Cada critério tem `values` com EXATAMENTE um valor por opção, NA
          MESMA ORDEM das opções.
        - Cada valor: `text` curto (até {$value} caracteres) e `mark`: "good"
          (atende bem), "partial" (atende em parte), "bad" (não atende) ou ""
          (sem juízo — quando a página só descreve). Só marque quando a página
          disser ou deixar claro; não dê nota por conta própria.
        - `highlight`: true em NO MÁXIMO UMA opção — a que a página recomenda
          ou escolheu. Se a página não escolhe, nenhuma.
        - `caption`: uma linha de até {$caption} caracteres dizendo o que se
          compara. Pode ficar vazio.

        Se a página NÃO compara alternativas (descreve uma coisa só, um
        processo, uma mudança no tempo), não force: responda
        `{"error": "<em uma frase, por que não há uma comparação aqui>"}`.
        PROMPT;
    }

    /**
     * The page's text — the EDITOR's current state when it sent one, the saved
     * page otherwise — plus what the author asked the figure to be about.
     */
    public function userPrompt(string $title, string $content, ?string $focus): string
    {
        // Masked and stripped like every other surface that hands a page to a
        // model: a protected value must not be quotable into a step, and a
        // media block is markup the model can neither author nor keep.
        $content = BlockVault::strip(SecretText::mask($content));

        $sections = [
            'PÁGINA: ' . $title,
            "CONTEÚDO DA PÁGINA:\n\n" . (trim($content) !== '' ? $content : '(vazia)'),
        ];

        if (filled($focus)) {
            $sections[] = "O AUTOR PEDIU QUE A FIGURA MOSTRE:\n\n" . trim($focus)
                . "\n\n(Use a página como fonte; isto só diz QUAL parte dela desenhar.)";
        }

        return implode("\n\n---\n\n", $sections);
    }

    /**
     * The repair round: only the problems and the payload it sent, never the
     * page again — what is being fixed is always the shape, and handing back
     * the source is an invitation to rewrite rather than to fix.
     *
     * @param  list<string>  $problems
     */
    public function repairPrompt(string $rawJson, array $problems): string
    {
        $list = implode("\n", array_map(fn (string $problem) => '- ' . $problem, $problems));

        return <<<PROMPT
        O JSON que você devolveu tem problemas:

        {$list}

        Corrija APENAS esses pontos e devolva o objeto JSON completo de novo,
        no mesmo bloco ```json. Não reescreva o que está certo.

        JSON anterior:

        ```json
        {$rawJson}
        ```
        PROMPT;
    }
}
