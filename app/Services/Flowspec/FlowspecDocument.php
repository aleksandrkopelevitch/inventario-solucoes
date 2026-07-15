<?php

namespace App\Services\Flowspec;

/**
 * Leitura estruturada de um documento `{meta, flowSpec}` do Digibee.
 *
 * `flowSpec` é um mapa branch => lista ordenada de steps: o branch de entrada
 * é `disconnected-root:<uuid>`, tracks de for-each terminam em
 * `-onProcessTrack`/`-onExceptionTrack` e os demais branches são alvos de
 * choice (`when[].target`/`otherwise`). `meta` mapeia step id => position
 * {x,y} — steps dentro de tracks de for-each NÃO têm entrada em `meta`
 * (ficam no editor do track, não no canvas).
 */
final class FlowspecDocument
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, list<array<string, mixed>>>  $branches
     */
    private function __construct(
        public readonly array $meta,
        public readonly array $branches,
    ) {}

    /** @param array<string, mixed> $document */
    public static function from(array $document): self
    {
        $branches = [];

        foreach ((is_array($document['flowSpec'] ?? null) ? $document['flowSpec'] : []) as $branch => $steps) {
            $branches[$branch] = array_values(array_filter(is_array($steps) ? $steps : [], is_array(...)));
        }

        return new self(
            is_array($document['meta'] ?? null) ? $document['meta'] : [],
            $branches,
        );
    }

    /** @return list<array{branch: string, step: array<string, mixed>}> */
    public function steps(): array
    {
        $steps = [];

        foreach ($this->branches as $branch => $branchSteps) {
            foreach ($branchSteps as $step) {
                $steps[] = ['branch' => $branch, 'step' => $step];
            }
        }

        return $steps;
    }

    /**
     * Nomes de connector usados (steps `type: connector`), únicos e ordenados
     * — é daqui que `flowspec_examples.connectors` é derivado.
     *
     * @return list<string>
     */
    public function connectorNames(): array
    {
        $names = [];

        foreach ($this->steps() as ['step' => $step]) {
            if (($step['type'] ?? null) === 'connector' && is_string($step['name'] ?? null) && $step['name'] !== '') {
                $names[] = $step['name'];
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /** @return list<string> */
    public function aliases(): array
    {
        $aliases = [];

        foreach ($this->steps() as ['step' => $step]) {
            if (is_string($step['doubleBracesAlias'] ?? null) && $step['doubleBracesAlias'] !== '') {
                $aliases[] = $step['doubleBracesAlias'];
            }
        }

        return array_values(array_unique($aliases));
    }

    /** @return list<string> */
    public function branchNames(): array
    {
        return array_keys($this->branches);
    }

    /** Steps em tracks de for-each não aparecem no canvas e dispensam `meta.position`. */
    public static function branchIsForEachTrack(string $branch): bool
    {
        return str_ends_with($branch, '-onProcessTrack') || str_ends_with($branch, '-onExceptionTrack');
    }
}
