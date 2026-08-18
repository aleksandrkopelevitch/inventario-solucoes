<?php

namespace App\Services\Cati;

use App\Enums\SubmissionSectionKey;
use App\Models\SubmissionMessage;
use Laravel\Ai\Responses\AgentResponse;

use function Laravel\Ai\agent;

/**
 * Generates the interview's reply to one turn: resolves context, builds the
 * prompt with history, calls the model once, and splits the answer into
 * conversation + per-section drafts.
 *
 * No correction loop (unlike the flowSpec generator, which validates JSON
 * against a schema): Markdown prose has no strict shape to check. The one
 * thing that IS validated is the section key on each draft block — a draft
 * addressed to a section that doesn't exist is dropped rather than written
 * somewhere approximate.
 */
class SubmissionChatService
{
    /**
     * The 4-backtick draft block with its section key
     * (see SubmissionChatPromptBuilder::systemPrompt()).
     */
    private const DRAFT_FENCE_PATTERN = '/^`{4}rascunho:([a-z_]+)[ \t]*\R(.*?)\R`{4}[ \t]*$/ms';

    public function __construct(
        private readonly SubmissionContextResolver $contextResolver,
        private readonly SubmissionChatPromptBuilder $prompts,
    ) {}

    public function generate(SubmissionMessage $userMessage): SubmissionChatReply
    {
        // The job dispatches a freshly deserialized model (SerializesModels)
        // with no relations loaded, and strict mode does NOT arm the guard on
        // a single fetch — so the eager load is explicit (see CLAUDE.md).
        $userMessage->loadMissing('chat.submission');

        $chat = $userMessage->chat;
        $submission = $chat->submission;

        $history = $chat->messages()->where('id', '<', $userMessage->id)->get();

        $context = $this->contextResolver->resolve($submission);

        $response = $this->prompt(
            $this->prompts->userPrompt($submission, $context, $history, $userMessage->content),
            $context->attachments,
        );

        [$content, $drafts, $rejected] = $this->extractDrafts($response->text);

        return new SubmissionChatReply(
            content: $content,
            drafts: $drafts,
            meta: [
                'provider' => config('services.cati.provider'),
                'model'    => config('services.cati.model'),
                'tokens'   => [
                    'prompt'     => $response->usage->promptTokens,
                    'completion' => $response->usage->completionTokens,
                ],
                'sources_inlined'  => $context->textSources->pluck('label')->all(),
                'sources_attached' => $context->attachedMeta,
                'sources_omitted'  => $context->omittedSources,
                'examples'         => $context->examples->pluck('slug')->all(),
                'examples_by_tag'  => $context->examplesByTag,
                'deviations'       => array_column($context->deviations, 'key'),
                'requirements'     => $context->requirements,
                // A draft aimed at an unknown section — kept in the audit trail
                // rather than silently discarded.
                'rejected_drafts' => $rejected,
            ],
        );
    }

    /**
     * Splits the reply into conversation and drafts.
     *
     * @return array{0: string, 1: list<array{key: string, markdown: string}>, 2: list<string>}
     */
    private function extractDrafts(string $text): array
    {
        $text = trim($text);
        $drafts = [];
        $rejected = [];

        if (preg_match_all(self::DRAFT_FENCE_PATTERN, $text, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $key = SubmissionSectionKey::tryFrom($match[1]);

                if ($key === null) {
                    $rejected[] = $match[1];

                    continue;
                }

                $drafts[] = ['key' => $key->value, 'markdown' => trim($match[2])];
            }
        }

        // Whatever is left once the blocks are removed is the conversation.
        // Done even for a rejected key, so a malformed block never leaks its
        // raw Markdown into the chat bubble.
        $content = trim((string) preg_replace(self::DRAFT_FENCE_PATTERN, '', $text));

        return [$content, $drafts, $rejected];
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
            provider: config('services.cati.provider'),
            model: config('services.cati.model'),
            timeout: (int) config('services.cati.timeout'),
        );
    }
}
