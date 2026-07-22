<?php

namespace App\Http\Controllers\Concerns;

use App\Contracts\Documentable;
use App\Http\Requests\GenerateDocumentationDraftRequest;
use App\Jobs\GenerateDocumentationDraft;
use App\Models\DocumentationAiGeneration;
use App\Models\Solution;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Documentation "AI assist", shared by SolutionDocumentationController and
 * IntegrationDocumentationController. Each controller resolves its own
 * target (DocumentationPage or Integration) and the Solution that owns the
 * context documents, and delegates to this trait the side panel, creating
 * the generation request (async job) and status polling. The draft is
 * loaded into the editor for review — nothing is persisted to the page
 * until the user saves.
 */
trait AssistsDocumentation
{
    /** Side panel with prompt + the Solution's context documents. */
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
     * Creates the generation record and dispatches the job. Returns the
     * polling URL (built from the record just created by the controller's
     * callback).
     *
     * @param  callable(DocumentationAiGeneration): string  $pollUrl
     */
    protected function createDraft(
        GenerateDocumentationDraftRequest $request,
        Solution $solution,
        Documentable $target,
        callable $pollUrl,
    ): JsonResponse {
        // Is there already a pending generation for the same target? Do NOT
        // silently reuse its pollUrl: this request may carry a different
        // prompt/context, and returning the previous request's draft would be
        // a wrong result with no warning. The job's WithoutOverlapping (keyed
        // by the target) also prevents a second one from running in
        // parallel — creating a second record/job would just waste a queue
        // slot and an API call for nothing. So we flag it and ask the caller
        // to wait (409 -> Toast in docs-ai.js).
        //
        // Cache::lock closes the check-then-create window: two near-
        // simultaneous clicks would both pass the exists() check and create
        // two records/jobs — with the lock, the second request fails to
        // acquire it and falls into the same 409.
        $lockKey = 'docs-ai-generate:' . $target->getMorphClass() . ':' . $target->getKey();

        $response = Cache::lock($lockKey, 10)->get(function () use ($request, $solution, $target, $pollUrl) {
            // Reap orphaned generations for this target first: a worker killed
            // mid-job (e.g. `composer dev` restarted) never runs
            // handle()/failed(), so its record stays `pending` forever — and
            // the guard below would then refuse every future draft for this
            // target. Anything older than `stale_after` can't still be running.
            DocumentationAiGeneration::query()
                ->where('target_type', $target->getMorphClass())
                ->where('target_id', $target->getKey())
                ->stale()
                ->update(['status' => 'failed', 'error' => DocumentationAiGeneration::INTERRUPTED_ERROR]);

            $pending = DocumentationAiGeneration::query()
                ->where('target_type', $target->getMorphClass())
                ->where('target_id', $target->getKey())
                ->where('status', 'pending')
                ->exists();

            if ($pending) {
                return null;
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
        });

        // false = lock busy (another request is creating one right now); null = already pending.
        return $response ?: response()->json([
            'message' => 'Já existe um rascunho sendo gerado para este conteúdo. Aguarde a conclusão antes de gerar outro.',
            'title'   => 'Geração em andamento',
            'type'    => 'warning',
        ], 409);
    }

    /** Polling: `{pending}` while generating; on completion, the Markdown; on failure, the error. */
    protected function draftStatusResponse(Solution $solution, DocumentationAiGeneration $generation): JsonResponse
    {
        $this->authorize('update', $solution);
        abort_unless($generation->solution_id === $solution->id, 404);

        // Orphaned mid-job (worker died): it never leaves `pending` on its own,
        // so resolve it to `failed` here too — otherwise a still-open editor
        // polls it until its client-side ceiling (~10min) instead of getting a
        // clean error, and the target stays blocked for new drafts meanwhile.
        if ($generation->isStale()) {
            $generation->update(['status' => 'failed', 'error' => DocumentationAiGeneration::INTERRUPTED_ERROR]);
        }

        if ($generation->isPending()) {
            return response()->json(['pending' => true]);
        }

        if ($generation->status === 'failed') {
            return response()->json([
                'pending' => false,
                'failed'  => true,
                // Generic message — the raw exception (which may carry the
                // provider's URL or response body) stays only in `error` on
                // the record, for auditing, and never reaches the user's Toast.
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
