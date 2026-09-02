<?php

namespace App\Services\Documentation;

use App\Models\DocumentationChatMessage;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Support\Documentation\BlockVault;
use App\Support\Documentation\DocumentationRequirements;
use App\Support\Documentation\LiteralVault;
use App\Support\Documentation\SecretText;
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
        private readonly ContextPageResolver $contextPages,
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

        // The page's PROTECTED values (`{% secret %}`) never reach the model
        // either, and for a second reason on top of LiteralVault's: whoever
        // asked may be an editor who is not allowed to read them, and a reply
        // quotes what it was given. It arrives already masked when an editor
        // asked (their editor holds markers), so this is what covers the ADMIN
        // case — and it is unconditional, because a rule that depends on who
        // asked is a rule with a hole in it.
        //
        // Nothing restores them on the way back: the draft keeps the markers,
        // and `EditsDocumentation::persistDocumentation()` puts the real bytes
        // back when the page is saved. That is why the restore there is
        // unconditional too.
        $existingContent = SecretText::mask($userMessage->existing_content);

        $requirements = DocumentationRequirements::for($target, $existingContent);

        $contextDocs = $this->contextDocs->resolve($notebook, $userMessage->context_media_ids ?? []);

        // Other pages of the documentation, chosen for THIS turn. They are
        // masked and stripped on the way in (see ContextPageResolver) and the
        // page being written is excluded from its own reference list — a second
        // copy of it under another heading is how a model loses track of which
        // text the draft is supposed to replace.
        $contextPages = $this->contextPages->resolve(
            $userMessage->context_page_ids ?? [],
            $target instanceof DocumentationPage ? $target : null,
        );

        // Opaque literals never reach the model — a reply rewrites the whole
        // page, and a token copied by hand comes back subtly wrong. Everything
        // the prompt will show is harvested first so one value gets one marker
        // wherever it appears (see LiteralVault).
        // The page's images, file cards, embeds and diagram citations. They are
        // frozen for the opposite reason to the literals: not because the model
        // copies them wrong, but because it was TOLD not to write them — and,
        // asked for the whole page back, obeyed by deleting the ones already
        // there (see BlockVault).
        $blocks = BlockVault::from([$existingContent]);

        $vault = LiteralVault::from([
            $existingContent,
            $userMessage->content,
            ...$history->pluck('content')->all(),
            ...$contextDocs->textDocs->pluck('content')->all(),
            ...$contextPages->pages->pluck('content')->all(),
        ]);

        $userPrompt = $this->prompts->userPrompt(
            $target,
            $notebook,
            $existingContent,
            $history,
            $userMessage->content,
            $contextDocs->textDocs,
            $contextPages,
            $requirements,
            $vault,
            $blocks,
        );

        $response = $this->prompt($userPrompt, $contextDocs->attachments);

        // Restore before splitting: the markers are put back in the
        // conversational half too, so a reply that quotes a value still reads
        // correctly, and the draft is persisted with the real values.
        // Counted on the RAW draft, before any restore: a marker is only a
        // marker until then, and it has to be the draft rather than the whole
        // reply — a model that says "removi o [[BLOCK-2]]" in prose would
        // otherwise be counted as having kept it.
        $blocks->audit($this->extractDraft($response->text)[1]);

        [$content, $draft] = $this->extractDraft($vault->restore($blocks->restore($response->text)));

        // A dropped block is said out loud rather than left for the person to
        // spot in the diff. It is not necessarily wrong — removing an image is
        // legitimate when it was asked for — so the notice states what is
        // missing and leaves the judgement to whoever presses "Aplicar".
        if ($notice = $blocks->droppedNotice()) {
            $content = trim($content . "\n\n" . $notice);
        }

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
                'context_pages'       => $contextPages->names(),
                'omitted_pages'       => $contextPages->omitted,
                'attached'            => $contextDocs->attachedMeta,
                'omitted_attachments' => $contextDocs->omittedAttachments,
                'omitted_context'     => [...$contextDocs->omittedContext, ...$contextDocs->omittedTexts],
                'requirements'        => $requirements,
                'literals'            => $vault->stats(),
                'blocks'              => $blocks->stats(),
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
