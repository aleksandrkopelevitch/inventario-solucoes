<?php

namespace App\Services\Cati;

use App\Enums\SubmissionSectionKey;
use App\Models\Submission;
use App\Models\SubmissionMessage;
use Illuminate\Support\Collection;

/**
 * Builds the interview's prompts.
 *
 * The behaviour that matters is all in the system prompt, and it is the
 * opposite of a form: the assistant's job is to ask as FEW questions as it can
 * get away with, and to arrive with a draft answer whenever the attached
 * material supports one. Typing was never the expensive part — the blank page
 * was.
 */
class SubmissionChatPromptBuilder
{
    public function systemPrompt(): string
    {
        $sections = collect(SubmissionSectionKey::cases())
            ->map(fn (SubmissionSectionKey $key) => "- `{$key->value}` — {$key->label()}"
                . ($key->mandatory() ? ' (obrigatória)' : ''))
            ->implode("\n");

        return <<<PROMPT
        Você conduz a preparação de uma submissão ao CATI, o Comitê de
        Arquitetura de TI da Leo Madeiras. Fala com um arquiteto que conhece a
        própria solução e tem pouco tempo. Responda em português do Brasil,
        direto, sem floreio e sem repetir o que a pessoa acabou de dizer.

        SEU TRABALHO NÃO É PERGUNTAR — É CHEGAR COM A RESPOSTA PRONTA.
        - O prompt traz uma seção "JÁ SABEMOS". Aquilo é fato, veio do
          inventário. NUNCA pergunte nada que esteja ali: perguntar a um
          arquiteto em que nuvem roda o próprio sistema, tendo o cadastro na
          mão, é exatamente o atrito que este produto existe para remover. Use
          como base, e no máximo peça confirmação de UM item quando ele parecer
          desatualizado.
        - Sempre que o material anexado sustentar um trecho, PROPONHA o texto
          em vez de perguntar. É muito mais barato para a pessoa corrigir um
          rascunho do que escrever do zero.
        - Faça UMA pergunta por vez, a de maior valor. Duas perguntas numa
          mensagem viram uma resposta pela metade.
        - Quando o prompt trouxer "PERGUNTAS DO COMITÊ", elas são o que o CATI
          historicamente cobra. Reescreva no contexto deste caso em vez de
          repetir literalmente, e ataque as de severidade alta primeiro.

        NÃO INVENTE NÚMERO. Custo, prazo, dimensionamento, SLA, volume e data
        não se estimam: se não estiverem no material nem tiverem sido ditos,
        pergunte. Escrever "aproximadamente 3 semanas" sem base é o tipo de
        erro que só aparece na reunião do comitê.

        SEÇÕES DA SUBMISSÃO (use estas chaves exatas):
        {$sections}

        BLOCOS DE RASCUNHO:
        Quando propuser texto para uma seção, feche a resposta com um bloco
        cercado por EXATAMENTE 4 crases, marcado com a chave da seção:

        ````rascunho:summary
        Markdown completo da seção...
        ````

        - Quatro crases porque o conteúdo pode ter blocos de código com três.
        - Um bloco por seção; pode haver mais de um na mesma resposta quando a
          pessoa responder algo que preenche duas de uma vez.
        - Cada bloco traz a seção INTEIRA já revisada, não só o trecho novo.
        - Fora dos blocos, escreva apenas a conversa: o que você preencheu e
          qual é a próxima pergunta. Nunca repita o conteúdo do rascunho fora
          do bloco.
        - Sem proposta de texto nesta resposta? Não inclua bloco nenhum.

        Dentro do bloco use Markdown simples: títulos `##`/`###`, listas com
        `-`, tabelas GitHub, `**negrito**`. Sem imagem, sem link para arquivo.
        PROMPT;
    }

    /**
     * @param  Collection<int, SubmissionMessage>  $history
     */
    public function userPrompt(
        Submission $submission,
        SubmissionContext $context,
        Collection $history,
        string $message,
    ): string {
        $parts = [];

        $parts[] = "Submissão: {$submission->name}"
            . ($submission->solution ? "\nSolução no catálogo: {$submission->solution->name}" : '')
            . ($submission->solution?->description ? "\nDescrição: {$submission->solution->description}" : '');

        if ($context->guidelines->isNotEmpty()) {
            $parts[] = "DIRETRIZES DO COMITÊ:\n\n" . $context->guidelines
                ->map(fn ($guideline) => "### {$guideline->title}\n{$guideline->content}")
                ->implode("\n\n");
        }

        if ($context->requirements['facts'] !== []) {
            $facts = collect($context->requirements['facts'])
                ->map(fn (array $fact) => "- {$fact['label']}: {$fact['value']}")
                ->implode("\n");
            $parts[] = "JÁ SABEMOS (fatos do inventário — não pergunte sobre isto):\n\n{$facts}";
        }

        $parts[] = "ESTADO DAS SEÇÕES:\n\n" . $this->sectionState($submission, $context);

        if ($context->deviations !== []) {
            $deviations = collect($context->deviations)
                ->sortBy(fn (array $rule) => ['high' => 0, 'medium' => 1, 'low' => 2][$rule['severity']] ?? 3)
                ->map(fn (array $rule) => "- [{$rule['severity']}] ({$rule['section']}) {$rule['question']}")
                ->implode("\n");
            $parts[] = "PERGUNTAS DO COMITÊ (derivadas do cadastro, ainda sem resposta):\n\n{$deviations}";
        }

        if ($context->textSources->isNotEmpty()) {
            $material = $context->textSources
                ->map(fn (array $source) => "### Material: {$source['label']}\n{$source['text']}")
                ->implode("\n\n");
            $parts[] = "MATERIAL ANEXADO:\n\n{$material}";
        }

        if ($context->examples->isNotEmpty()) {
            $examples = $context->examples
                ->map(fn ($example) => "### {$example->name}\n" . collect($example->sections)
                    ->map(fn ($text, $key) => "[{$key}] {$text}")
                    ->implode("\n"))
                ->implode("\n\n");
            $note = $context->examplesByTag
                ? 'submissões aprovadas parecidas com esta'
                : 'submissões aprovadas recentes, para referência de forma — o assunto pode não ter relação';
            $parts[] = "EXEMPLOS ({$note}):\n\n{$examples}";
        }

        if ($history->isNotEmpty()) {
            $parts[] = "CONVERSA ATÉ AQUI:\n\n" . $history
                ->map(fn (SubmissionMessage $m) => ($m->role === 'user' ? 'Arquiteto' : 'Você') . ": {$m->content}")
                ->implode("\n\n");
        }

        $parts[] = "MENSAGEM DO ARQUITETO:\n\n{$message}";

        return implode("\n\n---\n\n", $parts);
    }

    /** Current content per section, so the model revises rather than starts over. */
    private function sectionState(Submission $submission, SubmissionContext $context): string
    {
        $rows = $submission->sections->keyBy(fn ($section) => $section->key->value);

        return collect($context->requirements['sections'])
            ->map(function (array $section) use ($rows) {
                $content = trim((string) $rows->get($section['key'])?->content);
                $flag = $section['mandatory'] ? ' [obrigatória]' : '';

                return $content === ''
                    ? "- `{$section['key']}`{$flag}: VAZIA"
                    : "- `{$section['key']}`{$flag} ({$section['state']}):\n{$content}";
            })
            ->implode("\n");
    }
}
