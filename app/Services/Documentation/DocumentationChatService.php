<?php

namespace App\Services\Documentation;

use App\Models\DocumentationChatMessage;
use App\Models\Notebook;
use App\Support\Documentation\DocumentationRequirements;
use App\Support\Documentation\LiteralVault;
use Laravel\Ai\Responses\AgentResponse;

use function Laravel\Ai\agent;

/**
 * Generates the Documentation Assistant's reply to one chat turn: resolves
 * this turn's context documents, computes the minimum-requirements checklist,
 * builds the prompt (with history) and calls the model once — no correction
 * loop, unlike flowSpec's JSON generation, since Markdown has no strict shape
 * to validate. A reply MAY include a draft (see DocumentationChatPromptBuilder's
 * 4-backtick convention); if it doesn't, the turn is purely conversational.
 */
class DocumentationChatService
{
    /** Matches the 4-backtick draft block (see DocumentationChatPromptBuilder::systemPrompt()). */
    private const DRAFT_FENCE_PATTERN = '/^(.*?)\n?`{4}[^\n]*\n(.*)\n`{4}[ \t]*$/s';

    public function __construct(
        private readonly ContextDocumentResolver $contextDocs,
        private readonly DocumentationChatPromptBuilder $prompts,
    ) {}

    public function generate(DocumentationChatMessage $userMessage): DocumentationChatReply
    {
        // The job dispatches a freshly deserialized model (SerializesModels)
        // with no relations loaded — strict mode doesn't arm the guard on a
        // single fetch, so the eager load is explicit (see AGENTS.md).
        $userMessage->loadMissing(['chat.target', 'chat.notebook.solutions']);

        $chat = $userMessage->chat;
        $target = $chat->target;
        /** @var Notebook $notebook */
        $notebook = $chat->notebook;

        $history = $chat->messages()->where('id', '<', $userMessage->id)->get();

        $requirements = DocumentationRequirements::for($target, $userMessage->existing_content);

        $contextDocs = $this->contextDocs->resolve($notebook, $userMessage->context_media_ids ?? []);

        // Opaque literals never reach the model — a reply rewrites the whole
        // page, and a token copied by hand comes back subtly wrong. Everything
        // the prompt will show is harvested first so one value gets one marker
        // wherever it appears (see LiteralVault).
        $vault = LiteralVault::from([
            $userMessage->existing_content,
            $userMessage->content,
            ...$history->pluck('content')->all(),
            ...$contextDocs->textDocs->pluck('content')->all(),
        ]);

        $userPrompt = $this->prompts->userPrompt(
            $target,
            $notebook,
            $userMessage->existing_content,
            $history,
            $userMessage->content,
            $contextDocs->textDocs,
            $requirements,
            $vault,
        );

        $response = $this->prompt($userPrompt, $contextDocs->attachments);

        // Restore before splitting: the markers are put back in the
        // conversational half too, so a reply that quotes a value still reads
        // correctly, and the draft is persisted with the real values.
        [$content, $draft] = $this->extractDraft($vault->restore($response->text));

        return new DocumentationChatReply(
            content: $content,
            draft: $draft,
            meta: [
                'provider' => config('services.documentation_ai.provider'),
                'model'    => config('services.documentation_ai.model'),
                'tokens'   => [
                    'prompt'      => $response->usage->promptTokens,
                    'completion'  => $response->usage->completionTokens,
                    'cache_write' => $response->usage->cacheWriteInputTokens,
                    'cache_read'  => $response->usage->cacheReadInputTokens,
                ],
                'inlined'             => $contextDocs->textDocs->pluck('name')->all(),
                'attached'            => $contextDocs->attachedMeta,
                'omitted_attachments' => $contextDocs->omittedAttachments,
                'omitted_context'     => [...$contextDocs->omittedContext, ...$contextDocs->omittedTexts],
                'requirements'        => $requirements,
                'literals'            => $vault->stats(),
            ],
        );
    }

    /**
     * Splits the model's reply into the conversational text and an optional
     * draft — the full Markdown wrapped in the outer 4-backtick fence. No
     * match = purely conversational, no draft.
     *
     * @return array{0: string, 1: ?string}
     */
    private function extractDraft(string $text): array
    {
        if (preg_match(self::DRAFT_FENCE_PATTERN, trim($text), $m) === 1) {
            return [trim($m[1]), trim($m[2]) . "\n"];
        }

        return [trim($text), null];
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
