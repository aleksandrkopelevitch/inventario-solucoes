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
                'status'     => 'pending',
                'pollUrl'    => $pollUrl($generation),
                'consumeUrl' => route('solutions.docs.assist.consume', [$solution, $generation]),
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
            // The content the draft was generated FROM — the "before" side of
            // the review diff. On a fresh page load (resume after navigating
            // away) the client no longer has the submit-time snapshot in memory,
            // so it relies on this to diff against.
            'existing_content' => $generation->existing_content,
            'meta'             => $generation->meta,
        ]);
    }

    /**
     * Marks a finished generation as resolved so the editor stops resuming it
     * on reload. Called when the user applies/discards a draft or acknowledges
     * a failure. Idempotent.
     */
    protected function consumeDraftResponse(Solution $solution, DocumentationAiGeneration $generation): JsonResponse
    {
        $this->authorize('update', $solution);
        abort_unless($generation->solution_id === $solution->id, 404);

        if ($generation->consumed_at === null) {
            $generation->update(['consumed_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Payload describing a generation the editor should RESUME on page load, or
     * null if there's nothing to resume. This is what closes the "navigate away
     * while generating, come back later" gap: the job runs to completion on the
     * server regardless of the browser, so on the next load we hand the client
     * the URLs to pick the flow back up (poll a pending one, or open the review
     * for a finished one).
     *
     * A stale pending generation (worker died mid-job) is reaped, so a dead job
     * never shows as "generating" forever. Bounded to the last hour so an old,
     * never-resolved draft doesn't resurface out of nowhere.
     */
    protected function aiResumeFor(Solution $solution, Documentable $target): ?array
    {
        // Same gate as every other endpoint in this trait (panel / status /
        // consume): the Solution that owns the documentation, never the target
        // itself. They agree today (all three policies are role-based, and
        // DocumentationPagePolicy just delegates to its container), but a marker
        // rendered under one rule and polled under another is how you get a
        // "generating…" indicator that 403s on its first tick.
        $user = request()->user();

        if (! $user?->can('update', $solution)) {
            return null;
        }

        $generation = DocumentationAiGeneration::query()
            ->where('target_type', $target->getMorphClass())
            ->where('target_id', $target->getKey())
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->where('created_at', '>', now()->subHour())
            ->latest()
            ->first();

        if (! $generation) {
            return null;
        }

        // Reap only the record we're actually about to hand the client, and only
        // when it really is orphaned — a blanket `stale()->update()` before the
        // read would write on EVERY editor page load for a case that is rare by
        // definition. Nothing accumulates behind us: `createDraft()` still reaps
        // the target's stale records wholesale before its pending guard, which is
        // the one place where a leftover would actually block something.
        if ($generation->isStale()) {
            $generation->update(['status' => 'failed', 'error' => DocumentationAiGeneration::INTERRUPTED_ERROR]);
        }

        return [
            'pending'    => $generation->isPending(),
            'pollUrl'    => route('solutions.docs.assist.status', [$solution, $generation]),
            'consumeUrl' => route('solutions.docs.assist.consume', [$solution, $generation]),
        ];
    }
}
