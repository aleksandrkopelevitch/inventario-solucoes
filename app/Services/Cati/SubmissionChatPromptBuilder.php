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
 *
 * What the user prompt hands over is chosen just as deliberately. Three things
 * the module already computes used to be dropped on the floor here, and each
 * of them cost the interview something concrete:
 *
 * - **`SubmissionSectionKey::question()`** — the module's own definition of
 *   what each section must answer. Without it the model saw `domains_data —
 *   Domínios e dados` and had to guess that this means business domains, which
 *   data is read and written, where it comes from, and whether any of it is
 *   sensitive. It guessed differently every time.
 * - **The sections each catalog fact informs** (`SubmissionRequirements`
 *   attaches them on purpose, and its docblock explains why a 1:1 mapping
 *   would throw information away). Knowing the vendor belongs in the summary,
 *   the operating model AND the costs is what lets one fact fill three
 *   sections instead of one.
 * - **The credential findings on a source.** The scanner flags them and the UI
 *   badges them, and the prompt then inlined the raw text with no warning at
 *   all. A draft that quotes a `client_secret` into a section is not a
 *   cosmetic problem: approving a submission PROMOTES its sections into the
 *   Solution's documentation, and the deck renderer prints them on a slide.
 *
 * The history is the one part of this prompt that grows without bound while
 * being re-sent every turn, so it is trimmed here (see historySection).
 */
