<?php

namespace App\Services\Flowspec;

use App\Models\FlowspecChat;
use App\Models\FlowspecMessage;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\AgentResponse;

use function Laravel\Ai\agent;

/**
 * Orchestrates a flowSpec generation: resolves the conversation's ATTACHED
 * context (documentation references + the user's own files/pastes) plus the
 * tag-selected corpus examples, builds the prompt, calls the model via
 * laravel/ai (provider/model from `config('services.flowspec')`) and runs the
 * correction loop — normalizes, validates and re-prompts with the concrete
 * errors up to `max_attempts`.
 *
 * No JSON in the first response = conversational response (a question/request
 * for detail), returned as text with no re-prompt — and in that case,
 * `meta.suggested_documents` (`suggestedDocuments()`) names real documentation
 * the conversation doesn't have yet, to become an "adicionar ao contexto"
 * button. Nothing there enters a prompt until it is clicked: this service never
 * resolves context the user didn't attach.
 */
class FlowspecGenerationService
{
    public function __construct(
        private readonly FlowspecContextResolver $resolver,
        private readonly FlowspecPromptBuilder $prompts,
        private readonly DigibeeFlowspecNormalizer $normalizer,
        private readonly DigibeeFlowspecValidator $validator,
        private readonly CredentialScrubber $scrubber,
    ) {}

    /**
     * Generates the response for an already-persisted user message: the request
     * is its `content`, the context is whatever is attached to its CHAT (not to
     * the message — see the flowspec_attachments migration), and the history is
     * the messages preceding it in the chat.
     */
    public function generate(FlowspecMessage $userMessage): FlowspecGenerationResult
    {
        // Explicit: the job dispatches a freshly deserialized model
        // (SerializesModels), with no relation loaded — without this, the
        // ->chat access below is a silent lazy-load (Eloquent only arms the
        // strict-mode guard on multi-row hydrations, so even outside
        // production this wouldn't throw LazyLoadingViolationException).
        $userMessage->loadMissing('chat');
        $chat = $userMessage->chat;

        $history = $chat->messages()
            ->where('id', '<', $userMessage->id)
            ->get();

        $context = $this->resolver->resolve($chat, $userMessage->content);

        Log::debug('flowSpec: contexto resolvido', [
            'chat_id'    => $userMessage->flowspec_chat_id,
            'message_id' => $userMessage->id,
            ...$context->toMeta(),
        ]);

        $built = $this->prompts->userPrompt($context, $userMessage->content, $history);

        $basePrompt = $built->text;

        $maxAttempts = (int) config('services.flowspec.max_attempts');
        $attempts = [];
        $tokens = ['prompt' => 0, 'completion' => 0, 'cache_write' => 0, 'cache_read' => 0];

        $document = null;
        $validated = false;
        $conversational = false;
        $text = '';
        $prompt = $basePrompt;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // Only the prompt size — never the text: it carries whole
            // documentation and whatever the user attached, which can contain
            // secrets (what CredentialScrubber exists to catch) and alone
            // generates hundreds of KB of log per attempt when LOG_LEVEL=debug.
            Log::debug("flowSpec: prompt tentativa {$attempt}", [
                'message_id'   => $userMessage->id,
                'prompt_chars' => mb_strlen($prompt),
            ]);

            $response = $this->prompt($prompt, $context->attachments);
            $text = $response->text;
            $tokens['prompt'] += $response->usage->promptTokens;
            $tokens['completion'] += $response->usage->completionTokens;
            // Zero today (laravel/ai 0.3.2 doesn't surface prompt-cache token
            // counts for the configured provider) — accumulated to give
            // visibility once prompt caching lands (see optimization plan, Phase 2).
            $tokens['cache_write'] += $response->usage->cacheWriteInputTokens;
            $tokens['cache_read'] += $response->usage->cacheReadInputTokens;

            // Same here: only the response size, not the text — the model may
            // echo back secrets coming from documentation embedded in the prompt.
            Log::debug("flowSpec: resposta tentativa {$attempt}", [
                'message_id' => $userMessage->id,
                'text_chars' => mb_strlen($text),
                'usage'      => [
                    'prompt'     => $response->usage->promptTokens,
                    'completion' => $response->usage->completionTokens,
                ],
            ]);

            $fenced = $this->fencedJsonBlock($text);
            $candidate = null;
            $brokenFlowspec = false;

            if ($fenced !== null) {
                $decoded = json_decode($fenced, true);

                if (is_array($decoded) && $this->isFlowspecShape($decoded)) {
                    $candidate = $decoded; // a deliberate, well-formed flowSpec
                } elseif (! is_array($decoded) && $this->mentionsFlowspecKeys($fenced)) {
                    // The fence clearly INTENDS a flowSpec ("meta"/"flowSpec")
                    // but the JSON is malformed — a broken generation attempt to
                    // correct. A fenced fragment that is NOT a flowSpec (a
                    // snippet cited to illustrate a conversational answer) falls
                    // through and must not burn a correction attempt.
                    $brokenFlowspec = true;
                }
            }

            if ($candidate === null && ! $brokenFlowspec) {
                $candidate = $this->heuristicJsonCandidate($text);
            }

            if ($brokenFlowspec) {
                $errors = ['A resposta não é um JSON parseável: ' . json_last_error_msg() . '.'];
                $attempts[] = ['attempt' => $attempt, 'errors' => $errors];
                $prompt = $this->prompts->correctionPrompt($basePrompt, $text, $errors);

                continue;
            }

            if ($candidate === null) {
                $attempts[] = ['attempt' => $attempt, 'errors' => [], 'conversational' => true];
                $conversational = true;

                break; // question/clarification/analysis — nothing to correct
            }

            $normalization = $this->normalizer->normalize($candidate);
            $result = $this->validator->validate($normalization->document);

            $attempts[] = [
                'attempt' => $attempt,
                'errors'  => $result->errors,
                'fixes'   => $normalization->fixes,
            ];

            $document = $normalization->document; // best attempt so far

            if ($result->passes()) {
                $validated = true;

                break;
            }

            $prompt = $this->prompts->correctionPrompt($basePrompt, $text, $result->errors);
        }

