<?php

namespace App\Services\Flowspec;

use App\Models\DocumentationPage;
use App\Models\FlowspecExample;
use App\Models\FlowspecGuideline;
use App\Models\FlowspecMessage;
use App\Support\Context\TokenEstimator;
use App\Support\Documentation\SecretText;
use Illuminate\Support\Collection;

/**
 * Builds the flowSpec generator's prompts: the system prompt encodes the
 * Digibee platform rules that validation has already caught the model
 * getting wrong (the {meta, flowSpec} format, `{{ step.alias }}`, choice
 * branches, closed catalog, secrets only via account/global), plus any
 * admin-curated FlowspecGuideline documents (always included, in full —
 * unlike the tag-selected FlowspecExample corpus); the user prompt joins the
 * corpus examples, the documentation and material attached to the conversation,
 * any pasted reference pipeline, the chat history and the request.
 *
 * The history is the only section with a ceiling here. Everything else was
 * already bounded when it was attached (FlowspecContextBudget refuses context
 * that wouldn't fit), so it goes in whole — but a conversation grows on its own
 * and can't be refused, so its oldest turns are dropped instead, and the count
 * is reported back so the UI can say so.
 */
class FlowspecPromptBuilder
{
    public function systemPrompt(): string
    {
        $catalog = file_get_contents(database_path('data/digibee_component_catalog.json'));

        return <<<PROMPT
        Você é um especialista na plataforma de integração Digibee, ajudando a equipe da Leo Madeiras. A cada mensagem você escolhe EXATAMENTE UM de dois modos de resposta, conforme o que o usuário pediu:

        MODO GERAÇÃO — responda com UM ÚNICO JSON no formato {"meta": {...}, "flowSpec": {...}}, sem nenhum texto fora do JSON e sem cercas de código. Use este modo SOMENTE quando o usuário pedir explicitamente para gerar, criar, montar, alterar ou estender um pipeline (ex.: "gere o flowspec", "crie o pipeline que faz X", "monte o fluxo", "adicione um passo Y ao flowSpec anexado", "ajuste esse pipeline para ...").

        MODO CONVERSA — responda em TEXTO puro, direto e objetivo, SEM devolver um documento {meta, flowSpec} completo. Use este modo para TODO o resto: perguntas, dúvidas pontuais, e pedidos para explicar, revisar, comentar ou depurar um flowSpec ou um trecho que o usuário colou. Aqui você PODE citar trechos curtos de JSON para ilustrar um ponto (inclusive entre cercas de código), mas NUNCA devolva um pipeline inteiro a menos que tenham pedido para gerar. Se faltar informação essencial para gerar (por exemplo, a documentação de um sistema envolvido que você ainda não recebeu), continue no MODO CONVERSA: cite pelo nome exato cada sistema cuja documentação ajudaria e deixe claro que dá pra descrever mais na próxima mensagem OU anexar a documentação desse sistema diretamente.

        Na dúvida entre os dois modos, prefira o MODO CONVERSA e pergunte o que o usuário quer.

        Regras de plataforma (obrigatórias no MODO GERAÇÃO — o JSON é validado automaticamente e volta para você corrigir se violar qualquer uma):

        1. `flowSpec` é um mapa branch -> lista ordenada de steps. A branch de entrada chama-se exatamente `disconnected-root:<uuid v4>` e deve ser única.
        2. Todo step tem `id` UUID v4 NOVO (gere UUIDs novos, nunca copie dos exemplos) e todo `id` fora de tracks de for-each tem entrada em `meta` com `position: {x, y}` numéricos (colunas de ~200px, linhas de ~150px por branch).
        3. Steps `choice` roteiam por `when` e `otherwise`; cada condição tem `target` mais UM de `jsonPath` (`{target, jsonPath}`) OU `simple`, uma expressão Simple (`{target, simple}`, ex.: `#{body.RETURNING.STATUS} != '200'`) — use `jsonPath` por padrão e `simple` quando a condição comparar valores. `target`/`otherwise` referenciam NOMES de branch que PRECISAM existir como chave do `flowSpec`. Para status HTTP use faixa: `$.[?(@.status >= 200 && @.status <= 299)]`.
        4. `for-each-connector` aponta `params.onProcess`/`params.onException` para branches `<id-do-step>-onProcessTrack`/`<id-do-step>-onExceptionTrack`, que também precisam existir como chave do `flowSpec`. Steps dentro desses tracks NÃO entram no `meta`.
        5. Referência ao resultado de um step anterior usa SEMPRE o prefixo `step.`: `{{ step.<doubleBracesAlias>.campo }}`. NUNCA `{{ <alias>.campo }}` cru — isso quebra o pipeline com `mismatched input`.
        6. Escopos Double Braces válidos: message, global, account, step, metadata, trigger, session. Funções como UUID(), NOW(), CONCAT() são permitidas.
        7. Object Store SOBRESCREVE o `message` — preserve o payload que ainda será usado gerando antes um step jslt/json-generator com `doubleBracesAlias` e lendo depois via `{{ step.alias.$ }}`.
        8. Upsert em Object Store: operação `UPDATE` com `upsert: true` exige `unique: true` e `objectId` preenchido.
        9. Use APENAS componentes do catálogo abaixo — não invente connector nem tipo de step.
        10. NUNCA escreva credencial literal (chave de API, senha, token): valores sensíveis entram só por `{{ account.* }}` (via `accountLabel`/`accountLabels` no step) ou `{{ global.* }}`.
        {$this->guidelinesSection()}
        Catálogo de componentes permitidos:
        {$catalog}
        PROMPT;
    }

