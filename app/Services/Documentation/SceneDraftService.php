<?php

namespace App\Services\Documentation;

use App\Exceptions\SceneDraftFailed;
use App\Support\Documentation\ModelJson;
use App\Support\Documentation\StepScene;
use Laravel\Ai\Responses\AgentResponse;

use function Laravel\Ai\agent;

/**
 * Proposes a "fluxo em etapas" from a page's text.
 *
 * The same shape as DiagramDraftService, for the same reasons: one model call
 * and at most ONE repair round (the IR is small, and a payload still wrong
 * after being told exactly what is wrong is not going to converge), answered
 * inside the request because one person is watching one button.
 *
 * It WRITES NOTHING. The scene goes back to the editor, which drops it into the
 * block the author is editing; the page is saved by the editor like any other
 * change, so an author who dislikes the proposal simply edits or deletes it.
 *
 * The provider and model are the documentation assistant's
 * (`services.documentation_ai`), Gemini Flash today: the call chooses words,
 * not geometry, so a fast model is the right tool.
 */
class SceneDraftService
{
    public function __construct(private readonly SceneDraftPromptBuilder $prompts) {}

    public function draft(string $title, string $content, ?string $focus = null): StepScene
    {
        if (trim($content) === '') {
            throw SceneDraftFailed::emptyPage();
        }

        $payload = ModelJson::extract($this->prompt($this->prompts->userPrompt($title, $content, $focus))->text);

        if ($payload === null) {
            throw SceneDraftFailed::noJson();
        }

        // The prompt's way out: "this page describes no sequence of steps".
        if (filled($payload['error'] ?? null) && ! isset($payload['steps'])) {
            throw SceneDraftFailed::notDescribed((string) $payload['error']);
        }

        $problems = StepScene::validate($payload);

        if ($problems !== []) {
            $retry = ModelJson::extract(
                $this->prompt($this->prompts->repairPrompt(ModelJson::encode($payload), $problems))->text
            );

            if ($retry === null) {
                throw SceneDraftFailed::invalidDraft($problems);
            }

            $payload = $retry;
            $problems = StepScene::validate($payload);
        }

        if ($problems !== []) {
            throw SceneDraftFailed::invalidDraft($problems);
        }

        return StepScene::fromArray($payload);
    }

    /** Protected so tests can substitute the real API call with a test double. */
    protected function prompt(string $prompt): AgentResponse
    {
        return agent(instructions: $this->prompts->systemPrompt())->prompt(
            $prompt,
            provider: config('services.documentation_ai.provider'),
            model: config('services.documentation_ai.model'),
            timeout: (int) config('services.documentation_ai.scene_timeout'),
        );
    }
}
