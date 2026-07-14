<?php

namespace App\Services;

use App\Enums\Direction;
use App\Enums\IntegrationStatus;
use App\Enums\Protocol;
use App\Models\Integration;
use App\Models\Solution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Resolve o grafo de integrações para o contrato NEUTRO (agnóstico de
 * renderizador) descrito na seção 10 do briefing. O mapeamento para o schema
 * do canvas acontece no cliente, de modo que trocar o renderizador no futuro
 * não muda o servidor.
 *
 * Formato de retorno:
 *
 *     [
 *         'nodes' => [ ['id','label','slug','category','logo','url',
 *             'categoryLabel','statusLabel','criticalityLabel','environmentLabel',
 *             'cloudLabel','contractLabel','supportLabel','directorate',
 *             'mapPosition','positionUrl'], ... ],
 *         'edges' => [ ['id','source','target','label','status','direction','integrations'], ... ],
 *     ]
 *
 * Regras (seção 10):
 * - Cada aresta candidata vem de uma ligação real de `chain.edges` (não de
 *   adjacência de `integration_solution.position` — a chain é um grafo livre
 *   desde o data-viz F3, então duas soluções "vizinhas" na posição do pivot
 *   podem não ter ligação nenhuma entre si). Soluções iPaaS (ex.: Digibee)
 *   são participantes comuns da cadeia — o conceito de orquestrador foi
 *   removido.
 * - O mapa global exibe **uma aresta por par** de soluções (não uma por
 *   segmento de chain): `dedupePairs()` agrupa todas as arestas candidatas
 *   entre o mesmo par (de integrações diferentes, ou revisitado na mesma
 *   integração) numa só, agregando direção (bidirecional se há fluxo nos dois
 *   sentidos), status (a mais "saudável" do grupo) e protocolo (rótulos
 *   distintos, juntados). Evita o emaranhado de várias curvas sobrepostas
 *   entre o mesmo par no layout radial hub-and-spoke (`ecosystem-map.js`).
 */
class IntegrationGraphService
{
    /**
     * Mapa global: todo o ecossistema, com filtros opcionais por query string.
     * Filtros aceitos: `status` (default todas), `category`, `directorate`.
     *
     * @param  array<string, string|null>  $filters
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    public function globalMap(array $filters = []): array
    {
        $status = $filters['status'] ?? null;
        $category = $filters['category'] ?? null;
        $directorate = $filters['directorate'] ?? null;

        $integrations = Integration::query()
            ->when($status && $status !== 'all', fn (Builder $q) => $q->where('status', $status))
            ->when($category, fn (Builder $q) => $q->whereHas(
                'participants',
                fn (Builder $p) => $p->where('solutions.category', $category)
            ))
            ->when($directorate, fn (Builder $q) => $q->whereHas(
                'participants',
                fn (Builder $p) => $p->where('solutions.directorate', $directorate)
            ))
            ->with($this->graphEagerLoad())
            ->get();

        return $this->build($integrations);
    }

    /** Eager loads que evitam N+1 ao montar nós/arestas. */
    private function graphEagerLoad(): array
    {
        return [
            'participants' => fn ($q) => $q->select(
                'solutions.id',
                'solutions.name',
                'solutions.slug',
                'solutions.category',
                'solutions.logo_path',
                'solutions.status',
                'solutions.criticality',
                'solutions.environment',
                'solutions.cloud',
                'solutions.contract_status',
                'solutions.support_type',
                'solutions.directorate',
                'solutions.map_position',
            ),
        ];
    }

