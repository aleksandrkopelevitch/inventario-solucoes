<?php

namespace App\Services\Documentation;

use App\Enums\DiagramModel;
use App\Exceptions\DiagramDraftFailed;
use App\Models\DocumentationPage;
use App\Support\Diagrams\ModelSpec;
use App\Support\Documentation\ModelJson;
use Laravel\Ai\Responses\AgentResponse;

use function Laravel\Ai\agent;

/**
 * Reads a page and proposes one of the four diagram models for it.
 *
 * The sibling of `DiagramDraftService`, and deliberately its twin: one model
 * call, at most one repair round against our own validator's sentences, and a
 * spec carrying no geometry at all. What differs is only the vocabulary — a
 * sequence has participants and messages where a free graph has blocks and
 * links — which is why the prompts are per model and everything else here is
 * not.
 */
class DiagramModelService
{
    public function __construct(private readonly DiagramModelPromptBuilder $prompts) {}

    public function draft(DocumentationPage $page, DiagramModel $model): ModelSpec
    {
        if (trim((string) $page->documentation) === '') {
            throw DiagramDraftFailed::emptyPage();
        }

        $payload = ModelJson::extract($this->prompt($model, $this->prompts->userPrompt($page))->text);

        if ($payload === null) {
            throw DiagramDraftFailed::noJson();
        }

        // The prompt gives the model a way to say "this page has no sequence in
        // it", and that answer is worth more than four boxes invented to
        // satisfy the request.
        if (filled($payload['error'] ?? null) && ! isset($payload[ModelSpec::keys($model)[0]])) {
            throw DiagramDraftFailed::notDescribed((string) $payload['error']);
        }

        $problems = ModelSpec::validate($model, $payload);

        if ($problems !== []) {
            $retry = ModelJson::extract(
                $this->prompt($model, $this->prompts->repairPrompt(ModelJson::encode($payload), $problems))->text
            );

            if ($retry === null) {
                throw DiagramDraftFailed::invalidDraft($problems);
            }

            $payload = $retry;
            $problems = ModelSpec::validate($model, $payload);
        }

        if ($problems !== []) {
            throw DiagramDraftFailed::invalidDraft($problems);
        }

        return ModelSpec::from($model, $payload);
    }

    /** Protected so tests can substitute the real API call with a test double. */
    protected function prompt(DiagramModel $model, string $prompt): AgentResponse
    {
        return agent(instructions: $this->prompts->systemPrompt($model))->prompt(
            $prompt,
            provider: config('services.documentation_ai.provider'),
            model: config('services.documentation_ai.model'),
            timeout: (int) config('services.documentation_ai.diagram_timeout'),
        );
    }
}
