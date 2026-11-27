<?php

namespace App\Services\Documentation;

use App\Exceptions\UmlDraftFailed;
use App\Support\Documentation\ModelJson;
use App\Support\Documentation\UmlDiagram;
use App\Support\Documentation\UmlSolutionBindings;
use App\Support\GitbookRenderer;
use Illuminate\Http\Client\ConnectionException;
use Laravel\Ai\Responses\AgentResponse;

use function Laravel\Ai\agent;

/**
 * Proposes the JSON body of a "Diagrama UML" block — from the page, from what
 * the author asked, or as a change to the diagram already in the block.
 * Spec: docs-and-specs/done/uml-ai-generation.md.
 *
 * The shape is SceneDraftService's — one call, at most one repair round,
 * answered inside the request because one person is watching one button, and
 * nothing written: the block takes the JSON and the editor saves the page.
 *
 * One difference, on purpose: a draft still wrong after the repair is
 * RETURNED with its problems rather than thrown away. A scene is a handful of
 * labels and costs nothing to regenerate; a diagram is a few KB of JSON whose
 * usual leftover is one element in the wrong cell, and the author fixes that
 * in "Código" in seconds. UmlDraftFailed is kept for when there is nothing to
 * show.
 *
 * What the repair is told comes from the page's own checks: the validator's
 * problems (UmlDiagram::parse()), the relations drawn through another element
 * (its warnings) and the slugs the catalog does not have (UmlSolutionBindings).
 */
class UmlDraftService
{
    public function __construct(
        private readonly UmlDraftPromptBuilder $prompts,
        private readonly UmlSolutionBindings $bindings,
    ) {}

    /**
     * @return array{source: string, problems: list<string>, warnings: list<string>, logos: array<string, string>}
     */
    public function draft(string $type, string $title, string $content, ?string $instruction = null, ?string $current = null): array
    {
        if (trim($content) === '' && blank($instruction)) {
            throw UmlDraftFailed::nothingToDraw();
        }

        $started = microtime(true);
        $prompt = $this->prompts->userPrompt($type, $title, $content, $instruction, $current, $this->prompts->catalog());
        $payload = $this->payload($type, $this->ask($type, $prompt));
        [$problems, $warnings] = $this->check($type, $title, $payload);

        // One repair round, and only while the request has time for it: past
        // half of the per-call ceiling, a second call risks the proxy cutting
        // the request with nothing to show for the first one.
        $budget = (int) config('services.documentation_ai.uml_timeout');
        if (($problems !== [] || $warnings !== []) && microtime(true) - $started < $budget / 2) {
            $retry = ModelJson::extract($this->ask($type, $this->prompts->repairPrompt(ModelJson::encode($payload), [...$problems, ...$warnings])));

            // A repair that came back unreadable or as a refusal does not
            // throw the first attempt away.
            if ($retry !== null && ! isset($retry['error'])) {
                $payload = $retry;
                [$problems, $warnings] = $this->check($type, $title, $payload);
            }
        }

        $parsed = UmlDiagram::parse($type, $title, ModelJson::encode($payload));

        return [
            // Two spaces, the style of the block's own skeleton. Only the
            // leading run: an encoded JSON string never spans a line, so the
            // start of a line is always indentation, never a value.
            'source' => (string) preg_replace_callback(
                '/^( +)/m',
                fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)),
                ModelJson::encode($payload),
            ),
            'problems' => $problems,
            'warnings' => $warnings,
            'logos'    => GitbookRenderer::umlLogos(UmlSolutionBindings::slugsOf($parsed['diagram'])),
        ];
    }

    /** @return array<mixed> */
    private function payload(string $type, string $reply): array
    {
        $payload = ModelJson::extract($reply);

        if ($payload === null) {
            throw UmlDraftFailed::noJson();
        }

        // The prompt's way out: "nothing here fits this kind of diagram".
        if (filled($payload['error'] ?? null) && ! isset($payload['participants']) && ! isset($payload['nodes'])) {
            throw UmlDraftFailed::notDescribed((string) $payload['error']);
        }

        // Told not to, a model still sometimes echoes the block's attributes.
        unset($payload['type'], $payload['title']);

        return $payload;
    }

    /**
     * What the page would refuse (validator problems, unknown slugs) and what
     * it would draw badly (relations through another element).
     *
     * @param  array<mixed>  $payload
     * @return array{0: list<string>, 1: list<string>}
     */
    private function check(string $type, string $title, array $payload): array
    {
        $parsed = UmlDiagram::parse($type, $title, ModelJson::encode($payload));

        if ($parsed['diagram'] === null) {
            return [$parsed['problems'], $parsed['warnings']];
        }

        return [$this->bindings->check($parsed['diagram'])['problems'], $parsed['warnings']];
    }

    /**
     * One call, with the one failure that is nobody's fault turned into words
     * (SceneDraftService::ask(), for the same provider that sometimes hangs).
     */
    private function ask(string $type, string $prompt): string
    {
        try {
            return $this->prompt($type, $prompt)->text;
        } catch (ConnectionException) {
            throw UmlDraftFailed::unavailable();
        }
    }

    /** Protected so tests can substitute the real API call with a test double. */
    protected function prompt(string $type, string $prompt): AgentResponse
    {
        return agent(instructions: $this->prompts->systemPrompt($type))->prompt(
            $prompt,
            provider: config('services.documentation_ai.provider'),
            model: config('services.documentation_ai.model'),
            timeout: (int) config('services.documentation_ai.uml_timeout'),
        );
    }
}