    /**
     * Monta o contrato neutro a partir de uma coleção de integrações.
     *
     * @param  Collection<int, Integration>  $integrations
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    private function build(Collection $integrations): array
    {
        $nodes = [];
        $edges = [];
        $seq = 0;

        foreach ($integrations as $integration) {
            foreach ($integration->participants as $participant) {
                $this->putNode($nodes, $participant);
            }

            $chainNodes = array_values($integration->chain['nodes'] ?? []);
            $chainEdges = array_values($integration->chain['edges'] ?? []);

            foreach ($chainEdges as $edge) {
                $fromSid = $chainNodes[$edge['from'] ?? -1]['solution_id'] ?? null;
                $toSid = $chainNodes[$edge['to'] ?? -1]['solution_id'] ?? null;

                // Uma ponta em nó de texto livre não gera par de soluções —
                // não há aresta pra desenhar no mapa global.
                if ($fromSid === null || $toSid === null) {
                    continue;
                }

                $bidirectional = ($edge['arrow'] ?? '->') === '<->';
                $protocol = filled($edge['protocol'] ?? null)
                    ? Protocol::tryFrom($edge['protocol'])
                    : $integration->protocol;

                $edges[] = $this->edge($integration, "sol-{$fromSid}", "sol-{$toSid}", $bidirectional, $protocol, $seq++);
            }
        }

        return [
            'nodes' => array_values($nodes),
            'edges' => $this->dedupePairs($edges),
        ];
    }

    /**
     * Agrupa arestas candidatas pelo par não-ordenado de soluções, agregando
     * direção/status/protocolo/integrações de todas as que ligam o mesmo par.
     * A primeira aresta vista para um par define a orientação canônica
     * (`source`/`target`) do grupo — toda aresta seguinte só marca se o fluxo
     * observado é no mesmo sentido (`aToB`) ou no oposto (`bToA`); uma aresta
     * já `<->` marca os dois.
     *
     * @param  array<int, array<string, mixed>>  $edges
     * @return array<int, array<string, mixed>>
     */
    private function dedupePairs(array $edges): array
    {
        $groups = [];
        $order = [];

        foreach ($edges as $edge) {
            $key = $this->pairKey($edge['source'], $edge['target']);

            if (! isset($groups[$key])) {
                $order[] = $key;
                $groups[$key] = [
                    'source'       => $edge['source'],
                    'target'       => $edge['target'],
                    'aToB'         => false,
                    'bToA'         => false,
                    'statuses'     => [],
                    'protocols'    => [],
                    'integrations' => [],
                ];
            }

            $group = &$groups[$key];
            $forward = $edge['source'] === $group['source'];

            if ($edge['direction'] === Direction::Bidirectional->value) {
                $group['aToB'] = true;
                $group['bToA'] = true;
            } elseif ($forward) {
                $group['aToB'] = true;
            } else {
                $group['bToA'] = true;
            }

            $group['statuses'][] = $edge['status'];
            if (filled($edge['label'])) {
                $group['protocols'][$edge['label']] = true;
            }
            $group['integrations'][$edge['slug']] = ['slug' => $edge['slug'], 'name' => $edge['integration_name']];
            unset($group);
        }

        return array_map(function (string $key) use ($groups) {
            $group = $groups[$key];

            return [
                'id'           => 'pair-' . $key,
                'source'       => $group['source'],
                'target'       => $group['target'],
                'label'        => implode(' · ', array_keys($group['protocols'])),
                'status'       => $this->healthiestStatus($group['statuses']),
                'direction'    => ($group['aToB'] && $group['bToA'] ? Direction::Bidirectional : Direction::Unidirectional)->value,
                'integrations' => array_values($group['integrations']),
            ];
        }, $order);
    }

    /** Chave estável e não-ordenada pra um par `sol-{id}` — mesma independente de quem é source/target. */
    private function pairKey(string $a, string $b): string
    {
        $pair = [$a, $b];
        sort($pair);

        return implode('|', $pair);
    }

    /** @param  array<int, string>  $statuses */
    private function healthiestStatus(array $statuses): string
    {
        foreach (IntegrationStatus::cases() as $status) {
            if (in_array($status->value, $statuses, true)) {
                return $status->value;
            }
        }

        return $statuses[0];
    }

    /**
     * Além do essencial pro desenho (nome/logo), carrega os mesmos 8
     * atributos exibidos em `Solutions\DetailHeader` — o popover de
     * atributos do mapa (`ecosystem-map.js`) os mostra sem precisar de um
     * round-trip AJAX por clique. `url` evita reconstruir a rota no cliente,
     * assim como `positionUrl` (endpoint de auto-save do drag-and-drop de
     * hub). `mapPosition` é a posição arrastada e salva por último (null
     * até o primeiro arraste) — `ecosystem-map.js::layout()` a usa no lugar
     * do grid empacotado pra esse hub.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     */
    private function putNode(array &$nodes, Solution $solution): void
    {
        $id = "sol-{$solution->id}";

        if (isset($nodes[$id])) {
            return;
        }

        $nodes[$id] = [
            'id'               => $id,
            'label'            => $solution->name,
            'slug'             => $solution->slug,
            'category'         => $solution->category,
            'logo'             => $solution->logo_path ? Storage::disk('public')->url($solution->logo_path) : null,
            'url'              => route('solutions.show', $solution),
            'categoryLabel'    => $solution->category_label,
            'statusLabel'      => $solution->status_label,
            'criticalityLabel' => $solution->criticality_label,
            'environmentLabel' => $solution->environment_label,
            'cloudLabel'       => $solution->cloud_label,
            'contractLabel'    => $solution->contract_status_label,
            'supportLabel'     => $solution->support_type_label,
            'directorate'      => $solution->directorate,
            'mapPosition'      => $solution->map_position,
            'positionUrl'      => route('solutions.map.position.update', $solution),
        ];
    }

    /**
     * Aresta candidata (por segmento) — insumo de `dedupePairs()`, nunca devolvida diretamente.
     *
     * @return array<string, mixed>
     */
    private function edge(Integration $integration, string $source, string $target, bool $bidirectional, ?Protocol $protocol, int $seq): array
    {
        return [
            'id'               => "int-{$integration->id}-{$seq}",
            'source'           => $source,
            'target'           => $target,
            'label'            => $protocol?->label() ?? '',
            'status'           => $integration->status->value,
            'direction'        => ($bidirectional ? Direction::Bidirectional : Direction::Unidirectional)->value,
            'slug'             => $integration->slug,
            'integration_name' => $integration->name,
        ];
    }
}
