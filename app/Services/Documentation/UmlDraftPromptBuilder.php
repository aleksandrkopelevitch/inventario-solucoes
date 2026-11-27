<?php

namespace App\Services\Documentation;

use App\Mcp\Tools\GetCodeDocumentationGuide;
use App\Models\Solution;
use App\Support\Documentation\BlockVault;
use App\Support\Documentation\SecretText;
use App\Support\Documentation\UmlDiagram;
use RuntimeException;

/**
 * Prompts for "Gerar com IA" on a "Diagrama UML" block (UmlDraftService).
 *
 * The SYNTAX is not written here: it is §5 of the MCP's code-documentation
 * guide, sliced out of the same file at runtime. That file is the one whose
 * examples DocumentationUmlTest parses against the validator, so the in-app
 * model and an outside MCP client are taught the same notation from the same
 * text — a second copy would drift the first time somebody added a kind. The
 * preface above it says what differs here: only the JSON body comes back, and
 * the catalog listed in the prompt replaces the MCP's `search_solutions`.
 *
 * The model returns the BODY only — `type` is the block's, chosen by the
 * author beside the button, and the validator checks against it.
 */
class UmlDraftPromptBuilder
{
    public function systemPrompt(string $type): string
    {
        $label = UmlDiagram::TYPES[$type] ?? $type;
        $syntax = self::guideSection();

        return <<<PROMPT
        Você escreve diagramas UML para páginas de documentação técnica da Leo
        Madeiras. Um programa desenha o diagrama a partir de um JSON: você decide
        O QUE aparece e ONDE (a ordem dos participantes, a célula de cada
        elemento); o programa decide formas, setas e tamanhos.

        O diagrama pedido é um {$label} (`type="{$type}"`). Não mude o tipo.

        Responda APENAS com o objeto JSON do CORPO do diagrama, dentro de um
        bloco ```json — sem a linha `{% uml %}`, sem `{% enduml %}`, sem `type`
        nem `title` no objeto, e nenhum texto antes ou depois: quem lê a sua
        resposta é um programa.

        Fontes: só a página e o pedido do autor. Não complete com sistemas,
        mensagens ou campos que nenhum dos dois menciona. Escreva os nomes
        exatamente como a página os escreve, em português.

        Se a página e o pedido não descrevem nada que caiba num {$label},
        não force: responda `{"error": "<em uma frase, por quê>"}`.

        Se o prompt trouxer um DIAGRAMA ATUAL, o pedido é uma MUDANÇA nele:
        devolva o diagrama inteiro, alterando só o que foi pedido e mantendo
        ids, nomes, posições `at` e vínculos do resto exatamente como estão.

        Vínculos com o catálogo: aqui não existe `search_solutions`. Os slugs
        válidos são os da seção "CATÁLOGO DE SOLUÇÕES" do prompt — use um dela
        ou nenhum, nunca invente. Sem essa seção, não vincule nada.

        A seguir, a sintaxe completa (escrita para outro uso; onde ela fala em
        "escrita recusada", aqui significa que o diagrama volta para você
        corrigir).

        {$syntax}
        PROMPT;
    }

    /**
     * @param  array<string, string>|null  $catalog  slug → name; null when it was too long to list whole
     */
    public function userPrompt(string $type, string $title, string $content, ?string $instruction, ?string $current, ?array $catalog): string
    {
        // Masked and stripped like every other surface that hands a page to a
        // model: a protected value must not be quotable into a label, and a
        // media block is markup the model can neither author nor keep.
        $content = BlockVault::strip(SecretText::mask($content));

        $sections = [
            'PÁGINA: ' . $title,
            "CONTEÚDO DA PÁGINA:\n\n" . (trim($content) !== '' ? $content : '(vazia)'),
        ];

        if (filled($current)) {
            $sections[] = "DIAGRAMA ATUAL (o pedido abaixo é uma mudança nele):\n\n```json\n" . trim($current) . "\n```";
        }

        if (filled($instruction)) {
            $sections[] = "O AUTOR PEDIU:\n\n" . trim($instruction);
        }

        if ($catalog !== null && $catalog !== []) {
            $sections[] = "CATÁLOGO DE SOLUÇÕES (slug — nome; vincule só um elemento que É um destes sistemas):\n\n"
                . implode("\n", array_map(fn (string $slug, string $name) => "{$slug} — {$name}", array_keys($catalog), $catalog));
        }

        return implode("\n\n---\n\n", $sections);
    }

    /**
     * The repair round: the problems and the payload it sent, never the page
     * again — what is being fixed is the shape, and handing back the source is
     * an invitation to rewrite rather than to fix (SceneDraftPromptBuilder's
     * reasoning, which holds here too).
     *
     * @param  list<string>  $problems
     */
    public function repairPrompt(string $rawJson, array $problems): string
    {
        $list = implode("\n", array_map(fn (string $problem) => '- ' . $problem, $problems));

        return <<<PROMPT
        O diagrama que você devolveu tem problemas:

        {$list}

        Corrija APENAS esses pontos e devolva o objeto JSON completo de novo,
        no mesmo bloco ```json. Não reescreva o que está certo. Para um
        elemento no lugar errado, mude o `at` dele para uma célula livre.

        JSON anterior:

        ```json
        {$rawJson}
        ```
        PROMPT;
    }

    /**
     * The catalog the model may bind to, or null when it is too long to list
     * WHOLE — the assistant's page catalog rule: a truncated list reads as
     * complete, and the model invents a slug for a solution past the cut.
     *
     * @return array<string, string>|null
     */
    public function catalog(): ?array
    {
        $max = (int) config('services.documentation_ai.uml_max_catalog');

        if (Solution::query()->count() > $max) {
            return null;
        }

        return Solution::query()->orderBy('name')->pluck('name', 'slug')->all();
    }

    /**
     * §5 of the code-documentation guide, "Diagramas UML", up to §6. Throws
     * rather than sending an empty syntax: a renumbered guide must fail
     * loudly, in a test, not teach the model nothing.
     */
    public static function guideSection(): string
    {
        $guide = (string) file_get_contents(resource_path(GetCodeDocumentationGuide::GUIDE));

        if (preg_match('/^## 5\. .*?(?=^## 6\. )/ms', $guide, $match) !== 1) {
            throw new RuntimeException('The UML section (§5) of the code-documentation guide was not found.');
        }

        return trim($match[0]);
    }
}
