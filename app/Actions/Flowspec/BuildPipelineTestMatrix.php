<?php

namespace App\Actions\Flowspec;

use App\Services\Flowspec\FlowspecDocument;
use App\Support\Digibee\Testing\Assertion;
use App\Support\Digibee\Testing\AssertionOperator;
use App\Support\Digibee\Testing\PipelineTestCase;
use App\Support\Digibee\Testing\PipelineTestSuite;
use App\Support\Digibee\Testing\StatusExpectation;
use App\Support\Digibee\Testing\TestCaseCategory;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Derives a synthetic test matrix from a flowSpec — §3.4's generator, and the
 * one subsystem of the lifecycle that needs no credential and no route.
 *
 * **The input contract comes from the `{{ message.* }}` references.** That is
 * the finding this whole action rests on: a flowSpec never declares its own
 * input, but every field it READS off the incoming payload appears as a Double
 * Braces reference somewhere in it. Measured over the 201 pipelines in the
 * local export, 163 of them read at least one such field (most read 2 to 8), so
 * for four pipelines in five the request body is genuinely derivable rather
 * than guessed.
 *
 * **What it refuses to do is fabricate a routing payload.** §3.4 asks for
 * "payloads specifically engineered to trigger each choice condition", and for
 * most conditions that is not computable: a `choice` routing on
 * `#{body.RETURNING.STATUS} != '200'` after a REST call is deciding on a
 * downstream system's answer, and no request body can force it. The tempting
 * move is to emit a plausible payload anyway — and it is the worst one
 * available, because the case then runs, takes the happy path, and reports the
 * branch as covered. Those cases are emitted BLOCKED, carrying the condition
 * they need satisfied (see PipelineTestCase::$blocked).
 *
 * The exception is a narrow, checkable one: a condition that is a plain
 * equality on a field of the request body, at a `choice` nothing has rewritten
 * the message before. That subset IS solvable, so it is solved.
 */
class BuildPipelineTestMatrix
{
    /**
     * Steps that leave `message` as they found it, so a `choice` after one of
     * them is still deciding on the request body. Deliberately almost empty:
     * nearly every connector replaces the message (AGENTS.md says so out loud
     * for Object Store), and being wrong here means claiming a payload steers
     * a branch it cannot reach.
     */
    private const PASS_THROUGH = ['log-connector'];

    /**
     * One missing-field case per field gets long — the export has pipelines
     * reading eleven. Five is enough to catch a pipeline that crashes on
     * absent input without turning a suite into a fuzzer.
     */
    private const MAX_MISSING_FIELD_CASES = 5;

    /** @param array<string, mixed> $document the `{meta, flowSpec}` document */
    public function handle(array $document, string $pipelineName, string $environment = 'test'): PipelineTestSuite
    {
        $spec = FlowspecDocument::from($document);
        $entry = $this->entryBranch($spec);
        $fields = $this->messageFields($document);
        $skeleton = $this->skeleton($fields);

        return new PipelineTestSuite(
            name: Str::slug($pipelineName) . '-integration',
            pipelineName: $pipelineName,
            environment: $environment,
            // Present on a pipeline read back from the platform, absent on a
            // freshly generated document — and it is a segment of the URL the
            // runner calls, so it is carried rather than assumed to be 1.
            versionMajor: (int) ($document['versionMajor'] ?? 1),
            cases: [
                $this->happyPath($skeleton, $fields),
                ...$this->branchCoverage($spec, $entry, $skeleton),
                ...$this->errorHandlers($spec),
                ...$this->contractCases($skeleton, $fields),
            ],
        );
    }

    /**
     * The branch a request enters through — and it has two spellings, which is
     * not an accident. A generated document roots at
     * `disconnected-root:<uuid>` because it is written to be PASTED into the
     * canvas; all 201 pipelines in the tenant root at `start`, because that is
     * what a spec connected to its trigger looks like. Both have to be
     * understood here: the matrix is built from a generated document before
     * ingestion and from a read-back one after it.
     */
    private function entryBranch(FlowspecDocument $spec): ?string
    {
        foreach ($spec->branchNames() as $branch) {
            if (str_starts_with($branch, 'disconnected-root:') || in_array($branch, ['start', 'disconnected-start'], true)) {
                return $branch;
            }
        }

        return $spec->branchNames()[0] ?? null;
    }

