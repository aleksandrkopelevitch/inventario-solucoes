<?php

namespace App\Http\Controllers\Concerns;

use App\Contracts\Documentable;
use App\Http\Requests\GenerateDocumentationDraftRequest;
use App\Jobs\GenerateDocumentationDraft;
use App\Models\DocumentationAiGeneration;
use App\Models\Solution;
use Illuminate\Http\JsonResponse;

/**
 * "Assiste IA" da documentação, compartilhado por SolutionDocumentationController
 * e IntegrationDocumentationController. Cada controller resolve o seu alvo
 * (DocumentationPage ou Integration) e a Solução dona dos documentos de
 * contexto, e delega aqui o painel lateral, a criação do pedido de geração (job
 * assíncrono) e o polling de status. O rascunho é carregado no editor para
 * revisão — nada é gravado na página até o usuário salvar.
 */
trait AssistsDocumentation
{
    /** Painel lateral (side-panel) com prompt + documentos de contexto da Solução. */
    protected function assistantPanelResponse(Solution $solution, Documentable $target, string $generateUrl): JsonResponse
    {
        $this->authorize('update', $solution);

        return response()->json([
            'content' => view('documentation.panels.assistant', [
                'solution'        => $solution,
                'targetLabel'     => $target->documentationTitle(),
                'generateUrl'     => $generateUrl,
                'contextStoreUrl' => route('solutions.docs.context.store', $solution),
            ])->render(),
        ]);
    }

    /**
     * Cria o registro de geração e despacha o job. Devolve a URL de polling
     * (montada a partir do registro recém-criado pelo callback do controller).
     *
     * @param  callable(DocumentationAiGeneration): string  $pollUrl
     */
    protected function createDraft(
        GenerateDocumentationDraftRequest $request,
        Solution $solution,
        Documentable $target,
        callable $pollUrl,
    ): JsonResponse {
        // Já há uma geração pendente para o mesmo alvo? NÃO reaproveitar
        // silenciosamente o pollUrl dela: este pedido pode trazer prompt/contexto
        // diferentes, e devolver o rascunho do pedido anterior seria um resultado
        // errado sem aviso. O WithoutOverlapping da job (chaveado pelo alvo) também
        // impede um segundo rodar em paralelo — criar um segundo registro/job só
        // gastaria as tentativas em segundos e falharia sem nunca rodar. Então
        // sinalizamos e pedimos para aguardar (409 -> Toast no docs-ai.js).
        $pending = DocumentationAiGeneration::query()
            ->where('target_type', $target->getMorphClass())
            ->where('target_id', $target->getKey())
            ->where('status', 'pending')
            ->exists();

        if ($pending) {
            return response()->json([
                'message' => 'Já existe um rascunho sendo gerado para este conteúdo. Aguarde a conclusão antes de gerar outro.',
                'title'   => 'Geração em andamento',
                'type'    => 'warning',
            ], 409);
        }

        $data = $request->validated();

        $generation = DocumentationAiGeneration::create([
            'target_type'       => $target->getMorphClass(),
            'target_id'         => $target->getKey(),
            'solution_id'       => $solution->id,
            'user_id'           => $request->user()->id,
            'status'            => 'pending',
            'prompt'            => $data['prompt'],
            'context_media_ids' => array_map(intval(...), $data['media_ids'] ?? []),
            'existing_content'  => $data['existing_content'] ?? null,
        ]);

        GenerateDocumentationDraft::dispatch($generation);

        return response()->json([
            'status'  => 'pending',
            'pollUrl' => $pollUrl($generation),
        ]);
    }

    /** Polling: `{pending}` enquanto gera; ao concluir, o Markdown; se falhar, o erro. */
    protected function draftStatusResponse(Solution $solution, DocumentationAiGeneration $generation): JsonResponse
    {
        $this->authorize('update', $solution);
        abort_unless($generation->solution_id === $solution->id, 404);

        if ($generation->isPending()) {
            return response()->json(['pending' => true]);
        }

        if ($generation->status === 'failed') {
            return response()->json([
                'pending' => false,
                'failed'  => true,
                // Mensagem genérica — a exceção crua (que pode carregar URL ou
                // corpo da resposta do provider) fica só em `error` no registro,
                // para auditoria, e nunca vai pro Toast do usuário.
                'error' => 'Não consegui gerar a documentação. Tente novamente em instantes.',
            ]);
        }

        return response()->json([
            'pending' => false,
            'result'  => $generation->result,
            'meta'    => $generation->meta,
        ]);
    }
}