    /**
     * Admin-curated notes (App\Models\FlowspecGuideline) — architectural/
     * stylistic guidance with no structural check possible, unlike the 10
     * numbered rules above (which the validator enforces mechanically). ALL
     * active guidelines are always included, in full: this content is meant
     * to be curated and short (see config('services.flowspec.max_guideline_chars')),
     * not an open corpus needing tag-based selection or budget trimming like
     * FlowspecContextResolver's documentation section. Queried fresh on every
     * call (no cache) — same as the component catalog above, read via
     * file_get_contents() on every attempt — so a guideline an admin just
     * disabled mid-conversation is already gone from the very next
     * correction attempt.
     */
    private function guidelinesSection(): string
    {
        $guidelines = $this->activeGuidelines();

        if ($guidelines->isEmpty()) {
            return '';
        }

        $blocks = $guidelines->map(fn (FlowspecGuideline $guideline) => "## {$guideline->title}\n\n{$guideline->content}");

        return "\nDiretrizes adicionais definidas pela equipe da Leo Madeiras (curadoria manual — NÃO são checadas automaticamente pelo validador; siga-as como boas práticas, mas elas NUNCA sobrepõem as regras de plataforma acima nem o catálogo abaixo):\n\n"
            . $blocks->implode("\n\n") . "\n";
    }

    /** @return Collection<int, FlowspecGuideline> */
    public function activeGuidelines(): Collection
    {
        return FlowspecGuideline::query()->active()->orderBy('title')->get();
    }

    /**
     * @param  Collection<int, FlowspecMessage>  $history
     * @param  int  $historyAllowanceTokens  what the conversation history may
     *                                       cost on this turn — whatever the
     *                                       context limit has left after the
     *                                       fixed prompt and the attached
     *                                       context (FlowspecContextUsage).
     *                                       0 or less means "no ceiling", the
     *                                       shape every caller that doesn't
     *                                       budget passes.
     */
    public function userPrompt(FlowspecContext $context, string $request, Collection $history, int $historyAllowanceTokens = 0): FlowspecPrompt
    {
        [$historySection, $trimmed] = $this->historySection($history, $historyAllowanceTokens);

        $sections = array_filter([
            $this->examplesSection($context->examples),
            $this->documentationSection($context),
            $this->materialSection($context),
            $this->referenceFlowspecSection($context->referenceFlowspecs),
            $historySection,
            "# PEDIDO\n\n{$request}",
        ]);

        return new FlowspecPrompt(implode("\n\n---\n\n", $sections), $trimmed);
    }

    /**
     * Correction-loop prompt: re-presents the context, the previous response
     * and the validator's concrete errors.
     *
     * @param  list<string>  $errors
     */
    public function correctionPrompt(string $basePrompt, string $previousAnswer, array $errors): string
    {
        $list = implode("\n", array_map(fn (string $error) => "- {$error}", $errors));

        return <<<PROMPT
        {$basePrompt}

        ---

        # SUA RESPOSTA ANTERIOR (INVÁLIDA)

        {$previousAnswer}

        # ERROS DE VALIDAÇÃO A CORRIGIR

        {$list}

        Responda novamente com o JSON {"meta", "flowSpec"} COMPLETO e corrigido — não explique, não responda parcial.
        PROMPT;
    }

