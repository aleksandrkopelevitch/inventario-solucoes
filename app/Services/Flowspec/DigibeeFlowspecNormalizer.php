<?php

namespace App\Services\Flowspec;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Auto-fix barato dos erros mais comuns da IA antes do validador rodar:
 * UUIDs de step malformados/duplicados são regenerados (propagando para
 * `meta`, chaves de branch `<id>-on*Track` e `params.onProcess/onException`),
 * referências `{{ alias. }}` cruas ganham o prefixo `step.` quando o alias
 * existe, e steps de canvas sem `meta.position` recebem posição de grade.
 * O que não dá para corrigir com segurança fica para o validador apontar.
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
        $seen = [];

        foreach ($document['flowSpec'] as $steps) {
            foreach ((is_array($steps) ? $steps : []) as $step) {
                $id = is_array($step) ? ($step['id'] ?? null) : null;

                if (! is_string($id) || $id === '') {
                    continue;
                }

                if (preg_match(self::UUID_PATTERN, $id) !== 1 || isset($seen[$id])) {
                    $replacements[$id] = (string) Str::uuid();
                    $fixes[] = "UUID de step regenerado: {$id} -> {$replacements[$id]}.";
                }

                $seen[$id] = true;
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
     * `{{ jslt-3.token }}` -> `{{ step.jslt-3.token }}` quando `jslt-3` é um
     * `doubleBracesAlias` existente — o erro mais comum do modelo.
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
     * Steps de canvas (fora de tracks de for-each) sem posição ganham uma
     * grade simples: colunas de 200px por step, linhas de 150px por branch.
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

    /** Aplica $callback a todo valor string do documento, recursivamente. */
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
