<?php

namespace App\Services\Flowspec;

use Illuminate\Support\Arr;

/**
 * Garante que um `{meta, flowSpec}` gerado cola no canvas Digibee sem erro:
 * estrutura, posições em `meta`, branches de choice/for-each existentes,
 * referências Double Braces (o clássico `{{ alias. }}` sem `step.`),
 * componentes dentro do catálogo, segredo literal barrado e upsert de
 * Object Store completo. Os erros saem concretos, prontos para o re-prompt.
 */
class DigibeeFlowspecValidator
{
    private const VALID_SCOPES = ['message', 'global', 'account', 'step', 'metadata', 'trigger', 'session'];

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

    /** @param array<string, mixed> $document o JSON `{meta, flowSpec}` completo */
    public function validate(array $document): ValidationResult
    {
        if (! is_array($document['meta'] ?? null) || ! is_array($document['flowSpec'] ?? null)) {
            return new ValidationResult(['O documento precisa ter as chaves de topo `meta` (objeto) e `flowSpec` (objeto).']);
        }

        $spec = FlowspecDocument::from($document);
        $errors = [];

        $roots = array_filter($spec->branchNames(), fn (string $branch) => str_starts_with($branch, 'disconnected-root:'));

        if (count($roots) !== 1) {
            $errors[] = 'O `flowSpec` precisa de exatamente uma branch de entrada `disconnected-root:<uuid>` — encontradas: ' . count($roots) . '.';
        }

        $this->validateSteps($spec, $errors);
        $this->validateBranchReferences($spec, $errors);
        $this->validateDoubleBraces($document, $spec, $errors);

        foreach ($this->scrubber->violations($document) as $violation) {
            $errors[] = "Credencial literal: {$violation}.";
        }

        return new ValidationResult(array_values(array_unique($errors)));
    }

    /** @param list<string> $errors */
    private function validateSteps(FlowspecDocument $spec, array &$errors): void
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

            if (! FlowspecDocument::branchIsForEachTrack($branch)) {
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
                    if (! is_string($condition['jsonPath'] ?? null) || $condition['jsonPath'] === '') {
                        $errors[] = "Choice \"{$label}\": condição sem `jsonPath`.";
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

            if (($step['name'] ?? null) === 'for-each-connector') {
                foreach (['onProcess', 'onException'] as $track) {
                    $reference = Arr::get($step, "params.{$track}");

                    if (! in_array($reference, $branches, true)) {
                        $errors[] = "For-each \"{$label}\": `params.{$track}` \"{$reference}\" não existe como branch no `flowSpec`.";
                    }
                }
            }
        }
    }

    /**
     * Varre toda string do documento atrás de `{{ ... }}`: referência a step
     * anterior exige o prefixo `step.` (`{{ step.alias.campo }}` — nunca
     * `{{ alias.campo }}`), o alias precisa existir, e o escopo inicial deve
     * ser válido. Chamadas de função (`{{ UUID() }}`, `{{ CONCAT(...) }}`)
     * são ignoradas.
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
                    continue; // função Double Braces (UUID(), CONCAT(), NOW()...)
                }

                if ($identifier === 'step') {
                    continue; // validado abaixo, com o alias completo
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

    /** @return list<string> todos os valores string do documento, recursivamente */
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
