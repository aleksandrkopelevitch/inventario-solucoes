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

    /**
     * The sequence renderer's own geometry, which decides how much room a given
     * canvas has: the timeline runs from `lifelineTop` (142) to
     * `viewBox[1] - 65`, and a message must sit 18px inside both ends. So the
     * usable floor is 160 and the ceiling is `viewBox[1] - 83` — which is where
     * the default 760-high canvas gets its 677.
     *
     * Horizontally the same: with the default `column_fit` the lanes sit at a
     * fixed 108px pitch starting 62px in, each 86px wide.
     */
    private const SEQUENCE_DEFAULT_VIEWBOX = [920, 760];

    /**
     * How much canvas to keep below the last message.
     *
     * The timeline's own floor needs 83 (see above), and the LEGEND takes the
     * rest: it grows upward from `viewBox[1] - 54`, one 22px row at a time, so
     * its top is `viewBox[1] - 84` for a single row and higher for every row
     * after that. At 83 the last message landed one pixel inside a one-row
     * legend — "path 14 crosses legend Legend".
     *
     * 150 clears a three-row legend, which is more than the five relationship
     * variants a sequence can use will ever need. It is deliberately generous
     * rather than computed: this geometry is OURS, and the repair round cannot
     * save a mistake in it — the model is never shown the canvas, and every
     * attempt would be normalized back to the same numbers.
     */
    private const SEQUENCE_BOTTOM_RESERVE = 150;

    private const SEQUENCE_COL_PITCH = 108;

    private const SEQUENCE_SIDE_RESERVE = 145;

    /**
     * The dataflow canvas has the same shape of problem on its other axis: the
     * stages are columns at a fixed pitch, and the renderer's default 940-wide
     * canvas already overflows at FIVE of them — which is the most its schema
     * allows, so the maximum legal diagram did not fit the default canvas.
     * Measured against the CLI: 5 stages need 1068 and validate at 1120.
     */
    private const DATAFLOW_DEFAULT_VIEWBOX = [940, 720];

    private const DATAFLOW_STAGE_WIDTH = 230;

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
            $payload = $this->layOutSequence($payload);
        }

        if ($type === ArtifactDiagramType::Dataflow) {
            $stages = count(array_filter((array) ($payload['stages'] ?? []), 'is_array'));

            $payload['meta']['viewBox'] = [
                max(self::DATAFLOW_DEFAULT_VIEWBOX[0], $stages * self::DATAFLOW_STAGE_WIDTH),
                self::DATAFLOW_DEFAULT_VIEWBOX[1],
            ];
        }

        return $payload;
    }

    /**
     * Stacks the messages down the timeline AND grows the canvas to hold them.
     *
     * The second half is the one that was missing, and production found it: a
     * fixed `y` step against the renderer's default 760-high canvas fits
     * exactly nine messages, and the tenth was refused with "sits outside the
     * readable timeline". Squeezing the step to fit would have been the wrong
     * repair — a sequence diagram is SUPPOSED to get taller as the exchange
     * gets longer, and the renderer says so itself: "a taller viewBox gains
     * message room". So the spacing stays readable and the canvas follows the
     * content.
     *
     * The width follows the participant count for the same reason: the lanes
     * are laid out at a fixed pitch, so a dozen of them run off the side of a
     * default-width canvas exactly as a dozen messages ran off the bottom.
     *
     * A viewBox the MODEL supplied is ignored rather than merged. Geometry is
     * this class's half of the contract (the prompt never mentions a canvas),
     * and half-honouring one would reintroduce the same failure through a
     * different door.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function layOutSequence(array $payload): array
    {
        $messages = array_values(array_filter($payload['messages'], 'is_array'));

        $payload['messages'] = array_map(
            fn (array $message, int $i) => array_merge($message, ['y' => self::SEQUENCE_Y0 + $i * self::SEQUENCE_STEP]),
            $messages,
            array_keys($messages),
        );

        $lastY = self::SEQUENCE_Y0 + max(0, count($messages) - 1) * self::SEQUENCE_STEP;
        $participants = count(array_filter((array) ($payload['participants'] ?? []), 'is_array'));

        $payload['meta']['viewBox'] = [
            max(
                self::SEQUENCE_DEFAULT_VIEWBOX[0],
                max(0, $participants - 1) * self::SEQUENCE_COL_PITCH + self::SEQUENCE_SIDE_RESERVE,
            ),
            max(self::SEQUENCE_DEFAULT_VIEWBOX[1], $lastY + self::SEQUENCE_BOTTOM_RESERVE),
        ];

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
