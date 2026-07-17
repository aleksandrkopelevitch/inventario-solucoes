<?php

namespace App\Services\Flowspec;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Cheap auto-fix for the AI's most common mistakes before the validator runs:
 * malformed/duplicated step UUIDs are regenerated (propagating to `meta`,
 * `<id>-on*Track` branch keys and `params.onProcess/onException`), raw
 * `{{ alias. }}` references get the `step.` prefix when the alias exists,
 * and canvas steps without `meta.position` get a grid position. Whatever
 * can't be safely fixed is left for the validator to flag.
 */
class DigibeeFlowspecNormalizer
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @param array<string, mixed> $document */
    public function normalize(array $document): NormalizationResult
    {
        if (! is_array($document['meta'] ?? null) || ! is_array($document['flowSpec'] ?? null)) {
            return new NormalizationResult($document, []);
        }

        $fixes = [];

        $document = $this->regenerateInvalidIds($document, $fixes);
        $document = $this->prefixRawAliasReferences($document, $fixes);
        $document = $this->fillMissingPositions($document, $fixes);

        return new NormalizationResult($document, $fixes);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $fixes
     * @return array<string, mixed>
     */
    private function regenerateInvalidIds(array $document, array &$fixes): array
    {
        $replacements = [];

        foreach ($document['flowSpec'] as $steps) {
            foreach ((is_array($steps) ? $steps : []) as $step) {
                $id = is_array($step) ? ($step['id'] ?? null) : null;

                if (! is_string($id) || $id === '' || isset($replacements[$id])) {
                    continue;
                }

                // Only genuinely malformed ids are rewritten. A well-formed but
                // DUPLICATED UUID is left untouched: meta/branch/`{{ step }}`
                // references are keyed by id, so two steps sharing one id are
                // ambiguous and can't be split without guessing which reference
                // points where. DigibeeFlowspecValidator flags `id duplicado`
                // and the correction loop re-prompts to fix it — unlike the old
                // code here, which mapped both occurrences to the SAME new UUID
                // (the duplication survived) while logging a misleading fix.
                if (preg_match(self::UUID_PATTERN, $id) !== 1) {
                    $replacements[$id] = (string) Str::uuid();
                    $fixes[] = "UUID de step regenerado: {$id} -> {$replacements[$id]}.";
                }
            }
        }

        if ($replacements === []) {
            return $document;
        }

        $meta = [];

        foreach ($document['meta'] as $id => $entry) {
            $meta[$replacements[$id] ?? $id] = $entry;
        }
        $document['meta'] = $meta;

        $flowSpec = [];

        foreach ($document['flowSpec'] as $branch => $steps) {
            foreach ($replacements as $old => $new) {
                if (str_starts_with($branch, $old)) {
                    $branch = $new . substr($branch, strlen($old));
                }
            }

            foreach ((is_array($steps) ? $steps : []) as $index => $step) {
                if (! is_array($step)) {
                    continue;
                }

                if (isset($replacements[$step['id'] ?? ''])) {
                    $steps[$index]['id'] = $replacements[$step['id']];
                }

                foreach (['onProcess', 'onException'] as $track) {
                    $reference = Arr::get($step, "params.{$track}");

                    if (is_string($reference)) {
                        foreach ($replacements as $old => $new) {
                            if (str_starts_with($reference, $old)) {
                                Arr::set($steps[$index], "params.{$track}", $new . substr($reference, strlen($old)));
                            }
                        }
                    }
                }
            }

            $flowSpec[$branch] = $steps;
        }
        $document['flowSpec'] = $flowSpec;

        return $document;
    }

    /**
     * `{{ jslt-3.token }}` -> `{{ step.jslt-3.token }}` when `jslt-3` is an
     * existing `doubleBracesAlias` — the model's most common mistake.
     *
     * @param  array<string, mixed>  $document
     * @param  list<string>  $fixes
     * @return array<string, mixed>
     */
    private function prefixRawAliasReferences(array $document, array &$fixes): array
    {
        $aliases = FlowspecDocument::from($document)->aliases();

        if ($aliases === []) {
            return $document;
        }

        $patterns = array_map(
            fn (string $alias) => '/\{\{(\s*)(' . preg_quote($alias, '/') . ')(\s*\.)/',
            $aliases,
        );

        return $this->mapStrings($document, function (string $value) use ($patterns, &$fixes) {
            $fixed = preg_replace_callback($patterns, function (array $match) use (&$fixes) {
                $fixes[] = "Referência \"{{ {$match[2]}. }}\" corrigida para \"{{ step.{$match[2]}. }}\".";

                return '{{' . $match[1] . 'step.' . $match[2] . $match[3];
            }, $value);

            return $fixed ?? $value;
        });
    }

    /**
     * Canvas steps (outside for-each tracks) without a position get a simple
     * grid: 200px columns per step, 150px rows per branch.
     *
     * @param  array<string, mixed>  $document
     * @param  list<string>  $fixes
     * @return array<string, mixed>
     */
    private function fillMissingPositions(array $document, array &$fixes): array
    {
        $row = 0;

        foreach ($document['flowSpec'] as $branch => $steps) {
            if (FlowspecDocument::branchIsForEachTrack($branch)) {
                continue;
            }

            foreach ((is_array($steps) ? $steps : []) as $column => $step) {
                $id = is_array($step) ? ($step['id'] ?? null) : null;

                if (! is_string($id) || $id === '') {
                    continue;
                }

                $position = Arr::get($document, "meta.{$id}.position");

                if (! is_numeric($position['x'] ?? null) || ! is_numeric($position['y'] ?? null)) {
                    $document['meta'][$id] = ['position' => ['x' => 200 + 200 * $column, 'y' => 150 * $row]];
                    $fixes[] = "Posição de canvas gerada para o step {$id}.";
                }
            }

            $row++;
        }

        return $document;
    }

    /** Applies $callback to every string value in the document, recursively. */
    private function mapStrings(mixed $value, callable $callback): mixed
    {
        if (is_string($value)) {
            return $callback($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        return array_map(fn ($child) => $this->mapStrings($child, $callback), $value);
    }
}
