<?php

namespace App\Services\Cati;

use App\Enums\SubmissionSectionKey;
use App\Models\CatiExample;
use App\Models\CatiGuideline;
use App\Models\Submission;

class PreReviewPromptBuilder
{
    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        Você é um membro cético do CATI, o Comitê de Arquitetura de TI da Leo
        Madeiras, lendo uma submissão na véspera da reunião. Seu trabalho é
        achar o que vai travar a deliberação — não elogiar, não resumir, não
        sugerir melhorias de redação.

        O QUE NÃO FAZER, e isso é a parte mais importante:
        - O prompt já traz a tabela de aderência a padrões e as perguntas
          derivadas do cadastro. **Não repita nenhuma delas.** Elas já estão na
          frente de quem vai ler. Repetir é gastar a sua vez.
        - Não invente política que não está nas diretrizes. Se sua objeção
          depende de uma regra, ela tem que estar no texto que você recebeu.
        - Não peça "mais detalhes" genericamente. Diga QUAL detalhe e por que a
          decisão depende dele.

        O QUE PROCURAR — o que a regra determinística não enxerga:
        - Argumento que não fecha: a conclusão não decorre do que foi dito.
        - Número sem base: custo, prazo ou dimensionamento que aparece do nada,
          ou que contradiz outro trecho.
        - Risco que o texto implica e nunca nomeia (dependência de fornecedor
          único, ponto único de falha, dado saindo do ambiente).
        - Contradição entre seções.
        - Escopo que cresce em silêncio: algo citado como "fora do escopo" mas
          de que o resto depende.
        - Operação depois do go-live: quem opera, quem é acionado às 3h.

        FORMATO — um bloco por achado, nada fora dos blocos:

        ````achado:alta:plan_costs
        O prazo de 3 semanas não considera a janela de homologação de rede,
        que a própria seção diz levar 5 dias.
        ````

        Severidade: `alta` (trava a aprovação), `media` (vai gerar discussão),
        `baixa` (vale ajustar). A chave depois da severidade é a seção onde o
        problema está. Um achado por bloco, no máximo 8, do pior para o menor.
        Nenhum achado? Não devolva bloco nenhum.
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $requirements
     * @param  list<array<string, mixed>>  $conformance
     * @param  list<array<string, mixed>>  $deviations
     */
    public function userPrompt(Submission $submission, array $requirements, array $conformance, array $deviations): string
    {
        $parts = ["Submissão: {$submission->name}"];

        if ($submission->solution) {
            $parts[] = "Solução: {$submission->solution->name}";
        }

        $guidelines = CatiGuideline::query()->active()->orderBy('id')->get();

        if ($guidelines->isNotEmpty()) {
            $parts[] = "DIRETRIZES DO COMITÊ (a única política que você pode invocar):\n\n"
                . $guidelines->map(fn ($g) => "### {$g->title}\n{$g->content}")->implode("\n\n");
        }

        if ($requirements['facts'] !== []) {
            $parts[] = "FATOS DO INVENTÁRIO:\n\n" . collect($requirements['facts'])
                ->map(fn (array $fact) => "- {$fact['label']}: {$fact['value']}")
                ->implode("\n");
        }

        // Handed over precisely so it is NOT repeated.
        $parts[] = "JÁ COBERTO PELA ANÁLISE AUTOMÁTICA — NÃO REPITA:\n\n"
            . collect($conformance)
                ->map(fn (array $check) => "- {$check['label']}: {$check['verdict']->label()} — {$check['detail']}")
                ->implode("\n")
            . "\n\nPerguntas já geradas:\n"
            . (collect($deviations)->isEmpty()
                ? '- (nenhuma)'
                : collect($deviations)->map(fn (array $rule) => "- {$rule['question']}")->implode("\n"));

        $rows = $submission->sections->keyBy(fn ($section) => $section->key->value);
        $sections = [];

        foreach (SubmissionSectionKey::cases() as $key) {
            $content = trim((string) $rows->get($key->value)?->content);

            if ($content !== '') {
                $sections[] = "### `{$key->value}` — {$key->label()}\n\n{$content}";
            }
        }

        $parts[] = "A SUBMISSÃO:\n\n" . implode("\n\n", $sections);

        $examples = CatiExample::query()->active()->latest('id')->take(2)->get();

        if ($examples->isNotEmpty()) {
            $parts[] = 'Para calibrar o nível de exigência, submissões já aprovadas: '
                . $examples->pluck('name')->implode(', ');
        }

        return implode("\n\n---\n\n", $parts);
    }
}
