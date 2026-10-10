<?php

namespace App\Services\Documentation;

use App\Support\Documentation\BlockVault;
use App\Support\Documentation\SecretText;
use App\Support\Documentation\StepScene;

/**
 * Prompts for "montar um fluxo em etapas a partir desta página".
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
    public function systemPrompt(): string
    {
        $min = StepScene::MIN_STEPS;
        $max = StepScene::MAX_STEPS;
        $title = StepScene::MAX_TITLE;
        $detail = StepScene::MAX_DETAIL;
        $caption = StepScene::MAX_CAPTION;

        return <<<PROMPT
        Você lê uma página de documentação técnica da Leo Madeiras e resume, em
        ETAPAS, o processo que ela descreve. As etapas viram uma figura animada
        dentro da própria página: cards numerados, um depois do outro, ligados
        por setas.

        Responda APENAS com um objeto JSON dentro de um bloco ```json. Nenhum
        texto antes ou depois — quem lê a sua resposta é um programa.

        FORMATO:

        ```json
        {
          "caption": "Da solicitação do cliente ao crédito no SAP",
          "steps": [
            {"title": "Cliente solicita a devolução", "detail": "Pelo site ou pela loja, informando o pedido e o motivo."},
            {"title": "Loja confere a mercadoria", "detail": "A devolução só segue se o produto voltar em condições."},
            {"title": "Nota fiscal de devolução", "detail": "Emitida no SAP e enviada à SEFAZ.", "highlight": true},
            {"title": "Crédito ao cliente", "detail": "Estorno no cartão ou vale-troca."}
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
        - Escreva em português, com os nomes de sistemas exatamente como a
          página os escreve.

        REGRA MAIS IMPORTANTE: só o que a página diz. Não complete o processo
        com etapas que costumam existir, não invente sistemas, prazos ou
        responsáveis.

        Se a página NÃO descreve uma sequência de etapas (é uma referência de
        campos, uma lista de contatos, um glossário…), não force: responda
        `{"error": "<em uma frase, por que não há um fluxo aqui>"}`.
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
            $sections[] = "O AUTOR PEDIU QUE O FLUXO MOSTRE:\n\n" . trim($focus)
                . "\n\n(Use a página como fonte; isto só diz QUAL processo dela desenhar.)";
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
        no mesmo bloco ```json. Não reescreva as etapas que estão certas.

        JSON anterior:

        ```json
        {$rawJson}
        ```
        PROMPT;
    }
}
