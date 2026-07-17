<?php

namespace Database\Seeders;

use App\Models\Integration;
use App\Models\Solution;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Known portfolio of integrations (section 14.2). The Excel file doesn't
 * describe integrations; this seeder creates them by referencing solutions
 * by slug, creating `planned` solutions for participants missing from the
 * inventory. Idempotent.
 */
class IntegrationSeeder extends Seeder
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
     * Portfolio definition. participants: [slug, position] — the order in
     * the flow is enough on its own, there's no longer a labeled role on the
     * pivot. Digibee (iPaaS) is in all of them — all traffic passes through
     * it, but it's a regular chain participant.
     */
    private function portfolio(): array
    {
        return [
            [
                'slug'         => 'cws-sap-s4hana', 'name' => 'CWS <-> SAP S/4HANA',
                'source'       => 'cws-digital-marketing', 'target' => 'sap-s-4hana',
                'direction'    => 'bidirectional', 'protocol' => 'rest', 'sync_mode' => 'synchronous',
                'status'       => 'active', 'criticality' => 'high',
                'participants' => [
                    ['cws-digital-marketing', 0],
                    [self::DIGIBEE, 1],
                    ['sap-s-4hana', 2],
                ],
            ],
            [
                'slug'         => 'unica-viasoft-construshow', 'name' => 'UNICA -> Viasoft Construshow',
                'source'       => 'unica', 'target' => 'viasoft-construshow',
                'direction'    => 'unidirectional', 'protocol' => 'rest', 'sync_mode' => 'synchronous',
                'status'       => 'active', 'criticality' => 'medium',
                'participants' => [
                    ['unica', 0],
                    [self::DIGIBEE, 1],
                    ['viasoft-construshow', 2],
                ],
            ],
            [
                'slug'         => 'gupy-senior-hcm', 'name' => 'Gupy <-> Senior HCM',
                'source'       => 'gupy', 'target' => 'senior-hcm',
                'direction'    => 'bidirectional', 'protocol' => 'soap', 'sync_mode' => 'asynchronous',
                'status'       => 'in_development', 'criticality' => 'medium',
                'participants' => [
                    ['gupy', 0],
                    [self::DIGIBEE, 1],
                    ['senior-hcm', 2],
                ],
            ],
            [
                'slug'         => 'sap-allstrategy', 'name' => 'SAP -> AllStrategy',
                'source'       => 'sap-s-4hana', 'target' => 'allstrategy',
                'direction'    => 'unidirectional', 'protocol' => 'rest', 'sync_mode' => 'batch',
                'status'       => 'active', 'criticality' => 'high',
                'participants' => [
                    ['sap-s-4hana', 0],
                    [self::DIGIBEE, 1],
                    ['allstrategy', 2],
                ],
            ],
            [
                'slug'         => 'freshdesk-comprovei', 'name' => 'FreshDesk -> Comprovei',
                'source'       => 'freshdesk', 'target' => 'comprovei-nstech',
                'direction'    => 'unidirectional', 'protocol' => 'rest', 'sync_mode' => 'synchronous',
                'status'       => 'active', 'criticality' => 'medium',
                'participants' => [
                    ['freshdesk', 0],
                    [self::DIGIBEE, 1],
                    ['comprovei-nstech', 2],
                ],
            ],
            [
                'slug'         => 'freshdesk-indecx', 'name' => 'FreshDesk -> Indecx',
                'source'       => 'freshdesk', 'target' => 'indecx',
                'direction'    => 'unidirectional', 'protocol' => 'rest', 'sync_mode' => 'synchronous',
                'status'       => 'active', 'criticality' => 'low',
                'participants' => [
                    ['freshdesk', 0],
                    [self::DIGIBEE, 1],
                    ['indecx', 2],
                ],
            ],
            [
                'slug'         => 'bigquery-pricefy', 'name' => 'BigQuery -> Pricefy',
                'source'       => 'google-bigquery', 'target' => 'pricefy',
                'direction'    => 'unidirectional', 'protocol' => 'rest', 'sync_mode' => 'batch',
                'status'       => 'in_development', 'criticality' => 'medium',
                'participants' => [
                    ['google-bigquery', 0],
                    [self::DIGIBEE, 1],
                    ['pricefy', 2],
                ],
            ],
            [
                'slug'         => 'split-project-promob-sapcpi', 'name' => 'Split Project (Promob ERP <-> SAP CPI)',
                'source'       => 'promob-erp', 'target' => 'sap-cpi-integracao',
                'direction'    => 'bidirectional', 'protocol' => 'rest', 'sync_mode' => 'synchronous',
                'status'       => 'in_development', 'criticality' => 'medium',
                'participants' => [
                    ['promob-erp', 0],
                    [self::DIGIBEE, 1],
                    ['sap-cpi-integracao', 2],
                ],
            ],
            [
                // VPR: full chain SAP -> Digibee -> Mantran -> Repom -> BigQuery -> SAP
                'slug'         => 'toll-voucher-vpr', 'name' => 'Toll Voucher (VPR)',
                'source'       => 'sap-s-4hana', 'target' => 'sap-s-4hana',
                'direction'    => 'bidirectional', 'protocol' => 'rest', 'sync_mode' => 'batch',
                'status'       => 'planned', 'criticality' => 'high',
                'participants' => [
                    ['sap-s-4hana', 0],
                    [self::DIGIBEE, 1],
                    ['mantran-tms', 2],
                    ['repom-edenred', 3],
                    ['google-bigquery', 4],
                    ['sap-s-4hana', 5],
                ],
            ],
            [
                'slug'         => 'ad-account-unlock', 'name' => 'AD Account Unlock',
                'source'       => 'accessone-iam', 'target' => 'active-directory',
                'direction'    => 'unidirectional', 'protocol' => 'rest', 'sync_mode' => 'synchronous',
                'status'       => 'active', 'criticality' => 'medium',
                'participants' => [
                    ['accessone-iam', 0],
                    [self::DIGIBEE, 1],
                    ['active-directory', 2],
                ],
            ],
        ];
    }

    public function run(): void
    {
        $this->ensurePlannedSolutions();

        foreach ($this->portfolio() as $def) {
            $integration = Integration::updateOrCreate(
                ['slug' => $def['slug']],
                [
                    'name'               => $def['name'],
                    'source_solution_id' => $this->id($def['source']),
                    'target_solution_id' => $this->id($def['target']),
                    'direction'          => $def['direction'],
                    'protocol'           => $def['protocol'],
                    'sync_mode'          => $def['sync_mode'],
                    'status'             => $def['status'],
                    'criticality'        => $def['criticality'],
                ],
            );

            foreach ($def['participants'] as [$slug, $position]) {
                DB::table('integration_solution')->updateOrInsert(
                    ['integration_id' => $integration->id, 'solution_id' => $this->id($slug), 'position' => $position],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
        }
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