    /**
     * Every field the pipeline reads off the incoming payload, as dotted paths.
     *
     * Array subscripts are dropped (`itens[0].sku` becomes `itens.sku`): the
     * skeleton cannot know how many elements a caller sends, and an assertion
     * about the second one would be an invention. The field is still reported,
     * which is the part that matters.
     *
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private function messageFields(array $document): array
    {
        // Scoped to `flowSpec`, not to the whole document. A pipeline read
        // back from the platform carries `metadata.disconnectedFlowSpecs` —
        // blocks somebody left lying on the canvas, which are not part of the
        // flow and whose `{{ message.* }}` references would inflate the input
        // contract with fields nothing live reads.
        preg_match_all(
            '/\{\{\s*message\.([A-Za-z0-9_.\[\]-]+)/',
            (string) json_encode($document['flowSpec'] ?? [], JSON_UNESCAPED_UNICODE),
            $matches,
        );

        $fields = [];

        foreach ($matches[1] as $raw) {
            $path = trim(preg_replace('/\[\d*\]/', '', $raw) ?? '', '.');

            // `{{ message.$ }}` is "the whole payload", not a field of it.
            if ($path !== '' && $path !== '$') {
                $fields[] = $path;
            }
        }

        $fields = array_values(array_unique($fields));
        sort($fields);

        return $fields;
    }

    /**
     * A request body carrying every field the pipeline reads, with placeholder
     * values that NAME themselves.
     *
     * The values are strings whatever the real type is, because a flowSpec does
     * not say — which is exactly why the happy-path case ships blocked when
     * there are fields to fill: nobody can invent a CPF that exists in SAP, and
     * a suite that pretended otherwise would report a data problem as a
     * pipeline defect.
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function skeleton(array $fields): array
    {
        $body = [];

        foreach ($fields as $field) {
            Arr::set($body, $field, "<{$field}>");
        }

        return $body;
    }

    /** @param list<string> $fields */
    private function happyPath(array $skeleton, array $fields): PipelineTestCase
    {
        return new PipelineTestCase(
            name: 'Caminho feliz',
            category: TestCaseCategory::HappyPath,
            expects: StatusExpectation::ok(),
            // Deliberately minimal. The output shape is not derivable from the
            // flowSpec, so the one thing worth asserting is that a body came
            // back at all — a 200 with an empty payload is a real Digibee
            // failure mode and the only content claim this can honestly make.
            assertions: [new Assertion('$', AssertionOperator::Exists)],
            body: $skeleton,
            blocked: $fields === []
                ? null
                : 'Preencha valores reais para: ' . implode(', ', $fields)
                    . '. Os placeholders são derivados das referências `{{ message.* }}` e nomeiam o campo, não um valor válido.',
        );
    }

    /**
     * One case per `choice` condition and per `otherwise`, named by the branch
     * it has to reach.
     *
     * @return list<PipelineTestCase>
     */
    private function branchCoverage(FlowspecDocument $spec, ?string $entry, array $skeleton): array
    {
        $cases = [];

        foreach ($spec->steps() as ['branch' => $branch, 'step' => $step]) {
            if (($step['type'] ?? null) !== 'choice') {
                continue;
            }

            $label = $step['stepName'] ?? $step['id'] ?? 'choice';
            $atEntry = $branch === $entry && $this->nothingRewroteTheMessage($spec, $branch, $step);

            foreach (is_array($step['when'] ?? null) ? $step['when'] : [] as $condition) {
                if (! is_array($condition)) {
                    continue;
                }

                $cases[] = $this->branchCase($label, $condition, $atEntry, $skeleton);
            }

            if (is_string($step['otherwise'] ?? null) && $step['otherwise'] !== '') {
                $cases[] = new PipelineTestCase(
                    name: "Roteia para «{$step['otherwise']}» (otherwise de «{$label}»)",
                    category: TestCaseCategory::BranchCoverage,
                    expects: StatusExpectation::handled(),
                    body: $skeleton,
                    covers: $step['otherwise'],
                    // `otherwise` is the negation of every `when` at once, so
                    // there is no single condition to satisfy or to print.
                    blocked: "Exige uma entrada que não satisfaça nenhuma condição de «{$label}».",
                );
            }
        }

        return $cases;
    }

    /** @param array<string, mixed> $condition */
    private function branchCase(string $label, array $condition, bool $atEntry, array $skeleton): PipelineTestCase
    {
        $target = is_string($condition['target'] ?? null) ? $condition['target'] : '?';
        $expression = $condition['simple'] ?? $condition['jsonPath'] ?? '?';
        $synthesized = $atEntry ? $this->synthesizeFromCondition($condition, $skeleton) : null;

        return new PipelineTestCase(
            name: "Roteia para «{$target}»",
            category: TestCaseCategory::BranchCoverage,
            expects: StatusExpectation::handled(),
            body: $synthesized ?? $skeleton,
            covers: $target,
            blocked: match (true) {
                $synthesized !== null => null,
                $atEntry              => "Ajuste o payload para satisfazer `{$expression}` — a condição é decidida pelo corpo da requisição, mas não é uma igualdade simples que dê para resolver sozinho.",
                default               => "A condição `{$expression}` decide sobre a saída de um step anterior, não sobre a requisição — exige um mock ou o sistema de origem devolvendo esse caso.",
            },
        );
    }

