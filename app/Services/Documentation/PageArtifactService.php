<?php

namespace App\Services\Documentation;

use App\Enums\ArtifactDiagramType;
use App\Exceptions\PageArtifactFailed;
use App\Models\DocumentationPage;
use App\Support\Archify\ArchifyRunner;
use App\Support\Documentation\ModelJson;
use Laravel\Ai\Responses\AgentResponse;

use function Laravel\Ai\agent;

/**
 * Turns a documentation page into one rendered Archify artifact.
 *
 * Three stages, and the split between them is the point: the MODEL decides
 * semantics (who, in what order, in which lane), this class fills the geometry
 * that is mechanical rather than semantic, and ARCHIFY judges the result. A
 * spec that survives all three is rendered; one that does not is reported with
 * the validator's own words.
 *
 * Synchronous, for the same reason `DiagramDraftService` is: one menu item, one
 * person watching a spinner. It costs one model call plus one or two Node
 * invocations of a couple of seconds each, which is why `archify.timeout` is
 * 60s and not the AI default.
 */
class PageArtifactService
{
    /** Where the first message of a sequence sits, and the gap between them (the schema's floor is 160). */
    private const SEQUENCE_Y0 = 190;

    private const SEQUENCE_STEP = 56;

    public function __construct(
        private readonly PageArtifactPromptBuilder $prompts,
        private readonly ArchifyRunner $archify,
    ) {}

    /**
     * @return array{path: string, title: string} absolute path to the rendered
     *                                            HTML (caller owns cleanup)
     */
    public function render(DocumentationPage $page, ArtifactDiagramType $type): array
    {
        if (trim((string) $page->documentation) === '') {
            throw PageArtifactFailed::emptyPage();
        }

        $payload = ModelJson::extract($this->prompt($type, $this->prompts->userPrompt($page))->text);

        if ($payload === null) {
            throw PageArtifactFailed::noJson();
        }

        // The prompt gives the model an explicit way to say "this page has no
        // sequence in it", and that answer is worth more than a diagram of four
        // boxes invented to satisfy the request.
        if (filled($payload['error'] ?? null) && ! isset($payload['meta'])) {
            throw PageArtifactFailed::notDescribed((string) $payload['error']);
        }

        $payload = $this->normalize($payload, $type);

        $directory = storage_path('app/archify');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $specPath = tempnam($directory, 'spec-');
        $outPath = tempnam($directory, 'artifact-') . '.html';

        try {
            file_put_contents($specPath, ModelJson::encode($payload));

            $validation = $this->archify->validate($type->value, $specPath);

            if (! $validation->ok) {
                $payload = $this->repair($type, $payload, $validation->problems);
                file_put_contents($specPath, ModelJson::encode($payload));

                $validation = $this->archify->validate($type->value, $specPath);

                if (! $validation->ok) {
                    throw PageArtifactFailed::rejected($validation->problems);
                }
            }

            $delivery = $this->archify->deliver($type->value, $specPath, $outPath);

            if (! $delivery->ok || ! is_file($outPath)) {
                throw PageArtifactFailed::rejected($delivery->problems ?: ['a renderização falhou']);
            }
        } finally {
            @unlink($specPath);
        }

        return [
            'path'  => $outPath,
            'title' => trim((string) ($payload['meta']['title'] ?? '')) ?: $type->label(),
        ];
    }

    /**
     * One repair round, and only one — the same bound `DiagramDraftService`
     * keeps. A spec still refused after being handed the validator's own
     * diagnostics is not converging; it is a page the model could not read as
     * this kind of diagram, and two more calls would only say so later.
     *
     * @param  array<mixed>  $payload
     * @param  list<string>  $problems
     * @return array<mixed>
     */
    private function repair(ArtifactDiagramType $type, array $payload, array $problems): array
    {
        $retry = ModelJson::extract(
            $this->prompt($type, $this->prompts->repairPrompt(ModelJson::encode($payload), $problems))->text
        );

        if ($retry === null) {
            throw PageArtifactFailed::rejected($problems);
        }

        return $this->normalize($retry, $type);
    }

    /**
     * Everything the model must not be trusted to get right, or asked to care
     * about at all.
     *
     * The type and schema version are OURS: the menu item chose the type, so a
     * model that answered with a different one is answering a question nobody
     * asked. The visual preset is a deployment setting. And a sequence's `y` is
     * pure mechanics — a pixel per message, derived from the order the model
     * already expressed by listing them — so asking for it would be asking the
     * model to do arithmetic it is bad at, and to do it consistently across a
     * repair round.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function normalize(array $payload, ArtifactDiagramType $type): array
    {
        $payload['diagram_type'] = $type->value;
        $payload['schema_version'] = $type === ArtifactDiagramType::Workflow ? 2 : 1;

        $payload['meta'] = array_merge(
            is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            ['visual_preset' => (string) config('services.archify.visual_preset')],
        );

        // `output` would make the renderer write beside the spec instead of
        // where we told it to; the CLI takes the destination as an argument and
        // that is the only one we want honoured.
        unset($payload['meta']['output']);

        if ($type === ArtifactDiagramType::Sequence && is_array($payload['messages'] ?? null)) {
            $payload['messages'] = array_values(array_map(
                fn (mixed $message, int $i) => is_array($message)
                    ? array_merge($message, ['y' => self::SEQUENCE_Y0 + $i * self::SEQUENCE_STEP])
                    : $message,
                array_values($payload['messages']),
                array_keys(array_values($payload['messages'])),
            ));
        }

        return $payload;
    }

    /** Protected so tests can substitute the real API call with a test double. */
    protected function prompt(ArtifactDiagramType $type, string $prompt): AgentResponse
    {
        return agent(instructions: $this->prompts->systemPrompt($type))->prompt(
            $prompt,
            provider: config('services.documentation_ai.provider'),
            model: config('services.documentation_ai.model'),
            timeout: (int) config('services.documentation_ai.diagram_timeout'),
        );
    }
}
