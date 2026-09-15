<?php

namespace Database\Seeders;

use App\Enums\Direction;
use App\Models\Diagram;
use App\Models\Solution;
use Illuminate\Database\Seeder;

/**
 * Known portfolio of diagrams (section 14.2). The Excel file doesn't
 * describe diagrams; this seeder creates them by referencing solutions
 * by slug, creating `planned` solutions for participants missing from the
 * inventory. Idempotent.
 *
 * It writes the `chain` and NOTHING else about the topology. Every derived
 * column — the `participants` pivot with its `position`, `source`/
 * `target_solution_id`, `direction` and the summary `protocol` — is left to
 * `SyncDiagramFromChain` through `afterChainMutation()`, exactly like the
 * canvas does. It used to assert all of them by hand and never write a chain
 * at all, which was the same statement made twice and then, once the chain
 * became the source of truth, made only in the half nothing reads: the
 * ecosystem map builds its edges from `chain.edges`, so a seeded database
 * drew every solution and not one single link between them.
 */
class DiagramSeeder extends Seeder
{
    private const DIGIBEE = 'digibee-ipaas';

    /** @var array<string, int> cache slug => solution id */
    private array $cache = [];

    /** Participants missing from the inventory: slug => [name, description]. */
    private const PLANNED = [
        'gupy'                => ['Gupy', 'ATS de recrutamento e seleção (participante de integração).'],
        'active-directory'    => ['Active Directory / Entra ID', 'Diretório de identidade corporativa.'],
        'repom-edenred'       => ['Repom / Edenred', 'Gestão de vale-pedágio (VPR).'],
        'freshdesk'           => ['FreshDesk', 'Help desk e atendimento.'],
        'indecx'              => ['Indecx', 'Pesquisa de satisfação (NPS).'],
        'viasoft-construshow' => ['Viasoft Construshow', 'ERP de distribuidor do varejo de construção.'],
        'unica'               => ['UNICA', 'Sistema de distribuidor participante de integração.'],
    ];