    /** @param Collection<int, FlowspecExample> $examples */
    private function examplesSection(Collection $examples): string
    {
        if ($examples->isEmpty()) {
            return '';
        }

        $blocks = $examples->map(function (FlowspecExample $example) {
            $json = json_encode($example->flow_spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return <<<BLOCK
            ## EXEMPLO: {$example->name}
            (não copiar UUIDs nem valores — só o padrão; tags: {$this->tagList($example)})

            {$example->description}

            {$json}
            BLOCK;
        });

        return "# EXEMPLOS DE PIPELINES REAIS\n\n" . $blocks->implode("\n\n");
    }

    /**
     * Pipelines the user pasted as the base for the conversation — already
     * minified with the canvas `meta` stripped (NormalizeReferenceFlowspec).
     * Placed as reference material before the request itself, like the docs and
     * examples above it.
     *
     * A Collection, not a single string: these are text attachments on the
     * chat now (see AttachFlowspecText), and a conversation can legitimately
     * carry two pipelines — "junte esses dois fluxos" is a normal request.
     *
     * Each block is headed by the attachment's own LABEL — the name shown on
     * its pill in the context panel, which the user can rename. That shared
     * name is the whole point: it is how someone writes "estenda o flowSpec
     * Pedidos B2B" and the model knows which of three pipelines they mean.
     * These used to be numbered `Pipeline 1..N` (and a lone one got no heading
     * at all), a name the user never saw and therefore could never use.
     *
     * A single pipeline is headed too, for the same reason: the request may
     * name it, and a section the request names has to exist.
     *
     * @param  Collection<int, array{label: string, content: string}>  $referenceFlowspecs
     */
    private function referenceFlowspecSection(Collection $referenceFlowspecs): string
    {
        if ($referenceFlowspecs->isEmpty()) {
            return '';
        }

        $blocks = $referenceFlowspecs->values()->map(
            fn (array $spec) => "## {$spec['label']}\n\n{$spec['content']}"
        );

        return "# FLOWSPEC DE REFERÊNCIA\n\n"
            . "(pipeline(s) anexado(s) pelo usuário como base do pedido, cada um sob o NOME que o usuário deu a ele — é por esse nome que o pedido vai se referir a eles; ajuste/estenda sobre eles; gere UUIDs novos, não reaproveite os destes anexos)\n\n"
            . $blocks->implode("\n\n");
    }

    /**
     * Material the user brought into the conversation: uploads read as text and
     * long pastes. Files the model reads natively (PDF/image) are NOT here —
     * they are handed to the API as attachments — but they are NAMED here, so
     * the model knows what it is looking at and can cite it back the way it
     * cites a documentation page.
     */
    private function materialSection(FlowspecContext $context): string
    {
        $blocks = $context->textDocs->map(
            fn (array $doc) => "## {$doc['label']}\n\n{$doc['content']}"
        );

        $names = array_column($context->attachedMeta, 'name');

        if ($blocks->isEmpty() && $names === []) {
            return '';
        }

        $section = "# MATERIAL ANEXADO PELO USUÁRIO\n\n";

        if ($names !== []) {
            $section .= '(além do texto abaixo, estes arquivos vão anexados nesta mesma requisição e você os lê diretamente: '
                . implode('; ', $names) . ")\n\n";
        }

        if ($context->omittedAttachments !== []) {
            $section .= '(arquivos que não couberam no limite de anexos da requisição: '
                . implode('; ', $context->omittedAttachments) . ")\n\n";
        }

        return $section . $blocks->implode("\n\n");
    }

    private function documentationSection(FlowspecContext $context): string
    {
        if ($context->pages->isEmpty()) {
            return '';
        }

        $blocks = $context->pages->map(function (DocumentationPage $page) {
            // The caderno names the block. It used to be the owning Solution's
            // name, which a page no longer has one of — and the caderno is the
            // more useful heading anyway: it is what the person picking the
            // page in the attach panel actually saw.
            $notebook = $page->notebook?->name ?? 'Documentação';

            // MASKED, like the documentation assistant's own prompt: a page's
            // protected values are not context, and this model rewrites nothing
            // — so a marker is all it could ever have needed
            // (App\Support\Documentation\SecretText).
            return "## {$notebook} — {$page->title}\n\n" . SecretText::mask($page->documentation);
        });

        // No "omitted by budget" note any more: nothing here was trimmed. The
        // attach endpoints refuse documentation that wouldn't fit the context
        // limit, so everything attached is everything sent — which is what
        // makes the composer's meter trustworthy.
        return "# DOCUMENTAÇÃO DOS SISTEMAS ENVOLVIDOS\n\n" . $blocks->implode("\n\n");
    }

    /**
     * @param  Collection<int, FlowspecMessage>  $history
     * @return array{string, int} the section, and how many oldest messages it dropped to fit
     *
     * Only the most recent flowSpec is re-embedded in full — earlier ones
     * collapse to a placeholder (`flow_spec` is what's checked; every
     * message's own `content` there is already just a short canned string,
     * see GenerateFlowspecReply). A FAILED attempt has no such short string:
     * when the correction loop exhausts every try without ever producing a
     * recognized flowSpec, `flow_spec` stays null and `content` is the raw
     * model text verbatim — which can itself be a multi-KB JSON blob (see
     * FlowspecMessage::hasRawJsonContent()). Unlike a validated flowSpec,
     * there is no "current pipeline state" worth keeping from a failed
     * attempt, so EVERY occurrence collapses, not just the older ones —
     * otherwise a chat with a few failed-then-retried turns bakes tens of KB
     * of dead JSON into every future prompt for the rest of its life.
     */
    private function historySection(Collection $history, int $allowanceTokens = 0): array
    {
        if ($history->isEmpty()) {
            return ['', 0];
        }

        $latestWithSpec = $history->last(fn (FlowspecMessage $message) => $message->flow_spec !== null);

        $blocks = $history->map(function (FlowspecMessage $message) use ($latestWithSpec) {
            $role = $message->role === 'user' ? 'Usuário' : 'Assistente';
            $body = $message->content;

            if ($message->flow_spec !== null) {
                $body .= $message->is($latestWithSpec)
                    ? "\n\nflowSpec gerado:\n" . json_encode($message->flow_spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : "\n\n[flowSpec gerado nesta mensagem omitido — superado pelas seguintes]";
            } elseif ($message->role !== 'user' && $message->hasRawJsonContent()) {
                $body = '[resposta anterior descartada por não ter gerado um flowSpec reconhecível — omitida do histórico]';
            }

            return "**{$role}:** {$body}";
        })->values();

        [$blocks, $trimmed] = $this->fitHistory($blocks, $allowanceTokens);

        $section = "# HISTÓRICO DA CONVERSA\n\n";

        if ($trimmed > 0) {
            $section .= "({$trimmed} mensagem(ns) mais antiga(s) desta conversa foram omitidas para caber no limite de contexto — o usuário foi avisado disso na tela.)\n\n";
        }

        return [$section . $blocks->implode("\n\n"), $trimmed];
    }

    /**
     * Drops the OLDEST messages until the history fits its allowance.
     *
     * Oldest-first because the recent turns are the ones the next answer has to
     * be consistent with — including the flowSpec currently on the table. The
     * newest block is always kept even when it alone blows the allowance: a
     * request answered with no history at all is worse than one slightly over
     * budget, and the alternative (refusing to answer) would lock someone out
     * of their own conversation.
     *
     * @param  Collection<int, string>  $blocks
     * @return array{Collection<int, string>, int}
     */
    private function fitHistory(Collection $blocks, int $allowanceTokens): array
    {
        if ($allowanceTokens <= 0) {
            return [$blocks, 0];
        }

        $budget = TokenEstimator::charsFor($allowanceTokens);
        $kept = [];
        $used = 0;

        foreach ($blocks->reverse() as $block) {
            $size = mb_strlen($block);

            if ($kept !== [] && $used + $size > $budget) {
                break;
            }

            $used += $size;
            $kept[] = $block;
        }

        return [collect(array_reverse($kept)), $blocks->count() - count($kept)];
    }

    private function tagList(FlowspecExample $example): string
    {
        return implode(', ', $example->tags);
    }
}
