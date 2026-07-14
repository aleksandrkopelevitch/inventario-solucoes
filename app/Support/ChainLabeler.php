<?php

namespace App\Support;

use App\Models\Solution;
use Illuminate\Support\Collection;

/**
 * Rótulo humano de uma cadeia de integração (`{nodes, edges}` — cada edge é
 * `{from, to, arrow, protocol}` por índice de nó, não mais posicional).
 * Compartilhado entre o controller (nome derivado quando a criação não
 * informa um) e a lista de integrações do detalhe da solução
 * (`Solutions\IntegrationsMap`), para não duplicar a montagem do texto
 * "A -> B -> C".
 */
class ChainLabeler
{
    /**
     * Soluções referenciadas pelos chains dados — uma query só, para rotular
     * nós e (na F3) linkar para o detalhe da solução, sem N+1. Também traz
     * `environment`/`cloud`/`logo_path` — usados por `Solutions\IntegrationsMap`
     * para destacar hospedagem/cloud e o logo em cima de cada bloco no
     * data-viz (`integration-viz.js`).
     *
     * @param  Collection<int, array|null>  $chains
     * @return Collection<int, Solution>
     */
    public function resolveSolutions(Collection $chains): Collection
    {
        $ids = $chains->filter()
            ->flatMap(fn (array $chain) => collect($chain['nodes'] ?? [])->pluck('solution_id'))
            ->filter()
            ->unique();

        return $ids->isEmpty()
            ? collect()
            : Solution::whereIn('id', $ids)->get(['id', 'name', 'slug', 'environment', 'cloud', 'logo_path'])->keyBy('id');
    }

    /**
     * Texto completo da cadeia — vira o nome da integração quando o campo
     * fica em branco. Quando a cadeia é uma linha simples (`isLinear()`),
     * produz "A -> B -> C" andando os nós em ordem. Uma cadeia religada em
     * grafo livre no data-viz (F3) não tem mais essa ordem única — lista cada
     * ligação separadamente ("A -> B, B -> C, A -> C"), seguida (se houver)
     * dos blocos sem nenhuma ligação — "livres" no grafo, mas que ainda
     * precisam aparecer no resumo.
     */
    public function label(array $chain, Collection $solutions): string
    {
        $nodeLabels = collect($chain['nodes'] ?? [])->map(fn ($node) => $this->nodeLabel($node, $solutions))->values();
        $edges = collect($chain['edges'] ?? [])->values();

        if ($nodeLabels->isEmpty()) {
            return '';
        }

        if ($this->isLinear($chain)) {
            return $nodeLabels->reduce(
                fn (?string $carry, string $label, int $i) => $carry === null ? $label : "{$carry} {$edges[$i - 1]['arrow']} {$label}",
            ) ?? '';
        }

        if ($edges->isEmpty()) {
            return $nodeLabels->implode(', ');
        }

        $connected = $edges->flatMap(fn ($edge) => [$edge['from'] ?? null, $edge['to'] ?? null])->filter(fn ($i) => $i !== null)->unique();
        $isolated = $nodeLabels->keys()->diff($connected)->map(fn ($i) => $nodeLabels[$i]);

        return $edges->map(fn ($edge) => sprintf(
            '%s %s %s',
            $nodeLabels[$edge['from'] ?? null] ?? '?',
            $edge['arrow'] ?? '->',
            $nodeLabels[$edge['to'] ?? null] ?? '?',
        ))->concat($isolated)->implode(', ');
    }

    /**
     * Uma cadeia é "linear" quando `edges[i]` liga sempre `nodes[i]` a
     * `nodes[i+1]`, na ordem — usado só por `label()` acima para escolher o
     * formato do resumo textual ("A -> B -> C" vs. lista de ligações
     * separadas). Toda integração nasce linear (só o nó raiz); assim que o
     * data-viz (F3) religa uma ligação pra um nó fora dessa sequência, a
     * cadeia deixa de ser linear.
     */
    public function isLinear(array $chain): bool
    {
        $nodeCount = count($chain['nodes'] ?? []);
        $edges = array_values($chain['edges'] ?? []);

        if (count($edges) !== max(0, $nodeCount - 1)) {
            return false;
        }

        foreach ($edges as $i => $edge) {
            if (($edge['from'] ?? null) !== $i || ($edge['to'] ?? null) !== $i + 1) {
                return false;
            }
        }

        return true;
    }

    /** @param  array{solution_id?: int|null, label?: string|null}  $node */
    public function nodeLabel(array $node, Collection $solutions): string
    {
        return $solutions[$node['solution_id'] ?? null]?->name ?? $node['label'] ?? '?';
    }
}
