<?php

namespace App\Services\Flowspec;

/**
 * Structured reading of a Digibee `{meta, flowSpec}` document.
 *
 * `flowSpec` is a branch => ordered list of steps map: the entry branch is
 * `disconnected-root:<uuid>`, for-each tracks end in
 * `-onProcessTrack`/`-onExceptionTrack` and the remaining branches are choice
 * targets (`when[].target`/`otherwise`). `meta` maps step id => position
 * {x,y} — steps inside for-each tracks do NOT have an entry in `meta` (they
 * live in the track's editor, not on the canvas).
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
     * Names of connectors used (steps `type: connector`), unique and sorted
     * — this is where `flowspec_examples.connectors` is derived from.
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

    /** Steps in for-each tracks don't appear on the canvas and don't need `meta.position`. */
    public static function branchIsForEachTrack(string $branch): bool
    {
        return str_ends_with($branch, '-onProcessTrack') || str_ends_with($branch, '-onExceptionTrack');
    }
}