    /**
     * Portfolio definition. `flow` is the chain itself: an ordered list of
     * solution slugs, linked consecutively — the same shape the canvas
     * produces when somebody draws one block after another. A solution may
     * appear twice (the VPR flow returns to SAP), which is why the chain
     * holds NODES rather than a set of participants: two nodes, one solution,
     * and the pivot gets a single row at its first position.
     *
     * `arrow` and `protocol` are applied to every link of the flow. Nothing
     * here states a direction or an endpoint: `SyncDiagramFromChain` reads
     * both out of the drawing.
     *
     * @return array<int, array<string, mixed>>
     */
    private function portfolio(): array
    {
        return [
            [
                'slug'      => 'cws-sap-s4hana', 'name' => 'CWS <-> SAP S/4HANA',
                'sync_mode' => 'synchronous', 'status' => 'active', 'criticality' => 'high',
                'arrow'     => '<->', 'protocol' => 'rest',
                'flow'      => ['cws-digital-marketing', self::DIGIBEE, 'sap-s-4hana'],
            ],
            [
                'slug'      => 'unica-viasoft-construshow', 'name' => 'UNICA -> Viasoft Construshow',
                'sync_mode' => 'synchronous', 'status' => 'active', 'criticality' => 'medium',
                'arrow'     => '->', 'protocol' => 'rest',
                'flow'      => ['unica', self::DIGIBEE, 'viasoft-construshow'],
            ],
            [
                'slug'      => 'gupy-senior-hcm', 'name' => 'Gupy <-> Senior HCM',
                'sync_mode' => 'asynchronous', 'status' => 'in_development', 'criticality' => 'medium',
                'arrow'     => '<->', 'protocol' => 'soap',
                'flow'      => ['gupy', self::DIGIBEE, 'senior-hcm'],
            ],
            [
                'slug'      => 'sap-allstrategy', 'name' => 'SAP -> AllStrategy',
                'sync_mode' => 'batch', 'status' => 'active', 'criticality' => 'high',
                'arrow'     => '->', 'protocol' => 'rest',
                'flow'      => ['sap-s-4hana', self::DIGIBEE, 'allstrategy'],
            ],
            [
                'slug'      => 'freshdesk-comprovei', 'name' => 'FreshDesk -> Comprovei',
                'sync_mode' => 'synchronous', 'status' => 'active', 'criticality' => 'medium',
                'arrow'     => '->', 'protocol' => 'rest',
                'flow'      => ['freshdesk', self::DIGIBEE, 'comprovei-nstech'],
            ],
            [
                'slug'      => 'freshdesk-indecx', 'name' => 'FreshDesk -> Indecx',
                'sync_mode' => 'synchronous', 'status' => 'active', 'criticality' => 'low',
                'arrow'     => '->', 'protocol' => 'rest',
                'flow'      => ['freshdesk', self::DIGIBEE, 'indecx'],
            ],
            [
                'slug'      => 'bigquery-pricefy', 'name' => 'BigQuery -> Pricefy',
                'sync_mode' => 'batch', 'status' => 'in_development', 'criticality' => 'medium',
                'arrow'     => '->', 'protocol' => 'rest',
                'flow'      => ['google-bigquery', self::DIGIBEE, 'pricefy'],
            ],
            [
                'slug'      => 'split-project-promob-sapcpi', 'name' => 'Split Project (Promob ERP <-> SAP CPI)',
                'sync_mode' => 'synchronous', 'status' => 'in_development', 'criticality' => 'medium',
                'arrow'     => '<->', 'protocol' => 'rest',
                'flow'      => ['promob-erp', self::DIGIBEE, 'sap-cpi-integracao'],
            ],
            [
                // VPR: full chain SAP -> Digibee -> Mantran -> Repom -> BigQuery -> SAP
                'slug'      => 'toll-voucher-vpr', 'name' => 'Toll Voucher (VPR)',
                'sync_mode' => 'batch', 'status' => 'planned', 'criticality' => 'high',
                'arrow'     => '->', 'protocol' => 'rest',
                'flow'      => ['sap-s-4hana', self::DIGIBEE, 'mantran-tms', 'repom-edenred', 'google-bigquery', 'sap-s-4hana'],
            ],
            [
                'slug'      => 'ad-account-unlock', 'name' => 'AD Account Unlock',
                'sync_mode' => 'synchronous', 'status' => 'active', 'criticality' => 'medium',
                'arrow'     => '->', 'protocol' => 'rest',
                'flow'      => ['accessone-iam', self::DIGIBEE, 'active-directory'],
            ],
        ];
    }

    public function run(): void
    {
        $this->ensurePlannedSolutions();

        foreach ($this->portfolio() as $def) {
            $diagram = Diagram::updateOrCreate(
                ['slug' => $def['slug']],
                [
                    'name' => $def['name'],
                    // Placeholder: `diagrams.direction` is NOT NULL, and it is
                    // re-derived from the chain by the line below — the same
                    // shape `DiagramController::store()` uses on creation.
                    'direction'   => Direction::Unidirectional->value,
                    'sync_mode'   => $def['sync_mode'],
                    'status'      => $def['status'],
                    'criticality' => $def['criticality'],
                    'chain'       => $this->chain($def),
                ],
            );

            $diagram->afterChainMutation();
        }
    }

    /**
     * Builds the free-graph chain for one portfolio entry: one `system` node
     * per step of the flow, linked consecutively.
     *
     * @param  array<string, mixed>  $def
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    private function chain(array $def): array
    {
        $nodes = array_map(fn (string $slug) => [
            'solution_id' => $this->id($slug),
            'label'       => null,
            'kind'        => 'system',
        ], $def['flow']);

        $edges = [];
        for ($i = 0; $i < count($nodes) - 1; $i++) {
            $edges[] = [
                'from'     => $i,
                'to'       => $i + 1,
                'arrow'    => $def['arrow'],
                'protocol' => $def['protocol'],
            ];
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    private function ensurePlannedSolutions(): void
    {
        foreach (self::PLANNED as $slug => [$name, $description]) {
            Solution::updateOrCreate(
                ['slug' => $slug],
                [
                    'name'            => $name,
                    'description'     => $description,
                    'category'        => 'other',
                    'support_type'    => 'third_party',
                    'contract_status' => 'unknown',
                    'status'          => 'planned',
                ],
            );
        }
    }

    private function id(string $slug): int
    {
        return $this->cache[$slug] ??= Solution::where('slug', $slug)->value('id');
    }
}
