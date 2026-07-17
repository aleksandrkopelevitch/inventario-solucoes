<?php

namespace App\Services\Documentation;

use App\Models\DocumentationAiGeneration;
use App\Models\Solution;
use Illuminate\Support\Collection;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Responses\AgentResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

use function Laravel\Ai\agent;

/**
 * Generates a documentation draft from a request + the Solution's context
 * documents. Text documents get inlined into the prompt (respecting a
 * character budget); PDFs and images go as native attachments to the model
 * (laravel/ai). The output is Markdown+GitBook notation in the subset the
 * editor understands — loaded into the editor for review, never written directly.
 */
class DocumentationDraftService
{
    /** Extensions treated as text inlined into the prompt. */
    private const TEXT_EXTENSIONS = ['txt', 'md', 'csv', 'json', 'yaml', 'yml'];

    public function __construct(
        private readonly DocumentationDraftPromptBuilder $prompts,
    ) {}

    public function generate(DocumentationAiGeneration $generation): DocumentationDraftResult
    {
        // The job dispatches a freshly deserialized model (SerializesModels)
        // with no relations loaded — strict mode doesn't arm the guard on a
        // single fetch, so the eager load is explicit (see CLAUDE.md).
        $generation->loadMissing(['solution', 'target']);

        /** @var Solution $solution */
        $solution = $generation->solution;
        $target = $generation->target;

        // Defensive cap (the request doesn't limit media_ids): keeps the first
        // N in collection order and flags the surplus in `omitted_context` —
        // the user selected them explicitly, so they shouldn't vanish without
        // a record (same treatment as `omitted_attachments`).
        $max = (int) config('services.documentation_ai.max_context_documents');
        $selectedAll = $this->selectedMedia($solution, $generation->context_media_ids ?? []);
        $selected = $selectedAll->take($max)->values();
        $omittedContext = $selectedAll->slice($max)->pluck('file_name')->values()->all();

        [$textDocs, $attachments, $attachedMeta, $omittedAttachments, $omittedTexts] = $this->partition($selected);

        $userPrompt = $this->prompts->userPrompt(
            $target,
            $solution,
            // The current content is NOT truncated: the AI's output replaces
            // the whole page in the editor, so any part not sent to the model
            // would vanish from the rewrite. It fits comfortably in the
            // model's window (the request already caps it at 500k chars ~=
            // 125k tokens); context documents do have their own budget since
            // there can be several of them adding up.
            $generation->existing_content,
            $generation->prompt,
            $textDocs,
        );

        $response = $this->prompt($userPrompt, $attachments);

        return new DocumentationDraftResult(
            markdown: $this->cleanFence($response->text),
            meta: [
                'provider' => config('services.documentation_ai.provider'),
                'model'    => config('services.documentation_ai.model'),
                'tokens'   => [
                    'prompt'     => $response->usage->promptTokens,
                    'completion' => $response->usage->completionTokens,
                    // Zero today (laravel/ai 0.3.2's AnthropicProvider doesn't
                    // set cache_control) — recorded to give visibility once
                    // prompt caching lands (see optimization plan, Phase 2).
                    'cache_write' => $response->usage->cacheWriteInputTokens,
                    'cache_read'  => $response->usage->cacheReadInputTokens,
                ],
                'inlined'             => $textDocs->pluck('name')->all(),
                'attached'            => $attachedMeta,
                'omitted_attachments' => $omittedAttachments,
                // Surplus from the document cap + texts that didn't fit the
                // character budget — both "selected but left out of the prompt".
                'omitted_context' => [...$omittedContext, ...$omittedTexts],
            ],
        );
    }

    /**
     * Chosen context documents, in collection order (the `max_context_documents`
     * cap is applied by the caller, so it can flag the surplus). Empty list =
     * NO documents: the panel's checkboxes come checked by default, so `[]`
     * only happens when the user deliberately unchecked all of them — treating
     * it as "all" would silently ignore that choice.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Media>
     */
    private function selectedMedia(Solution $solution, array $ids): Collection
    {
        $ids = array_map(intval(...), $ids);

        return $solution->getMedia(Solution::CONTEXT_COLLECTION)
            ->whereIn('id', $ids)
            ->values();
    }