class SubmissionChatPromptBuilder
{
    public function systemPrompt(): string
    {
        // The stable half of the section list. The per-turn brief in
        // sectionState() carries each section's question and its current text;
        // this is only the vocabulary of exact keys the draft blocks must use.
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
          mão, é exatamente o atrito que este produto existe para remover. Cada
          fato diz quais seções ele informa — use-o em todas elas, não só na
          primeira.
        - Sempre que o material anexado sustentar um trecho, PROPONHA o texto
          em vez de perguntar. É muito mais barato para a pessoa corrigir um
          rascunho do que escrever do zero. Diga de onde veio ("isso saiu do
          deck de 2024") na conversa, nunca dentro do bloco.
        - Faça UMA pergunta por vez, a de maior valor. Duas perguntas numa
          mensagem viram uma resposta pela metade.
        - Uma resposta curta costuma preencher mais de uma seção. Escreva
          todas as que ela sustentar, não só aquela sobre a qual você
          perguntou.
        - Quando o prompt trouxer "PERGUNTAS DO COMITÊ", elas são o que o CATI
          historicamente cobra. Reescreva no contexto deste caso em vez de
          repetir literalmente, e ataque as de severidade alta primeiro.

        NÃO INVENTE NÚMERO. Custo, prazo, dimensionamento, SLA, volume e data
        não se estimam: se não estiverem no material nem tiverem sido ditos,
        pergunte. Escrever "aproximadamente 3 semanas" sem base é o tipo de
        erro que só aparece na reunião do comitê.

        E NÃO AFIRME AUSÊNCIA. "Não há dado pessoal envolvido", "não impacta
        nenhum legado", "não há risco de compliance" são afirmações sobre algo
        que ninguém disse — e são justamente as que o comitê cobra depois,
        porque saíram assinadas. Ninguém falou no assunto? Escreva que ainda
        não foi levantado, ou pergunte. Só afirme que não há quando a pessoa
        tiver dito que não há.

        "NÃO SEI" É UMA RESPOSTA — E ENCERRA O ASSUNTO.
        Quando a pessoa não souber, ou disser que decide depois, registre a
        lacuna no texto da seção, com todas as letras ("Custo de licenciamento
        ainda não levantado — depende da proposta comercial em andamento"), e
        siga para a próxima pergunta. Nunca repita a pergunta reformulada, e
        nunca preencha a lacuna com um valor plausível. Uma seção que declara o
        que falta é uma seção honesta; o comitê delibera sobre isso todo dia. O
        que ele não perdoa é descobrir na reunião que o número era chute.

        QUANDO O MATERIAL E A PESSOA SE CONTRADIZEREM, a pessoa vence — ela
        conhece o estado atual, o documento pode ser velho. Mas NOMEIE a
        divergência numa frase ("o deck fala em 4 instâncias; você disse 2 —
        vou escrever 2"). Contradição não resolvida entre o anexo e o texto é
        das primeiras coisas que o comitê encontra.

        NUNCA REPRODUZA CREDENCIAL. Se um material vier marcado com aviso de
        credencial, não copie senha, token, chave ou string de conexão com
        segredo para dentro de nenhum rascunho — descreva o mecanismo ("a
        autenticação usa client credentials, com o segredo no cofre"). O que
        entra numa seção aprovada vai para a documentação da solução e para o
        slide.

        SEÇÕES DA SUBMISSÃO (use estas chaves exatas):
        {$sections}

        O QUE É UMA SEÇÃO PRONTA:
        - Prosa completa, do tamanho que o assunto pedir — a versão curta para
          projetar é gerada depois, por outra passada. Não escreva em tópicos
          telegráficos achando que vai virar slide: encurtar é fácil, adivinhar
          o que foi cortado não.
        - Responde a pergunta que a seção carrega, inteira. Uma seção que já
          tem texto pode continuar incompleta — compare o que está escrito com
          o que a pergunta pede antes de dá-la por resolvida.
        - As seções marcadas [OBRIGATÓRIA] são as que travam a submissão.
          Ataque-as primeiro. As marcadas [só no deck] não aparecem no chamado,
          mas o comitê vê os slides — trate-as depois das obrigatórias, nunca
          em vez delas.

        QUANDO PARAR. Preenchidas as obrigatórias, siga para as que faltam do
        deck. Preenchido tudo, diga isso e ofereça revisar uma seção específica
        ou resumir para os slides. Não invente pergunta nova para parecer útil:
        uma entrevista que não acaba é a razão de as pessoas voltarem a montar
        o deck na mão.

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
            $parts[] = "JÁ SABEMOS (fatos do inventário — não pergunte sobre isto):\n\n"
                . $this->factLines($context->requirements['facts']);
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
            $parts[] = "MATERIAL ANEXADO:\n\n" . $context->textSources
                ->map(fn (array $source) => "### Material: {$source['label']}"
                    . $this->credentialWarning($source)
                    . "\n{$source['text']}")
                ->implode("\n\n");
        }

        // Attached on purpose and left out anyway — the model has to know it is
        // working from partial material, or it will answer as if it saw
        // everything.
        if ($context->omittedSources !== []) {
            $parts[] = 'MATERIAL NÃO INCLUÍDO NESTE TURNO (estourou o limite de contexto): '
                . implode(', ', $context->omittedSources)
                . '. Se a resposta depender de algo que só estaria aí, diga isso em vez de supor.';
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

        if (($historySection = $this->historySection($history)) !== '') {
            $parts[] = $historySection;
        }

        $parts[] = "MENSAGEM DO ARQUITETO:\n\n{$message}";

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * A fact, and the sections it is evidence for.
     *
     * `SubmissionRequirements` carries that mapping deliberately — most facts
     * inform several sections, and the fornecedor shows up in the summary, the
     * operating model and the costs. Handing over only `label: value` left the
     * model to rediscover that every turn, badly.
     *
     * @param  list<array<string, mixed>>  $facts
     */
    private function factLines(array $facts): string
    {
        return collect($facts)
            ->map(function (array $fact) {
                $line = "- {$fact['label']}: {$fact['value']}";
                $sections = $fact['sections'] ?? [];

                return $sections === []
                    ? $line
                    : $line . ' → informa: ' . implode(', ', $sections);
            })
            ->implode("\n");
    }

    /** @param array{label: string, flagged: list<string>}  $source */
    private function credentialWarning(array $source): string
    {
        if (($source['flagged'] ?? []) === []) {
            return '';
        }

        return '  ⚠ CONTÉM POSSÍVEL CREDENCIAL (' . implode(', ', $source['flagged'])
            . ') — não copie o valor para nenhum rascunho; descreva o mecanismo.';
    }

    /**
     * What each section must answer, what it says today, and how far along it
     * is.
     *
     * The question is included even for a section that already has text: "has
     * content" and "is answered" are different states, and a half-written
     * section is the one the model is most likely to leave alone. Without it,
     * the only description of `domains_data` reaching the model was its own
     * two-word label.
     */
    private function sectionState(Submission $submission, SubmissionContext $context): string
    {
        $rows = $submission->sections->keyBy(fn ($section) => $section->key->value);

        $intro = "Cada seção traz a pergunta que precisa responder. Texto escrito não é o mesmo\n"
            . "que pergunta respondida — compare os dois antes de considerar uma seção pronta.\n";

        $blocks = collect($context->requirements['sections'])->map(function (array $section) use ($rows) {
            $key = SubmissionSectionKey::from($section['key']);
            $content = trim((string) $rows->get($section['key'])?->content);

            $flag = match (true) {
                $key->mandatory() => ' [OBRIGATÓRIA]',
                $key->deckOnly()  => ' [só no deck]',
                default           => '',
            };

            $block = "### `{$section['key']}` — {$section['label']}{$flag}\n"
                . "Pergunta: {$key->question()}";

            return $content === ''
                ? $block . "\nEstado: VAZIA"
                : $block . "\nEstado: {$section['state']}\nTexto atual:\n{$content}";
        })->implode("\n\n");

        return $intro . "\n" . $blocks;
    }

    /**
     * The conversation so far, trimmed oldest-first to fit its budget.
     *
     * Everything else in this prompt is bounded already; the history was not,
     * and it is re-sent in full on every turn — so a long interview (the
     * normal case, not the edge one) grew until the provider refused it,
     * halfway through a submission somebody had been filling in all afternoon.
     *
     * Oldest-first because the recent turns are the ones the next answer has
     * to be consistent with, and the NEWEST turn is kept even when it alone
     * blows the budget: answering with no history at all is worse than being
     * slightly over. What was dropped is stated rather than hidden — a
     * conversation that quietly forgot its own beginning reads as the
     * assistant losing track, and the person re-explains things they already
     * said.
     *
     * @param  Collection<int, SubmissionMessage>  $history
     */
    private function historySection(Collection $history): string
    {
        if ($history->isEmpty()) {
            return '';
        }

        $blocks = $history
            ->map(fn (SubmissionMessage $m) => ($m->role === 'user' ? 'Arquiteto' : 'Você') . ": {$m->content}")
            ->values();

        $budget = (int) config('services.cati.history_budget_chars');
        $kept = [];
        $used = 0;

        foreach ($blocks->reverse() as $block) {
            $length = mb_strlen($block);

            if ($budget > 0 && $kept !== [] && $used + $length > $budget) {
                break;
            }

            array_unshift($kept, $block);
            $used += $length;
        }

        $dropped = $blocks->count() - count($kept);

        $note = $dropped > 0
            ? " ({$dropped} mensagem(ns) mais antiga(s) foram omitidas por limite de contexto — "
                . 'se precisar de algo dito lá atrás, pergunte em vez de supor.)'
            : '';

        return "CONVERSA ATÉ AQUI{$note}:\n\n" . implode("\n\n", $kept);
    }
}