    /**
     * The one solvable subset: a plain equality against a field of the request
     * body, either as a Simple expression (`#{body.tipo} == 'A'`) or as a
     * single-comparison JsonPath filter (`$.[?(@.tipo == 'A')]`).
     *
     * Note the scope name: in a Simple expression the incoming payload is
     * `body`, not `message` — the two vocabularies sit side by side in the same
     * document and confusing them here would synthesize a payload that steers
     * nothing.
     *
     * @param  array<string, mixed>  $condition
     * @return array<string, mixed>|null
     */
    private function synthesizeFromCondition(array $condition, array $skeleton): ?array
    {
        $patterns = [
            '/^\s*#\{\s*body\.([A-Za-z0-9_.]+)\s*\}\s*(==|!=)\s*\'([^\']*)\'\s*$/',
            '/^\s*\$\.?\[\?\(\s*@\.([A-Za-z0-9_.]+)\s*(==|!=)\s*\'([^\']*)\'\s*\)\]\s*$/',
        ];

        foreach ([$condition['simple'] ?? null, $condition['jsonPath'] ?? null] as $expression) {
            if (! is_string($expression)) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $expression, $match) !== 1) {
                    continue;
                }

                [, $field, $operator, $literal] = $match;
                $body = $skeleton;

                // For `!=` any other value routes there, and a value that says
                // so is more useful to read than an arbitrary one.
                Arr::set($body, $field, $operator === '==' ? $literal : "<diferente de {$literal}>");

                return $body;
            }
        }

        return null;
    }

    /**
     * One case per exception track that actually has steps.
     *
     * None of these can be reached from a request payload — a track runs
     * because the step it belongs to threw — so they are all blocked. Emitting
     * them anyway is the point: an untested error handler is the most common
     * thing to be silently missing from a pipeline's coverage, and a matrix
     * that simply omitted it would report full coverage of a flow whose failure
     * path nobody has ever run.
     *
     * @return list<PipelineTestCase>
     */
    private function errorHandlers(FlowspecDocument $spec): array
    {
        $owners = [];

        foreach ($spec->steps() as ['step' => $step]) {
            foreach (['onProcess', 'onException'] as $track) {
                $reference = Arr::get($step, "params.{$track}");

                if (is_string($reference) && $reference !== '') {
                    $owners[$reference] = $step['stepName'] ?? $step['id'] ?? '?';
                }
            }
        }

        $cases = [];

        foreach ($spec->branchNames() as $branch) {
            if (! str_ends_with($branch, '-onExceptionTrack') || ($spec->branches[$branch] ?? []) === []) {
                continue;
            }

            $owner = $owners[$branch] ?? 'um step anterior';

            $cases[] = new PipelineTestCase(
                name: "Tratamento de erro de «{$owner}»",
                category: TestCaseCategory::ErrorHandler,
                expects: StatusExpectation::handled(),
                covers: $branch,
                blocked: "Exige que «{$owner}» falhe — um mock devolvendo erro, ou o sistema de origem indisponível.",
            );
        }

        return $cases;
    }

    /**
     * The cases the agent can genuinely run unattended, because they need no
     * valid data and no downstream behaviour: they assert only that bad input
     * is HANDLED rather than crashing (StatusExpectation::handled()).
     *
     * @param  list<string>  $fields
     * @return list<PipelineTestCase>
     */
    private function contractCases(array $skeleton, array $fields): array
    {
        $cases = [
            new PipelineTestCase(
                name: 'Corpo malformado',
                category: TestCaseCategory::Contract,
                expects: StatusExpectation::handled(),
                // A raw string, because there is no way to json_encode invalid
                // JSON — which is why PipelineTestCase::$body accepts one.
                body: '{"cpf": ',
            ),
        ];

        if ($fields === []) {
            return $cases;
        }

        $cases[] = new PipelineTestCase(
            name: 'Corpo vazio',
            category: TestCaseCategory::Contract,
            expects: StatusExpectation::handled(),
            body: [],
        );

        foreach (array_slice($fields, 0, self::MAX_MISSING_FIELD_CASES) as $field) {
            $body = $skeleton;
            Arr::forget($body, $field);

            $cases[] = new PipelineTestCase(
                name: "Sem o campo «{$field}»",
                category: TestCaseCategory::Contract,
                expects: StatusExpectation::handled(),
                body: $body,
            );
        }

        return $cases;
    }

    /**
     * Whether the message reaching this `choice` is still the request body.
     *
     * Conservative by construction: any step before it that is not in
     * PASS_THROUGH is assumed to have replaced `message`, because nearly every
     * connector does. Getting this wrong in the permissive direction is what
     * produces a payload that claims to steer a branch it cannot reach.
     *
     * @param  array<string, mixed>  $choice
     */
    private function nothingRewroteTheMessage(FlowspecDocument $spec, string $branch, array $choice): bool
    {
        foreach ($spec->branches[$branch] ?? [] as $step) {
            if (($step['id'] ?? null) === ($choice['id'] ?? null)) {
                return true;
            }

            if (! in_array($step['name'] ?? '', self::PASS_THROUGH, true)) {
                return false;
            }
        }

        return false;
    }
}
