<?php

namespace App\Services\Flowspec;

use App\Enums\FlowspecTarget;
use Illuminate\Support\Arr;

/**
 * Ensures a generated `{meta, flowSpec}` pastes into the Digibee canvas
 * without error: structure, positions in `meta`, existing choice/track
 * branches (for-each, retry, block-execution — see TRACK_CONNECTORS), Double
 * Braces references (the classic `{{ alias. }}` missing `step.`), components
 * within the catalog, no literal secrets, and complete Object Store upserts.
 * Errors come out concrete, ready for the re-prompt.
 *
 * Two of those checks are about the paste format specifically, so they answer
 * to App\Enums\FlowspecTarget: the entry branch is `disconnected-root:<uuid>`
 * for a document going to the clipboard and `start` for one going into a
 * pipeline, and `meta.position` is required only for the first. Run with the
 * wrong target, this validator is exactly inverted — it rejects every one of
 * the 201 pipelines the tenant actually runs, and passes documents that can
 * never be ingested. That is why the target is an argument with a default
 * rather than something inferred from the document: inferring it would mean
 * reading the mistake as the intent.
 */
class DigibeeFlowspecValidator
{
    /**
     * Double Braces scopes, and the last two are not padding: `iterators` is
     * how the For Each connector's own reference reads the current item
     * (`{{iterators.<for-each-alias>.current}}`) and `replica` is what the
     * multi-instance guide uses for an instance variable
     * (`{{replica.instance_variable_name}}`). Both are documented by Digibee
     * and both appear in the tenant's live pipelines, and while they were
     * missing here the loop rejected them — so a for-each body written the
     * documented way was sent back to the model as an unknown scope, over and
     * over, until the attempts ran out.
     *
     * @var list<string>
     */
    private const VALID_SCOPES = ['message', 'global', 'account', 'step', 'metadata', 'trigger', 'session', 'iterators', 'replica'];

    /**
     * Connectors whose track params must reference existing branches, mapped
     * to which tracks they actually carry — NOT all of them carry both:
     * `do-while-connector` only ever has `onProcess` (no exception track), so
     * a flat "check both" would falsely flag every valid do-while step.
     *
     * @var array<string, list<string>>
     */
    private const TRACK_CONNECTORS = [
        'for-each-connector'                => ['onProcess', 'onException'],
        'retry-connector'                   => ['onProcess', 'onException'],
        'block-execution-connector'         => ['onProcess', 'onException'],
        'stream-json-file-reader-connector' => ['onProcess', 'onException'],
        'do-while-connector'                => ['onProcess'],
    ];

    /**
     * Tracks that are legitimately ABSENT, measured over the 201 pipelines the
     * tenant runs: `onProcess` is a real branch in 404 of 404 references,
     * while `onException` is simply not there in 296 of 384 — and it is never
     * a dangling name. An exception track is an option the canvas leaves
     * empty by default, so demanding one rejected 101 of the 201 live
     * pipelines.
     *
     * That mattered beyond this file. Block F's signal includes validating a
     * pipeline read BACK from the platform, and a rule describing nothing real
     * would have sent the correction loop off to invent exception tracks for
     * pipelines that were already right — the same shape as the `simple`
     * choice condition and the credential scrubber's date keys before it.
     *
     * A reference that is PRESENT and names a branch that does not exist is
     * still an error: that is a typo, not an omission.
     *
     * @var list<string>
     */
    private const OPTIONAL_TRACKS = ['onException'];

    /** @var array{step_types: list<string>, connector_names: list<string>} */
    private readonly array $catalog;

