<?php

namespace App\Services\Flowspec;

use App\Models\DocumentationPage;
use App\Models\FlowspecExample;
use App\Models\FlowspecMessage;
use App\Models\Integration;
use Illuminate\Support\Collection;

/**
 * Builds the flowSpec generator's prompts: the system prompt encodes the
 * Digibee platform rules that validation has already caught the model
 * getting wrong (the {meta, flowSpec} format, `{{ step.alias }}`, choice
 * branches, closed catalog, secrets only via account/global); the user
 * prompt joins the catalog, corpus examples, trimmed documentation, chat
 * history and the request.
 */
class FlowspecPromptBuilder
{
    public function systemPrompt(): string
    {
        $catalog = file_get_contents(database_path('data/digibee_component_catalog.json'));

        return <<<PROMPT
        Você gera pipelines de integração da plataforma Digibee para a Leo Madeiras.

        Quando o pedido for a criação ou alteração de um pipeline, responda com UM ÚNICO JSON no formato {"meta": {...}, "flowSpec": {...}} — sem nenhum texto fora do JSON, sem cercas de código. Se o pedido for uma dúvida ou faltar informação essencial (por exemplo, documentação de um sistema envolvido que você ainda não recebeu), responda em texto puro (sem JSON) pedindo o que falta — cite pelo nome exato cada sistema cuja documentação ajudaria, e deixe claro que dá pra descrever mais na próxima mensagem OU anexar a documentação desse sistema diretamente.

        Regras de plataforma (obrigatórias — a resposta é validada automaticamente e volta para você corrigir se violar qualquer uma):

        1. `flowSpec` é um mapa branch -> lista ordenada de steps. A branch de entrada chama-se exatamente `disconnected-root:<uuid v4>` e deve ser única.
        2. Todo step tem `id` UUID v4 NOVO (gere UUIDs novos, nunca copie dos exemplos) e todo `id` fora de tracks de for-each tem entrada em `meta` com `position: {x, y}` numéricos (colunas de ~200px, linhas de ~150px por branch).
        3. Steps `choice` roteiam por `when: [{target, jsonPath}]` e `otherwise`; `target`/`otherwise` referenciam NOMES de branch que PRECISAM existir como chave do `flowSpec`. Para status HTTP use faixa: `$.[?(@.status >= 200 && @.status <= 299)]`.
        4. `for-each-connector` aponta `params.onProcess`/`params.onException` para branches `<id-do-step>-onProcessTrack`/`<id-do-step>-onExceptionTrack`, que também precisam existir como chave do `flowSpec`. Steps dentro desses tracks NÃO entram no `meta`.
        5. Referência ao resultado de um step anterior usa SEMPRE o prefixo `step.`: `{{ step.<doubleBracesAlias>.campo }}`. NUNCA `{{ <alias>.campo }}` cru — isso quebra o pipeline com `mismatched input`.
        6. Escopos Double Braces válidos: message, global, account, step, metadata, trigger, session. Funções como UUID(), NOW(), CONCAT() são permitidas.
        7. Object Store SOBRESCREVE o `message` — preserve o payload que ainda será usado gerando antes um step jslt/json-generator com `doubleBracesAlias` e lendo depois via `{{ step.alias.$ }}`.
        8. Upsert em Object Store: operação `UPDATE` com `upsert: true` exige `unique: true` e `objectId` preenchido.
        9. Use APENAS componentes do catálogo abaixo — não invente connector nem tipo de step.
        10. NUNCA escreva credencial literal (chave de API, senha, token): valores sensíveis entram só por `{{ account.* }}` (via `accountLabel`/`accountLabels` no step) ou `{{ global.* }}`.

        Catálogo de componentes permitidos:
        {$catalog}
        PROMPT;
    }

    /** @param Collection<int, FlowspecMessage> $history */
    public function userPrompt(FlowspecContext $context, string $request, Collection $history): string
    {
        $sections = array_filter([
            $this->examplesSection($context->examples),
            $this->documentationSection($context),
            $this->historySection($history),
            "# PEDIDO\n\n{$request}",
        ]);

        return implode("\n\n---\n\n", $sections);
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

    private function documentationSection(FlowspecContext $context): string
    {
        if ($context->pages->isEmpty() && $context->integrationDocs->isEmpty()) {
            return '';
        }

        $pageBlocks = $context->pages->map(function (DocumentationPage $page) {
            $solution = $page->container->name;

            return "## {$solution} — {$page->title}\n\n{$page->documentation}";
        });

        $integrationBlocks = $context->integrationDocs->map(function (Integration $integration) {
            return "## Integração: {$integration->name}\n\n{$integration->documentation}";
        });

        $section = "# DOCUMENTAÇÃO DOS SISTEMAS ENVOLVIDOS\n\n" . $pageBlocks->merge($integrationBlocks)->implode("\n\n");

        if ($context->omittedDocuments !== []) {
            // `omittedDocuments` is a list of `{type, id, label}` refs (to
            // become an "add" button in the chat) — here only the label goes
            // into the prompt.
            $section .= "\n\n(Documentos omitidos por orçamento de contexto: " . implode('; ', array_column($context->omittedDocuments, 'label')) . ')';
        }

        return $section;
    }

    /** @param Collection<int, FlowspecMessage> $history */
    private function historySection(Collection $history): string
    {
        if ($history->isEmpty()) {
            return '';
        }

        $latestWithSpec = $history->last(fn (FlowspecMessage $message) => $message->flow_spec !== null);

        $blocks = $history->map(function (FlowspecMessage $message) use ($latestWithSpec) {
            $role = $message->role === 'user' ? 'Usuário' : 'Assistente';
            $body = $message->content;

            if ($message->flow_spec !== null) {
                $body .= $message->is($latestWithSpec)
                    ? "\n\nflowSpec gerado:\n" . json_encode($message->flow_spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : "\n\n[flowSpec gerado nesta mensagem omitido — superado pelas seguintes]";
            }

            return "**{$role}:** {$body}";
        });

        return "# HISTÓRICO DA CONVERSA\n\n" . $blocks->implode("\n\n");
    }

    private function tagList(FlowspecExample $example): string
    {
        return implode(', ', $example->tags);
    }
}