    /**
     * Splits into text documents (inlined, respecting the character budget)
     * and native attachments (PDF/image).
     *
     * @param  Collection<int, Media>  $media
     * @return array{0: Collection<int, array{name: string, content: string}>, 1: list<object>, 2: list<array{id: int, name: string, kind: string}>, 3: list<string>, 4: list<string>}
     */
    private function partition(Collection $media): array
    {
        $budget = (int) config('services.documentation_ai.doc_budget_chars');
        $maxAttachmentBytes = (int) config('services.documentation_ai.max_attachment_bytes');
        $textDocs = collect();
        $attachments = [];
        $attachedMeta = [];
        $omittedAttachments = [];
        $omittedTexts = [];
        $attachmentBytes = 0;

        foreach ($media as $item) {
            $ext = strtolower((string) $item->extension);
            $mime = (string) $item->mime_type;

            if (in_array($ext, self::TEXT_EXTENSIONS, true) || str_starts_with($mime, 'text/')) {
                if ($budget <= 0) {
                    // Budget exhausted — omits the remaining texts, but records
                    // it: the user marked them on purpose, they can't vanish
                    // without notice (same treatment as omitted_attachments/omitted_context).
                    $omittedTexts[] = $item->file_name;

                    continue;
                }
                // Reads only what's needed from the file (UTF-8: up to 4
                // bytes/char, so (budget+1)*4 guarantees at least budget+1
                // chars) instead of loading a file up to 20MB whole into
                // memory just to truncate it.
                $content = (string) file_get_contents($item->getPath(), false, null, 0, ($budget + 1) * 4);
                if (mb_strlen($content) > $budget) {
                    $content = mb_substr($content, 0, $budget) . "\n\n[documento truncado]";
                }
                $budget -= mb_strlen($content);
                $textDocs->push(['name' => $item->file_name, 'content' => $content]);

                continue;
            }

            $isImage = str_starts_with($mime, 'image/');
            $isPdf = $mime === 'application/pdf' || $ext === 'pdf';

            if (! $isImage && ! $isPdf) {
                continue;
            }

            // Aggregate byte ceiling: exceeded, omit this one and flag it —
            // each file is already <= 20MB per the request's validation, so
            // the first attachment always fits under the API's ~32MB/request limit.
            if ($maxAttachmentBytes > 0 && $attachmentBytes + (int) $item->size > $maxAttachmentBytes) {
                $omittedAttachments[] = $item->file_name;

                continue;
            }

            $attachmentBytes += (int) $item->size;

            if ($isImage) {
                $attachments[] = new LocalImage($item->getPath(), $mime);
                $attachedMeta[] = ['id' => $item->id, 'name' => $item->file_name, 'kind' => 'image'];
            } else {
                $attachments[] = new LocalDocument($item->getPath(), $mime);
                $attachedMeta[] = ['id' => $item->id, 'name' => $item->file_name, 'kind' => 'pdf'];
            }
        }

        return [$textDocs, $attachments, $attachedMeta, $omittedAttachments, $omittedTexts];
    }

    /**
     * Removes a code fence wrapping the entire response (the model sometimes
     * wraps everything in ```markdown … ```), preserving internal fences.
     */
    private function cleanFence(string $text): string
    {
        $trimmed = trim($text);

        if (preg_match('/^```(?:markdown|md)?\s*\n(.*)\n```$/s', $trimmed, $m) === 1) {
            return trim($m[1]) . "\n";
        }

        return $trimmed . "\n";
    }

    /**
     * Protected so tests can substitute the real API call with a test double.
     *
     * @param  list<object>  $attachments
     */
    protected function prompt(string $prompt, array $attachments = []): AgentResponse
    {
        return agent(instructions: $this->prompts->systemPrompt())->prompt(
            $prompt,
            attachments: $attachments,
            provider: config('services.documentation_ai.provider'),
            model: config('services.documentation_ai.model'),
            timeout: (int) config('services.documentation_ai.timeout'),
        );
    }
}