    public function __construct(private readonly CredentialScrubber $scrubber)
    {
        $this->catalog = json_decode(
            file_get_contents(database_path('data/digibee_component_catalog.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @param array<string, mixed> $document the complete `{meta, flowSpec}` JSON */
    public function validate(array $document, FlowspecTarget $target = FlowspecTarget::Clipboard): ValidationResult
    {
        if (! is_array($document['flowSpec'] ?? null)) {
            return new ValidationResult(['O documento precisa ter a chave de topo `flowSpec` (objeto).']);
        }

        if ($target->usesCanvasPositions() && ! is_array($document['meta'] ?? null)) {
            return new ValidationResult(['O documento precisa ter as chaves de topo `meta` (objeto) e `flowSpec` (objeto).']);
        }

        $spec = FlowspecDocument::from($document);
        $errors = [];

        $roots = array_filter($spec->branchNames(), fn (string $branch) => $target->isRootBranch($branch));

        if (count($roots) !== 1) {
            $expected = $target === FlowspecTarget::Platform ? '`start`' : '`disconnected-root:<uuid>`';
            $errors[] = "O `flowSpec` precisa de exatamente uma branch de entrada {$expected} "
                . '(destino: ' . $target->label() . ') — encontradas: ' . count($roots) . '.';
        }

        $this->validateSteps($spec, $target, $errors);
        $this->validateBranchReferences($spec, $errors);
        $this->validateDoubleBraces($document, $spec, $errors);

        foreach ($this->scrubber->violations($document) as $violation) {
            $errors[] = "Credencial literal: {$violation}.";
        }

        return new ValidationResult(array_values(array_unique($errors)));
    }

    /** @param list<string> $errors */
    private function validateSteps(FlowspecDocument $spec, FlowspecTarget $target, array &$errors): void
    {
        $seenIds = [];
        $seenAliases = [];

        foreach ($spec->steps() as ['branch' => $branch, 'step' => $step]) {
            $id = $step['id'] ?? null;
            $label = $step['stepName'] ?? $id ?? '?';

            if (! is_string($id) || $id === '') {
                $errors[] = "Step \"{$label}\" na branch \"{$branch}\" sem `id`.";

                continue;
            }

            if (isset($seenIds[$id])) {
                $errors[] = "`id` duplicado entre steps: {$id}.";
            }
            $seenIds[$id] = true;

            $alias = $step['doubleBracesAlias'] ?? null;

            if (is_string($alias) && $alias !== '') {
                if (isset($seenAliases[$alias])) {
                    $errors[] = "`doubleBracesAlias` duplicado entre steps: {$alias}.";
                }
                $seenAliases[$alias] = true;
            }

            $type = $step['type'] ?? null;

            if (! in_array($type, $this->catalog['step_types'], true)) {
                $errors[] = "Step \"{$label}\": `type` \"{$type}\" fora do catálogo (" . implode(', ', $this->catalog['step_types']) . ').';
            }

            if ($type === 'connector' && ! in_array($step['name'] ?? null, $this->catalog['connector_names'], true)) {
                $errors[] = "Step \"{$label}\": connector \"" . ($step['name'] ?? '') . '" fora do catálogo (' . implode(', ', $this->catalog['connector_names']) . ').';
            }

            if ($target->usesCanvasPositions() && ! FlowspecDocument::branchIsForEachTrack($branch)) {
                $position = Arr::get($spec->meta, "{$id}.position");

                if (! is_numeric($position['x'] ?? null) || ! is_numeric($position['y'] ?? null)) {
                    $errors[] = "Step \"{$label}\" ({$id}): sem `meta[\"{$id}\"].position` com `x`/`y` numéricos (obrigatório fora de tracks de for-each).";
                }
            }

            $this->validateObjectStoreUpsert($step, $label, $errors);
        }
    }

    /** @param list<string> $errors */
    private function validateBranchReferences(FlowspecDocument $spec, array &$errors): void
    {
        $branches = $spec->branchNames();

        foreach ($spec->steps() as ['step' => $step]) {
            $label = $step['stepName'] ?? $step['id'] ?? '?';

            if (($step['type'] ?? null) === 'choice') {
                $when = $step['when'] ?? null;

                if (! is_array($when) || $when === []) {
                    $errors[] = "Choice \"{$label}\": `when` precisa ser uma lista não vazia de condições.";
                    $when = [];
                }

                foreach ($when as $condition) {
                    // A condition routes by EITHER a JSONPath or a Simple
                    // expression (`#{body.STATUS} != '200'`) — Digibee's canvas
                    // offers both, and 18 of the 612 conditions across the 182
                    // deployed Leo Madeiras pipelines use `simple`. Demanding
                    // `jsonPath` rejected those as malformed and sent the model
                    // off to "fix" a choice that was already correct.
                    $hasJsonPath = is_string($condition['jsonPath'] ?? null) && $condition['jsonPath'] !== '';
                    $hasSimple = is_string($condition['simple'] ?? null) && $condition['simple'] !== '';

                    if (! $hasJsonPath && ! $hasSimple) {
                        $errors[] = "Choice \"{$label}\": condição sem `jsonPath` nem `simple`.";
                    }

                    $target = $condition['target'] ?? null;

                    if (! in_array($target, $branches, true)) {
                        $errors[] = "Choice \"{$label}\": `target` \"{$target}\" não existe como branch no `flowSpec`.";
                    }
                }

                $otherwise = $step['otherwise'] ?? null;

                if ($otherwise !== null && ! in_array($otherwise, $branches, true)) {
                    $errors[] = "Choice \"{$label}\": `otherwise` \"{$otherwise}\" não existe como branch no `flowSpec`.";
                }
            }

            foreach (self::TRACK_CONNECTORS[$step['name'] ?? ''] ?? [] as $track) {
                $reference = Arr::get($step, "params.{$track}");

                if (($reference === null || $reference === '') && in_array($track, self::OPTIONAL_TRACKS, true)) {
                    continue; // no exception track — the ordinary state (see OPTIONAL_TRACKS)
                }

                if (! in_array($reference, $branches, true)) {
                    $errors[] = "Step \"{$label}\": `params.{$track}` \"{$reference}\" não existe como branch no `flowSpec`.";
                }
            }
        }
    }

    /**
     * Scans every string in the document for `{{ ... }}`: a reference to a
     * previous step requires the `step.` prefix (`{{ step.alias.field }}` —
     * never `{{ alias.field }}`), the alias must exist, and the initial scope
     * must be valid. Function calls (`{{ UUID() }}`, `{{ CONCAT(...) }}`) are
     * ignored.
     *
     * @param  array<string, mixed>  $document
     * @param  list<string>  $errors
     */
    private function validateDoubleBraces(array $document, FlowspecDocument $spec, array &$errors): void
    {
        $aliases = $spec->aliases();

        foreach ($this->allStrings($document) as $value) {
            preg_match_all('/\{\{\s*([A-Za-z_][A-Za-z0-9_-]*)\s*([.(])/', $value, $matches, PREG_SET_ORDER);

            foreach ($matches as [, $identifier, $next]) {
                if ($next === '(') {
                    continue; // Double Braces function (UUID(), CONCAT(), NOW()...)
                }

                if ($identifier === 'step') {
                    continue; // validated below, with the full alias
                }

                if (in_array($identifier, $aliases, true)) {
                    $errors[] = "Referência \"{{ {$identifier}. }}\" sem o prefixo `step.` — use \"{{ step.{$identifier}. }}\".";

                    continue;
                }

                if (! in_array($identifier, self::VALID_SCOPES, true)) {
                    $errors[] = "Escopo Double Braces desconhecido \"{{ {$identifier}. }}\" — válidos: " . implode(', ', self::VALID_SCOPES) . '.';
                }
            }

            preg_match_all('/\{\{\s*step\.([A-Za-z0-9_-]+)/', $value, $stepRefs);

            foreach ($stepRefs[1] as $alias) {
                if (! in_array($alias, $aliases, true)) {
                    $errors[] = "Referência \"{{ step.{$alias} }}\" aponta para um `doubleBracesAlias` que não existe em nenhum step.";
                }
            }
        }
    }

    /** @param list<string> $errors */
    private function validateObjectStoreUpsert(array $step, string $label, array &$errors): void
    {
        if (($step['name'] ?? null) !== 'object-store-connector') {
            return;
        }

        $params = is_array($step['params'] ?? null) ? $step['params'] : [];

        if (($params['operation'] ?? null) === 'UPDATE' && ($params['upsert'] ?? false) === true) {
            if (($params['unique'] ?? null) !== true) {
                $errors[] = "Object Store \"{$label}\": UPDATE com `upsert` exige `unique: true`.";
            }

            if (! is_string($params['objectId'] ?? null) || $params['objectId'] === '') {
                $errors[] = "Object Store \"{$label}\": UPDATE com `upsert` exige `objectId` preenchido.";
            }
        }
    }

    /** @return list<string> all string values in the document, recursively */
    private function allStrings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $child) {
            $strings = array_merge($strings, $this->allStrings($child));
        }

        return $strings;
    }
}