        // A validated document has no credential violations by construction
        // (the scrubber runs inside the validator, so passes() implies clean).
        // Only the unvalidated best attempt can still carry a literal secret —
        // if it does, withhold it entirely: never persist or render a document
        // (or its raw text) that leaks a credential. The correction loop
        // already tried and failed to get a clean one.
        $credentialLeak = $document !== null && ! $validated && $this->scrubber->violations($document) !== [];

        if ($credentialLeak) {
            $document = null;
        }

        return new FlowspecGenerationResult(
            document: $document,
            text: $text,
            validated: $validated,
            credentialLeak: $credentialLeak,
            meta: [
                ...$context->toMeta(),
                'attempts' => $attempts,
                'tokens'   => $tokens,
                'provider' => config('services.flowspec.provider'),
                'model'    => config('services.flowspec.model'),
                // Snapshot of which guideline documents were active at
                // generation time — always all of them (no per-request
                // selection), but the content can change later, so this is
                // the historical record of what guidance actually applied.
                'guidelines' => $this->prompts->activeGuidelines()->pluck('title')->all(),
                'history_trimmed' => $built->trimmedHistoryTurns,
                // Only on an actual CONVERSATIONAL response — not when the
                // loop ran out of attempts with invalid JSON (there $document
                // is also null, but inferring suggestions from broken JSON
                // makes no sense).
                'suggested_documents' => $conversational ? $this->suggestedDocuments($chat, $text) : [],
            ],
        );
    }

    /**
     * "Adicionar ao contexto" buttons for a conversational response:
     * documentation the model cited by name while asking for more context, that
     * this conversation doesn't already have attached.
     *
     * This is the entire replacement for the old automatic injection — the same
     * name-matching, but the user decides. Nothing here costs a token until a
     * button is clicked.
     *
     * @return list<array{type: string, id: int, label: string}>
     */
    private function suggestedDocuments(FlowspecChat $chat, string $conversationalText): array
    {
        return $this->resolver->suggestFor($conversationalText, $this->resolver->attachedKeys($chat));
    }

    /**
     * Protected so tests can substitute the real API call with a test double.
     *
     * @param  list<object>  $attachments  PDFs/images the model reads natively
     *                                     (Laravel\Ai\Files\LocalDocument /
     *                                     LocalImage) instead of as inlined text
     */
    protected function prompt(string $prompt, array $attachments = []): AgentResponse
    {
        return agent(instructions: $this->prompts->systemPrompt())->prompt(
            $prompt,
            attachments: $attachments,
            provider: config('services.flowspec.provider'),
            model: config('services.flowspec.model'),
            timeout: (int) config('services.flowspec.timeout'),
        );
    }

    /**
     * JSON block inside a code fence (```json ... ```) — the model fenced the
     * JSON deliberately, so a `json_decode` failure here is a real model
     * error, not a misread on our end.
     */
    private function fencedJsonBlock(string $text): ?string
    {
        return preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $match) === 1
            ? $match[1]
            : null;
    }

    /**
     * With no code fence, scans from the first `{` to the last `}` — but only
     * treats it as a flowSpec ATTEMPT if that decodes to an array with a
     * `meta` or `flowSpec` key. The system prompt teaches a double-braces
     * syntax (`{{ step.alias.field }}`), so a purely conversational response
     * citing that syntax also contains `{`/`}` — without this filter, it
     * would get extracted, fail `json_decode` and burn a correction-loop
     * attempt on a meaningless "fix the JSON".
     *
     * @return array<string, mixed>|null
     */
    private function heuristicJsonCandidate(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $candidate = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($candidate) && $this->isFlowspecShape($candidate)
            ? $candidate
            : null;
    }

    /**
     * A decoded value is a flowSpec document (as opposed to some other JSON
     * fragment the model cited in a conversational answer) only if it carries
     * a `meta` or `flowSpec` key.
     *
     * @param  array<string, mixed>  $decoded
     */
    private function isFlowspecShape(array $decoded): bool
    {
        return array_key_exists('meta', $decoded) || array_key_exists('flowSpec', $decoded);
    }

    /**
     * Cheap textual check that a malformed fenced block was MEANT to be a
     * flowSpec — used to decide whether a JSON that failed to parse is a
     * broken generation attempt (correct it) or just an illustrative snippet
     * in a conversational answer (ignore it).
     */
    private function mentionsFlowspecKeys(string $json): bool
    {
        return str_contains($json, '"meta"') || str_contains($json, '"flowSpec"');
    }
}
