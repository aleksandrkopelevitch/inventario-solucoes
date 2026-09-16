<?php

namespace App\Services\Documentation;

use App\Enums\ChainNodeKind;
use App\Models\DocumentationPage;
use App\Models\Solution;
use App\Support\Documentation\BlockVault;
use App\Support\Documentation\ChainDraft;
use App\Support\Documentation\SecretText;

/**
 * Prompts for "draw what this page describes".
 *
 * Nothing here shares the Documentation Assistant's contract, deliberately. That
 * one returns prose with at most one 4-backtick block that REPLACES the page;
 * this one returns a JSON object and never touches the page at all. Putting the
 * second on top of the first would mean a reply whose kind has to be guessed
 * from its content, and `.claude/rules/documentation-assistant.md` is a long
 * account of what it costs when that guess is subtle.
 */
class DiagramDraftPromptBuilder
{
    /**
     * Past this many solutions the catalog would stop being a list and start
     * being a corpus. The rule is `pageCatalog()`'s and for the same reason:
     * ALL of them or NONE, because a truncated list reads as complete and the
     * model invents a name for a system it cannot find. The catalog is 109
     * today; if it ever passes this, the fix is retrieval, not a `limit`.
     */
    private const MAX_CATALOG = 400;

    public function systemPrompt(): string
    {
        $kinds = collect(ChainNodeKind::cases())
            ->filter(fn (ChainNodeKind $kind) => $kind->pickable())
            ->map(fn (ChainNodeKind $kind) => "- `{$kind->value}` — {$kind->label()}")
            ->implode("\n");

        $maxNodes = ChainDraft::MAX_NODES;

        return <<<PROMPT
        Você lê uma página de documentação técnica da Leo Madeiras e devolve o
        DESENHO do fluxo que ela descreve: os blocos e as ligações entre eles.

        Responda APENAS com um objeto JSON dentro de um bloco ```json. Nenhum
        texto antes ou depois — quem lê a sua resposta é um programa.

        FORMATO:

        ```json
        {
          "name": "CWS ↔ SAP S/4HANA",
          "nodes": [
            {"id": "cws", "kind": "system", "solution": "CWS"},
            {"id": "portal", "kind": "system", "label": "Portal do fornecedor"},
            {"id": "aprova", "kind": "decision", "label": "Aprovado?"}
          ],
          "edges": [
            {"from": "cws", "to": "aprova", "arrow": "->", "protocol": "REST"}
          ]
        }
        ```

        REGRAS DOS BLOCOS (`nodes`):
        - `id` é um apelido curto que VOCÊ escolhe, único, usado só para ligar
          as arestas. Não aparece na tela.
        - `kind` é o que o bloco É:
        {$kinds}
        - `solution` é o NOME EXATO de uma solução do catálogo abaixo, copiado
          caractere por caractere. Só `system` aceita `solution`.
        - Um sistema que NÃO está no catálogo não vira `solution`: escreva o
          nome em `label` e deixe `solution` fora. Isso é o certo a fazer — o
          desenho mostra um bloco externo, que é exatamente o que ele é.
        - NUNCA invente um nome parecido com um do catálogo para "encaixar". Um
          nome que não existe no catálogo é um bloco de texto livre, não um erro.
        - Todo bloco precisa de `label` OU de `solution` (os de tipo `start` e
          `end` podem ficar sem os dois).
        - No máximo {$maxNodes} blocos. Desenhe o FLUXO que a página descreve,
          não uma caixa por parágrafo.

        REGRAS DAS LIGAÇÕES (`edges`):
        - `from` e `to` são `id`s que existem em `nodes`.
        - `arrow` é `"->"`, `"<-"` ou `"<->"` (mão dupla).
        - Fluxo nos dois sentidos entre os MESMOS dois blocos é UMA ligação
          `"<->"`, nunca duas setas opostas — duas viram dois traços
          sobrepostos na tela.
        - `protocol` é como a ligação acontece, quando a página disser: `REST`,
          `SOAP`, `SFTP`, `JDBC`, `arquivo`, `fila`… Se a página não disser,
          deixe fora. Não deduza um protocolo.
        - Um bloco sem nenhuma ligação é válido. Uma página que descreve um
          sistema isolado desenha um bloco só.

        REGRA GERAL, E É A MAIS IMPORTANTE: desenhe SOMENTE o que a página diz.
        Nada de completar a arquitetura com o que costuma existir, nada de
        supor um banco de dados, uma fila ou um monitoramento que ninguém
        escreveu. Se a página descreve pouco, o desenho é pequeno.

        `name` é como o desenho vai se chamar no catálogo: curto, e nomeando as
        pontas quando for uma integração ("Gupy → Senior HCM").
        PROMPT;
    }

    /** The page, plus the vocabulary of real names it may use. */
    public function userPrompt(DocumentationPage $page): string
    {
        // Masked and stripped for the two reasons the assistant's own prompt
        // documents: a protected value must not be quotable into a block label,
        // and a media block is markup the model can neither author nor keep
        // (`{% file %}` carries an id only the upload knows). Neither is any use
        // for drawing a topology, which makes this the cheap half of that rule.
        $content = BlockVault::strip(SecretText::mask($page->documentation));

        $sections = [
            'PÁGINA: ' . $page->title,
            "CONTEÚDO DA PÁGINA:\n\n" . (trim($content) !== '' ? $content : '(vazia)'),
        ];

        if ($catalog = $this->solutionCatalog()) {
            $sections[] = $catalog;
        }

        return implode("\n\n---\n\n", $sections);
    }

    /**
     * The repair round. It is given ONLY the problems and the payload it sent,
     * never the page again: the failure being fixed is always a shape failure
     * (an id that doesn't exist, a `kind` outside the vocabulary), and handing
     * back the source material is an invitation to redraw rather than to fix.
     *
     * @param  list<string>  $problems
     */
    public function repairPrompt(string $rawJson, array $problems): string
    {
        $list = implode("\n", array_map(fn (string $problem) => '- ' . $problem, $problems));

        return <<<PROMPT
        O JSON que você devolveu tem problemas de formato:

        {$list}

        Corrija APENAS esses pontos e devolva o objeto JSON completo de novo,
        no mesmo bloco ```json. Não redesenhe o fluxo, não acrescente blocos e
        não remova nenhum que não esteja na lista acima.

        JSON anterior:

        ```json
        {$rawJson}
        ```
        PROMPT;
    }

    /**
     * Every solution name, so the model can only echo one back instead of
     * writing what it remembers a system is called. `CreateDiagramFromDraft`
     * resolves these EXACTLY — a name off by one word resolves to nothing and
     * becomes a free-text block, which is why the list has to be complete and
     * verbatim.
     */
    private function solutionCatalog(): ?string
    {
        $solutions = Solution::query()->orderBy('name')->get(['id', 'name']);

        if ($solutions->isEmpty() || $solutions->count() > self::MAX_CATALOG) {
            return null;
        }

        return "CATÁLOGO DE SOLUÇÕES (nomes válidos para `solution`, lista completa):\n\n"
            . $solutions->map(fn (Solution $solution) => '- ' . $solution->name)->implode("\n");
    }
}
