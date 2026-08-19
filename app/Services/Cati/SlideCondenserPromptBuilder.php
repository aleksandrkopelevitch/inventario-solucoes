<?php

namespace App\Services\Cati;

use App\Enums\SubmissionSectionKey;
use App\Models\Submission;

/**
 * Prompts for turning a section's prose into slide text.
 *
 * The output is Markdown, not JSON, on purpose: `MarkdownToBlocks` already
 * turns Markdown into slide blocks, a person can read and fix the result, and
 * there is no second schema to keep in step. What IS validated is the shape of
 * what comes back — length, nesting, table size — by the same code the
 * deterministic path answers to.
 */
class SlideCondenserPromptBuilder
{
    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        Você transforma o texto de uma submissão ao CATI — o Comitê de
        Arquitetura de TI da Leo Madeiras — em texto de SLIDE.

        São dois textos diferentes para o mesmo assunto. O que você recebe foi
        escrito para ser LIDO: tem o argumento inteiro, em prosa. O que você
        devolve vai ser PROJETADO e lido a seis metros de distância, enquanto
        alguém fala por cima.

        REGRAS:
        - No máximo 6 linhas por seção, cada uma com no máximo 120 caracteres.
        - Uma ideia por linha. Comece pelo substantivo, não por "O objetivo é".
        - Use `- ` para cada linha. Um nível de subitem só quando a hierarquia
          for real; nunca dois.
        - Negrito só no rótulo de uma linha (`- **Segurança:** dois firewalls`),
          quando ele ajuda a varrer o slide com os olhos.
        - Tabela em Markdown quando o conteúdo JÁ é tabular (fases, camadas,
          responsáveis). No máximo 6 colunas e 12 linhas.

        PROIBIDO:
        - **Inventar qualquer coisa.** Número, prazo, nome de sistema, área
          responsável: se não está no texto original, não entra. Resumir é
          cortar, nunca completar.
        - Trocar um número por um adjetivo ("3 a 4 semanas" nunca vira "prazo
          curto"). O comitê delibera sobre os números.
        - Frase de ligação ("Além disso", "Vale destacar que"), título repetindo
          o nome da seção, e ponto final em linha de bullet.

        FORMATO DA RESPOSTA — um bloco por seção, exatamente assim:

        ````slide:summary
        - Primeira linha
        - Segunda linha
        ````

        Quatro crases porque o conteúdo pode ter blocos de código. Sem nenhum
        texto fora dos blocos. Devolva um bloco para CADA seção que receber, na
        mesma ordem, usando a chave exata que veio no pedido.
        PROMPT;
    }

    /**
     * @param  list<array{key: SubmissionSectionKey, content: string}>  $sections
     */
    public function userPrompt(Submission $submission, array $sections): string
    {
        $parts = ["Submissão: {$submission->name}"];

        if ($submission->solution) {
            $parts[] = "Solução: {$submission->solution->name}";
        }

        foreach ($sections as $section) {
            $parts[] = "### Seção `{$section['key']->value}` — {$section['key']->label()}\n\n{$section['content']}";
        }

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * Re-ask with what came back wrong, naming the sections to redo.
     *
     * @param  array<string, list<string>>  $problems  section key => problems
     */
    public function correctionPrompt(array $problems): string
    {
        $lines = [];

        foreach ($problems as $key => $issues) {
            $lines[] = "- `{$key}`: " . implode('; ', $issues);
        }

        return 'Os blocos abaixo não servem para slide. Refaça APENAS estas seções, '
            . "no mesmo formato, corrigindo o que está apontado:\n\n" . implode("\n", $lines);
    }
}
