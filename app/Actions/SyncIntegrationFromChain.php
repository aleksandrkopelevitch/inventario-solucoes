<?php

namespace App\Actions;

use App\Enums\Direction;
use App\Models\Integration;
use Illuminate\Support\Facades\DB;

/**
 * Reconstrói os campos estruturados da Integração a partir do `chain` — o
 * grafo livre autorado inteiramente pelo data-viz F3 (`integration-viz.js`:
 * nó raiz na criação, blocos/ligações/religação depois) —, fonte de verdade
 * da topologia desde o desacoplamento do `diagram` (que voltou a ser um
 * desenho livre, sem efeito colateral em participants).
 *
 * `chain = {nodes: [{solution_id, label}], edges: [{from, to, arrow, protocol}]}`
 * — `from`/`to` são índices de `nodes`, não mais posições consecutivas.
 * Nós de texto livre (solution_id null) contam para o grau de entrada/saída
 * dos vizinhos, mas não viram participants (o pivot referencia solutions).
 */
class SyncIntegrationFromChain
{
    public function handle(Integration $integration): void
    {
        $chain = $integration->chain ?? [];
        $nodes = array_values($chain['nodes'] ?? []);
        $edges = array_values($chain['edges'] ?? []);

        // Grau de entrada/saída por índice do nó, seguindo o sentido da seta de cada ligação.
        $in = array_fill(0, count($nodes), 0);
        $out = array_fill(0, count($nodes), 0);
        $sawForward = false;
        $sawBackward = false;
        foreach ($edges as $edge) {
            $from = $edge['from'] ?? null;
            $to = $edge['to'] ?? null;
            if (! isset($nodes[$from]) || ! isset($nodes[$to])) {
                continue;
            }
            $arrow = $edge['arrow'] ?? '->';
            $sawForward = $sawForward || $arrow !== '<-';
            $sawBackward = $sawBackward || $arrow !== '->';
            if ($arrow !== '<-') { // '->' e '<->'
                $out[$from]++;
                $in[$to]++;
            }
            if ($arrow !== '->') { // '<-' e '<->'
                $out[$to]++;
                $in[$from]++;
            }
        }

        $solutionNodes = collect($nodes)
            ->map(fn ($node, $i) => ['index' => $i, 'solution_id' => $node['solution_id'] ?? null])
            ->filter(fn ($node) => ! empty($node['solution_id']))
            ->values();

        if ($solutionNodes->isEmpty()) {
            DB::transaction(function () use ($integration) {
                $integration->participants()->detach();
                $integration->update([
                    'source_solution_id' => null,
                    'target_solution_id' => null,
                ]);
            });

            return;
        }

        // Agregado por solução distinta (uma solução pode aparecer em mais de um nó).
        $inBySolution = [];
        $outBySolution = [];
        $positions = [];
        foreach ($solutionNodes as $node) {
            $sid = $node['solution_id'];
            $inBySolution[$sid] = ($inBySolution[$sid] ?? 0) + $in[$node['index']];
            $outBySolution[$sid] = ($outBySolution[$sid] ?? 0) + $out[$node['index']];
            $positions[$sid] = min($positions[$sid] ?? PHP_INT_MAX, $node['index']);
        }

        $sourceId = ($solutionNodes->first(fn ($n) => $inBySolution[$n['solution_id']] === 0) ?? $solutionNodes->first())['solution_id'];
        $targetId = ($solutionNodes->last(fn ($n) => $outBySolution[$n['solution_id']] === 0) ?? $solutionNodes->last())['solution_id'];

        $bidirectional = $sawForward && $sawBackward;

        // O protocolo é definido por ligação (chain.edges[i].protocol). O
        // escalar `integrations.protocol` guarda um valor representativo (a
        // primeira ligação com protocolo, na ordem armazenada) só para
        // exibição resumida — a verdade por ligação vive no chain.
        $protocol = collect($edges)->pluck('protocol')->first(fn ($p) => filled($p));

        $pivotData = collect($positions)->map(fn ($position) => ['position' => $position])->all();

        DB::transaction(function () use ($integration, $pivotData, $sourceId, $targetId, $bidirectional, $protocol) {
            $integration->participants()->detach();
            $integration->participants()->attach($pivotData);

            $integration->update([
                'source_solution_id' => $sourceId,
                'target_solution_id' => $targetId,
                'direction'          => ($bidirectional ? Direction::Bidirectional : Direction::Unidirectional)->value,
                'protocol'           => $protocol,
            ]);
        });
    }
}
